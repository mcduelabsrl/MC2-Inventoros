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
    /** Scopes the Dev Dashboard app must be granted. */
    public const REQUIRED_SCOPES = ['read_orders', 'read_products', 'read_inventory', 'write_inventory', 'read_locations'];

    /** Webhook topics the plugin subscribes to. */
    public const WEBHOOK_TOPICS = ['ORDERS_CREATE', 'ORDERS_CANCELLED'];

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
        $data = $this->graphql('query ShopInfo { shop { name myshopifyDomain } locations(first: 100) { nodes { id name isActive } } }');

        $locations = [];
        foreach ($data['locations']['nodes'] ?? [] as $node) {
            if (($node['isActive'] ?? true) && isset($node['id'], $node['name'])) {
                $locations[] = ['id' => (string) $node['id'], 'name' => (string) $node['name']];
            }
        }

        return ['name' => (string) ($data['shop']['name'] ?? $this->connection->shop_domain), 'locations' => $locations];
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
     * Set the "available" quantity of inventory items at the connection's location.
     *
     * Inventoros is the source of truth, so the compare-and-swap check is
     * skipped (changeFromQuantity null). Shopify applies a call all or
     * nothing; the user errors it returns name the offending entries by index.
     *
     * @param  array<int, array{inventory_item_id: string, quantity: int}>  $quantities  At most 250.
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
                'locationId' => $this->connection->location_id,
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
     * Start stocking an inventory item at the connection's location.
     *
     * @return array<int, string> Error messages; empty on success.
     */
    public function activateAtLocation(string $inventoryItemId): array
    {
        $data = $this->graphql(
            'mutation Activate($inventoryItemId: ID!, $locationId: ID!) { inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId) @idempotent(key: "'.Str::uuid().'") { inventoryLevel { id } userErrors { field message } } }',
            ['inventoryItemId' => $inventoryItemId, 'locationId' => $this->connection->location_id],
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

        if ($response->status() === 401) {
            // A token revoked or rotated early: get a new one, once.
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

            throw new ShopifyException('Shopify API error: '.implode('; ', $messages), transient: $throttled);
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
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
