<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;

/**
 * The Shopify Admin GraphQL API, for one connection.
 *
 * Authenticates with the client credentials grant: the app is created in the
 * Shopify Dev Dashboard and installed on the merchant's own store, and its
 * client ID and secret are exchanged for an access token that lasts 24 hours.
 * The token is cached (encrypted) on the connection and renewed shortly
 * before it expires, or when Shopify answers 401.
 */
class ShopifyClient
{
    /** Scopes the Dev Dashboard app must be granted: orders, stock and locations. */
    public const REQUIRED_SCOPES = [
        'read_orders',
        'read_products',
        'read_inventory',
        'write_inventory',
        'read_locations',
    ];

    /** Scopes that unlock one feature each; without them the rest works. */
    public const OPTIONAL_SCOPES = [
        // Publish Inventoros products to Shopify.
        'write_products',
        // With several locations: ship each order from its assigned location's warehouse.
        'read_assigned_fulfillment_orders',
        'read_merchant_managed_fulfillment_orders',
    ];

    /** Webhook topics the plugin subscribes to. orders/updated also covers edits, fulfilments and refunds. */
    public const WEBHOOK_TOPICS = ['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED'];

    /** Fields of an order, for reconciliation and recovery (see OrderPayload). */
    public const ORDER_FIELDS = 'id legacyResourceId name email note createdAt processedAt updatedAt cancelledAt currencyCode displayFinancialStatus '
        .'shippingAddress { firstName lastName company address1 address2 city zip provinceCode country phone } '
        .'billingAddress { firstName lastName company address1 address2 city zip provinceCode country phone } '
        .'lineItems(first: 250) { nodes { id sku name quantity currentQuantity originalUnitPriceSet { shopMoney { amount } } '
        .'discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { priceSet { shopMoney { amount } } } } } '
        .'shippingLines(first: 20) { nodes { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } } } '
        .'refunds { id createdAt refundLineItems(first: 250) { nodes { quantity restockType lineItem { id sku } } } } '
        .'fulfillments { id status displayStatus createdAt location { id } trackingInfo { company number url } '
        .'fulfillmentLineItems(first: 250) { nodes { quantity lineItem { id sku } } } }';

    /** inventorySetQuantities accepts at most this many quantities per call. */
    public const MAX_QUANTITIES_PER_CALL = 250;

    private const TIMEOUT_SECONDS = 20;

    public function __construct(private readonly ShopifyConnection $connection) {}

    /**
     * The shop's name and its active locations; also proves the credentials work.
     *
     * @return array{name: string, locations: array<int, array{id: string, name: string}>}
     */
    public function shopInfo(): array
    {
        // Read what the store actually granted first (this needs no scope),
        // so a missing scope is named instead of failing on some field.
        $granted = $this->grantedScopes();
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, self::implied($granted)));
        if ($missing !== []) {
            throw new ShopifyException(
                'The Shopify app is missing these access scopes: '.implode(', ', $missing).'. '
                .'Add them to the app\'s version in the Shopify Dev Dashboard, release the version, approve the new scopes in the store if Shopify asks, then save again.'
            );
        }

        $data = $this->graphql('query ShopInfo { shop { name myshopifyDomain } locations(first: 100) { nodes { id name isActive } } }');

        $locations = [];
        foreach ($data['locations']['nodes'] ?? [] as $node) {
            if (($node['isActive'] ?? true) && isset($node['id'], $node['name'])) {
                $locations[] = ['id' => (string) $node['id'], 'name' => (string) $node['name']];
            }
        }

        return [
            'name' => (string) ($data['shop']['name'] ?? $this->connection->shop_domain),
            'locations' => $locations,
            'missing_optional_scopes' => array_values(array_diff(self::OPTIONAL_SCOPES, self::implied($granted))),
        ];
    }

    /**
     * The access scopes the store granted the app.
     *
     * @return array<int, string>
     */
    public function grantedScopes(): array
    {
        $data = $this->graphql('query GrantedScopes { currentAppInstallation { accessScopes { handle } } }');

        return array_values(array_filter(array_map(
            fn ($scope) => is_array($scope) ? (string) ($scope['handle'] ?? '') : '',
            (array) ($data['currentAppInstallation']['accessScopes'] ?? []),
        )));
    }

    /**
     * Granted scopes plus the ones they imply: write_x includes read_x.
     *
     * @param  array<int, string>  $granted
     * @return array<int, string>
     */
    private static function implied(array $granted): array
    {
        foreach ($granted as $scope) {
            if (str_starts_with($scope, 'write_')) {
                $granted[] = 'read_'.substr($scope, 6);
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * Shopify variants for the given SKUs, keyed by SKU.
     *
     * @param  array<int, string>  $skus
     * @return array<string, array{variant_id: string, inventory_item_id: string, tracked: bool}>
     */
    public function variantsBySku(array $skus): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($skus)), 25) as $chunk) {
            $query = implode(' OR ', array_map(fn (string $sku) => 'sku:'.self::searchValue($sku), $chunk));
            $data = $this->graphql(
                'query VariantsBySku($query: String!) { productVariants(first: 250, query: $query) { nodes { id sku inventoryItem { id tracked } } } }',
                ['query' => $query],
            );

            foreach ($data['productVariants']['nodes'] ?? [] as $node) {
                $variant = self::variantFromNode($node);
                // Shopify's search is not exact (it tokenizes), so keep exact matches only.
                if ($variant !== null && in_array($variant['sku'], $chunk, true)) {
                    $found[$variant['sku']] ??= Arr::except($variant, 'sku');
                }
            }
        }

        return $found;
    }

    /**
     * Every variant in the store that has a SKU, one page at a time.
     *
     * @return \Generator<int, array{sku: string, variant_id: string, inventory_item_id: string, tracked: bool}>
     */
    public function allVariants(): \Generator
    {
        $cursor = null;

        do {
            $data = $this->graphql(
                'query AllVariants($cursor: String) { productVariants(first: 250, after: $cursor) { nodes { id sku inventoryItem { id tracked } } pageInfo { hasNextPage endCursor } } }',
                ['cursor' => $cursor],
            );

            foreach ($data['productVariants']['nodes'] ?? [] as $node) {
                $variant = self::variantFromNode($node);
                if ($variant !== null) {
                    yield $variant;
                }
            }

            $page = $data['productVariants']['pageInfo'] ?? [];
            $cursor = ($page['hasNextPage'] ?? false) ? ($page['endCursor'] ?? null) : null;
        } while ($cursor !== null);
    }

    /**
     * Set the "available" quantity of inventory items at Shopify locations.
     *
     * Inventoros is the source of truth, so the compare-and-swap check is
     * skipped (changeFromQuantity null). Shopify applies a call all or
     * nothing; the user errors it returns name the offending entries by index.
     *
     * @param  array<int, array{inventory_item_id: string, location_id: string, quantity: int}>  $quantities  At most 250.
     * @return array<int, array{index: int|null, code: string|null, message: string}> User errors; empty on success.
     */
    public function setAvailable(array $quantities): array
    {
        $input = [
            'name' => 'available',
            'reason' => 'correction',
            'referenceDocumentUri' => 'inventoros://shopify-plugin/stock-sync',
            'quantities' => array_map(fn (array $q) => [
                'inventoryItemId' => $q['inventory_item_id'],
                'locationId' => $q['location_id'],
                'quantity' => $q['quantity'],
                'changeFromQuantity' => null,
            ], array_values($quantities)),
        ];

        $data = $this->graphql(
            'mutation SetQuantities($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) @idempotent(key: "'.Str::uuid().'") { inventoryAdjustmentGroup { id } userErrors { field message code } } }',
            ['input' => $input],
        );

        return array_map(function (array $error) {
            $field = $error['field'] ?? [];
            // ["input", "quantities", "3", "inventoryItemId"] -> 3
            $index = is_array($field) && ($field[1] ?? null) === 'quantities' && is_numeric($field[2] ?? null) ? (int) $field[2] : null;

            return ['index' => $index, 'code' => $error['code'] ?? null, 'message' => (string) ($error['message'] ?? 'Unknown error')];
        }, $data['inventorySetQuantities']['userErrors'] ?? []);
    }

    /**
     * Start stocking an inventory item at a location.
     *
     * @return array<int, string> Error messages; empty on success.
     */
    public function activateAtLocation(string $inventoryItemId, string $locationId): array
    {
        $data = $this->graphql(
            'mutation Activate($inventoryItemId: ID!, $locationId: ID!) { inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId) @idempotent(key: "'.Str::uuid().'") { inventoryLevel { id } userErrors { field message } } }',
            ['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId],
        );

        return array_map(fn (array $e) => (string) ($e['message'] ?? 'Unknown error'), $data['inventoryActivate']['userErrors'] ?? []);
    }

    /**
     * Subscribe the store's order webhooks to $uri, replacing subscriptions of
     * the same topics that point at an older Inventoros URL of this plugin.
     *
     * @return array{created: array<int, string>, existing: array<int, string>}
     */
    public function registerWebhooks(string $uri): array
    {
        $data = $this->graphql('query Webhooks { webhookSubscriptions(first: 100) { nodes { id topic uri } } }');
        $result = ['created' => [], 'existing' => []];
        $existing = [];

        foreach ($data['webhookSubscriptions']['nodes'] ?? [] as $node) {
            if (! in_array($node['topic'] ?? null, self::WEBHOOK_TOPICS, true)) {
                continue;
            }

            if (($node['uri'] ?? null) === $uri) {
                $existing[$node['topic']] = true;
            } elseif (str_contains((string) ($node['uri'] ?? ''), '/webhooks/plugins/shopify/')) {
                $this->deleteWebhook((string) $node['id']);
            }
        }

        foreach (self::WEBHOOK_TOPICS as $topic) {
            if (isset($existing[$topic])) {
                $result['existing'][] = $topic;

                continue;
            }

            $created = $this->graphql(
                'mutation CreateWebhook($topic: WebhookSubscriptionTopic!, $uri: String!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: { uri: $uri, format: JSON }) { webhookSubscription { id } userErrors { field message } } }',
                ['topic' => $topic, 'uri' => $uri],
            );

            $errors = $created['webhookSubscriptionCreate']['userErrors'] ?? [];
            if ($errors !== []) {
                throw new ShopifyException("Shopify refused the {$topic} webhook: ".implode('; ', array_column($errors, 'message')));
            }

            $result['created'][] = $topic;
        }

        return $result;
    }

    /**
     * Orders updated since a moment, oldest first, one page at a time.
     *
     * @return \Generator<int, array<string, mixed>> GraphQL order nodes
     */
    public function ordersUpdatedSince(\DateTimeInterface $since, int $maxPages = 20): \Generator
    {
        $cursor = null;
        $pages = 0;
        $query = "updated_at:>='".gmdate('Y-m-d\\TH:i:s\\Z', $since->getTimestamp())."'";

        do {
            $data = $this->graphql(
                'query Orders($query: String!, $cursor: String) { orders(first: 25, after: $cursor, query: $query, sortKey: UPDATED_AT) { nodes { '.self::ORDER_FIELDS.' } pageInfo { hasNextPage endCursor } } }',
                ['query' => $query, 'cursor' => $cursor],
            );

            foreach ($data['orders']['nodes'] ?? [] as $node) {
                if (is_array($node)) {
                    yield $node;
                }
            }

            $page = $data['orders']['pageInfo'] ?? [];
            $cursor = ($page['hasNextPage'] ?? false) ? ($page['endCursor'] ?? null) : null;
        } while ($cursor !== null && ++$pages < $maxPages);
    }

    /**
     * One order by its numeric id, or null when Shopify has no such order.
     *
     * @return array<string, mixed>|null GraphQL order node
     */
    public function order(string $orderId): ?array
    {
        $data = $this->graphql(
            'query Order($id: ID!) { order(id: $id) { '.self::ORDER_FIELDS.' } }',
            ['id' => self::gid('Order', $orderId)],
        );

        return is_array($data['order'] ?? null) ? $data['order'] : null;
    }

    /**
     * The locations an order's fulfilment orders are assigned to (open ones first).
     *
     * @return array<int, string> location gids
     */
    public function assignedLocations(string $orderId): array
    {
        $data = $this->graphql(
            'query AssignedLocations($id: ID!) { order(id: $id) { fulfillmentOrders(first: 20) { nodes { status assignedLocation { location { id } } } } } }',
            ['id' => self::gid('Order', $orderId)],
        );

        $locations = [];
        foreach ($data['order']['fulfillmentOrders']['nodes'] ?? [] as $node) {
            $id = $node['assignedLocation']['location']['id'] ?? null;
            if (is_string($id) && ($node['status'] ?? '') !== 'CANCELLED') {
                $locations[] = $id;
            }
        }

        return array_values(array_unique($locations));
    }

    /**
     * Every product with its options, variants and per-location stock.
     *
     * @return \Generator<int, array<string, mixed>> GraphQL product nodes
     */
    public function products(): \Generator
    {
        $cursor = null;

        do {
            $data = $this->graphql(
                'query Products($cursor: String) { products(first: 25, after: $cursor) { nodes { id legacyResourceId title handle descriptionHtml status vendor productType options { name values } '
                .'variants(first: 100) { nodes { id sku barcode price selectedOptions { name value } inventoryItem { id tracked unitCost { amount } '
                .'inventoryLevels(first: 20) { nodes { location { id } quantities(names: ["available"]) { name quantity } } } } } } } pageInfo { hasNextPage endCursor } } }',
                ['cursor' => $cursor],
            );

            foreach ($data['products']['nodes'] ?? [] as $node) {
                if (is_array($node)) {
                    yield $node;
                }
            }

            $page = $data['products']['pageInfo'] ?? [];
            $cursor = ($page['hasNextPage'] ?? false) ? ($page['endCursor'] ?? null) : null;
        } while ($cursor !== null);
    }

    /**
     * Create a product with its options and variants (productSet).
     *
     * @param  array<string, mixed>  $input  ProductSetInput
     * @return array{product: array<string, mixed>|null, errors: array<int, string>}
     */
    public function createProduct(array $input): array
    {
        $data = $this->graphql(
            'mutation PublishProduct($input: ProductSetInput!) { productSet(input: $input, synchronous: true) { product { id variants(first: 250) { nodes { id sku inventoryItem { id tracked } } } } userErrors { field message code } } }',
            ['input' => $input],
        );

        return [
            'product' => is_array($data['productSet']['product'] ?? null) ? $data['productSet']['product'] : null,
            'errors' => array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), $data['productSet']['userErrors'] ?? []),
        ];
    }

    /**
     * Webhook topics that have no subscription to $uri.
     *
     * @return array<int, string>
     */
    public function missingWebhooks(string $uri): array
    {
        $data = $this->graphql('query Webhooks { webhookSubscriptions(first: 100) { nodes { id topic uri } } }');
        $present = [];

        foreach ($data['webhookSubscriptions']['nodes'] ?? [] as $node) {
            if (($node['uri'] ?? null) === $uri) {
                $present[] = $node['topic'] ?? null;
            }
        }

        return array_values(array_diff(self::WEBHOOK_TOPICS, $present));
    }

    /**
     * "gid://shopify/Order/123" for ("Order", "123"); a gid is returned as is.
     */
    public static function gid(string $type, string|int $id): string
    {
        $id = (string) $id;

        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }

    /**
     * The numeric id at the end of a gid ("gid://shopify/LineItem/7" -> "7").
     */
    public static function legacyId(string|int|null $id): string
    {
        $id = (string) $id;
        $id = explode('?', $id)[0];

        return str_contains($id, '/') ? substr($id, strrpos($id, '/') + 1) : $id;
    }

    private function deleteWebhook(string $id): void
    {
        $this->graphql(
            'mutation DeleteWebhook($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }',
            ['id' => $id],
        );
    }

    /**
     * Run one GraphQL operation and return its `data`.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShopifyException
     */
    public function graphql(string $query, array $variables = []): array
    {
        $response = $this->post($query, $variables);

        // A token revoked or rotated early (401), or one issued before the
        // app was granted more scopes (ACCESS_DENIED: tokens live 24 hours
        // and carry the scopes of the moment they were issued): get a new
        // one, once.
        if ($response->status() === 401 || self::accessDenied($response)) {
            $this->forgetToken();
            $response = $this->post($query, $variables);
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new ShopifyException("Shopify is unavailable or rate limiting (HTTP {$response->status()}).", transient: true);
        }

        if (! $response->successful()) {
            throw new ShopifyException(self::httpError($response));
        }

        $json = $response->json();
        $errors = is_array($json) ? ($json['errors'] ?? null) : null;

        if (! empty($errors)) {
            $messages = is_array($errors) ? array_map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e, $errors) : [(string) $errors];
            $throttled = collect(is_array($errors) ? $errors : [])->contains(fn ($e) => is_array($e) && ($e['extensions']['code'] ?? null) === 'THROTTLED');

            $hint = self::accessDenied($response)
                ? ' The Shopify app lacks an access scope this needs: check the scopes of its version in the Dev Dashboard (see the plugin README), release it and approve it in the store.'
                : '';

            throw new ShopifyException('Shopify API error: '.implode('; ', $messages).$hint, transient: $throttled);
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    private static function accessDenied(Response $response): bool
    {
        $errors = $response->json('errors');
        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (is_array($error) && (($error['extensions']['code'] ?? null) === 'ACCESS_DENIED' || str_starts_with((string) ($error['message'] ?? ''), 'Access denied'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the cached access token, so the next call gets a new one with
     * the scopes the app has now.
     */
    public function refreshToken(): void
    {
        $this->forgetToken();
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function post(string $query, array $variables): Response
    {
        $body = ['query' => $query];
        if ($variables !== []) {
            $body['variables'] = $variables;
        }

        try {
            return Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken()])
                ->acceptJson()
                ->post($this->baseUrl()."/admin/api/{$this->connection->api_version}/graphql.json", $body);
        } catch (ConnectionException $e) {
            throw new ShopifyException('Could not reach Shopify: '.$e->getMessage(), transient: true, previous: $e);
        }
    }

    /**
     * A valid access token, requesting a new one when none is cached or the
     * cached one expires within five minutes.
     *
     * @throws ShopifyException
     */
    public function accessToken(): string
    {
        $connection = $this->connection;

        if (filled($connection->access_token) && $connection->token_expires_at?->isAfter(now()->addMinutes(5))) {
            return $connection->access_token;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->acceptJson()
                ->post($this->baseUrl().'/admin/oauth/access_token', [
                    'client_id' => $connection->client_id,
                    'client_secret' => $connection->client_secret,
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $e) {
            throw new ShopifyException('Could not reach Shopify: '.$e->getMessage(), transient: true, previous: $e);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new ShopifyException("Shopify is unavailable (HTTP {$response->status()}).", transient: true);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new ShopifyException(
                'Shopify refused the client ID and secret ('.self::httpError($response).'). '
                .'Check them in the Dev Dashboard, and that the app is installed on '.$connection->shop_domain.'.'
            );
        }

        $connection->forceFill([
            'access_token' => $token,
            'token_expires_at' => now()->addSeconds(max(60, (int) ($response->json('expires_in') ?? 86399))),
        ])->save();

        return $token;
    }

    private function forgetToken(): void
    {
        $this->connection->forceFill(['access_token' => null, 'token_expires_at' => null])->save();
    }

    private function baseUrl(): string
    {
        return 'https://'.$this->connection->shop_domain;
    }

    private static function httpError(Response $response): string
    {
        $message = $response->json('error_description') ?? $response->json('error') ?? $response->json('errors');

        if (is_array($message)) {
            $message = json_encode($message);
        }

        return 'HTTP '.$response->status().(is_string($message) && $message !== '' ? ': '.Str::limit($message, 300) : '');
    }

    /**
     * A SKU quoted for Shopify's search syntax.
     */
    private static function searchValue(string $sku): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $sku).'"';
    }

    /**
     * @param  mixed  $node
     * @return array{sku: string, variant_id: string, inventory_item_id: string, tracked: bool}|null
     */
    private static function variantFromNode($node): ?array
    {
        $sku = is_array($node) ? trim((string) ($node['sku'] ?? '')) : '';
        $item = is_array($node) ? ($node['inventoryItem'] ?? null) : null;

        if ($sku === '' || ! is_array($item) || empty($item['id'])) {
            return null;
        }

        return [
            'sku' => $sku,
            'variant_id' => (string) $node['id'],
            'inventory_item_id' => (string) $item['id'],
            'tracked' => (bool) ($item['tracked'] ?? true),
        ];
    }
}
