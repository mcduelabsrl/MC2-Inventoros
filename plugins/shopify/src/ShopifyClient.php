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
        // Import the catalogue: Shopify asks for it to read variant prices.
        'read_markets_home',
        // Recover orders older than 60 days.
        'read_all_orders',
        // With several locations: ship each order from its assigned location's warehouse.
        'read_assigned_fulfillment_orders',
        'read_merchant_managed_fulfillment_orders',
        // Stock per warehouse: move an order's fulfilment to the warehouse
        // its pairs were gathered in.
        'write_merchant_managed_fulfillment_orders',
        // Stock per warehouse: read and fix inventory transfers (e.g. the
        // destination of a draft transfer) and receive their shipments.
        'read_inventory_transfers',
        'write_inventory_transfers',
        'read_inventory_shipments',
        'write_inventory_shipments_received_items',
    ];

    /** Webhook topics the plugin subscribes to. orders/updated also covers edits, fulfilments and refunds. */
    public const WEBHOOK_TOPICS = ['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED', 'INVENTORY_LEVELS_UPDATE'];

    /** Every quantity name of an inventory level. */
    public const QUANTITY_NAMES = ['available', 'on_hand', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'];

    /** Fields of an order, for reconciliation and recovery (see OrderPayload). */
    public const ORDER_FIELDS = 'id legacyResourceId name email note createdAt processedAt updatedAt cancelledAt currencyCode displayFinancialStatus totalReceivedSet { shopMoney { amount } } '
        .'shippingAddress { firstName lastName company address1 address2 city zip provinceCode country phone } '
        .'billingAddress { firstName lastName company address1 address2 city zip provinceCode country phone } '
        .'lineItems(first: 50) { nodes { id sku name quantity currentQuantity variant { id } originalUnitPriceSet { shopMoney { amount } } '
        .'discountAllocations { allocatedAmountSet { shopMoney { amount } } } taxLines { priceSet { shopMoney { amount } } } } pageInfo { hasNextPage } } '
        .'shippingLines(first: 10) { nodes { originalPriceSet { shopMoney { amount } } discountedPriceSet { shopMoney { amount } } } } '
        .'refunds { id createdAt totalRefundedSet { shopMoney { amount } } refundLineItems(first: 50) { nodes { quantity restockType location { id } lineItem { id sku } } pageInfo { hasNextPage } } } '
        .'fulfillments { id status displayStatus createdAt location { id } trackingInfo { company number url } '
        .'fulfillmentLineItems(first: 50) { nodes { quantity lineItem { id sku } } pageInfo { hasNextPage } } }';

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
        $skus = array_values(array_unique($skus));
        $generated = array_values(array_filter($skus, fn (string $sku) => self::isGeneratedSku($sku)));
        $real = array_values(array_diff($skus, $generated));

        foreach (array_chunk($real, 25) as $chunk) {
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

        // SHOPIFY-<id>: the variant itself, by id.
        foreach (array_chunk($generated, 100) as $chunk) {
            $ids = array_map(fn (string $sku) => self::gid('ProductVariant', substr($sku, strlen(self::GENERATED_SKU_PREFIX))), $chunk);
            $data = $this->graphql(
                'query VariantsById($ids: [ID!]!) { nodes(ids: $ids) { ... on ProductVariant { id sku inventoryItem { id tracked } } } }',
                ['ids' => $ids],
            );

            foreach ($data['nodes'] ?? [] as $node) {
                $variant = self::variantFromNode($node);
                if ($variant !== null) {
                    $found[self::generatedSku($variant['variant_id'])] = Arr::except($variant, 'sku');
                }
            }
        }

        return $found;
    }

    /**
     * Every variant in the store, one page at a time, each under its
     * effective SKU (see effectiveSku()).
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
    public function ordersUpdatedSince(\DateTimeInterface $since, int $maxPages = 50): \Generator
    {
        $query = "updated_at:>='".gmdate('Y-m-d\\TH:i:s\\Z', $since->getTimestamp())."'";

        yield from $this->paginate(
            'query Orders($first: Int!, $query: String!, $cursor: String) { orders(first: $first, after: $cursor, query: $query, sortKey: UPDATED_AT) { nodes { '.self::ORDER_FIELDS.' } pageInfo { hasNextPage endCursor } } }',
            'orders',
            ['query' => $query],
            first: 10,
            maxPages: $maxPages,
        );
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
     * Every product with its options and variants, and each variant's
     * available stock at the given locations (inventoryLevels, as in the
     * Admin API's own shape).
     *
     * Stock is read with one aliased inventoryLevel per location, not the
     * inventoryLevels connection: nested connections multiply into the
     * query cost, and Shopify refuses a query costing over 1000 points.
     *
     * @param  array<int, string>  $locationIds  location gids
     * @return \Generator<int, array<string, mixed>> GraphQL product nodes; `_incomplete` when a product has more variants than one page
     */
    public function products(array $locationIds = []): \Generator
    {
        $levels = '';
        foreach (array_values($locationIds) as $i => $locationId) {
            $levels .= ' l'.$i.': inventoryLevel(locationId: '.json_encode((string) $locationId).') { location { id } quantities(names: ["available"]) { name quantity } }';
        }

        $query = 'query Products($first: Int!, $cursor: String) { products(first: $first, after: $cursor) { nodes { id legacyResourceId title handle descriptionHtml status options { name values } '
            .'variants(first: 100) { nodes { id sku barcode price selectedOptions { name value } inventoryItem { id tracked unitCost { amount }'.$levels.' } } pageInfo { hasNextPage } } } pageInfo { hasNextPage endCursor } } }';

        foreach ($this->paginate($query, 'products', [], first: 10) as $product) {
            $variants = [];
            foreach ((array) ($product['variants']['nodes'] ?? []) as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $item = (array) ($variant['inventoryItem'] ?? []);
                $nodes = [];
                foreach ($item as $key => $value) {
                    if (preg_match('/^l\d+$/', (string) $key)) {
                        if (is_array($value)) {
                            $nodes[] = $value;
                        }
                        unset($item[$key]);
                    }
                }
                $item['inventoryLevels'] = ['nodes' => $nodes];
                $variant['inventoryItem'] = $item;
                $variants[] = $variant;
            }
            $product['_incomplete'] = (bool) ($product['variants']['pageInfo']['hasNextPage'] ?? false);
            $product['variants'] = ['nodes' => $variants];

            yield $product;
        }
    }

    /**
     * Walk a connection page by page. The query takes $first and $cursor.
     * When Shopify refuses a page for costing more than its 1000-point
     * limit, the page size shrinks in proportion and the page is asked for
     * again; only a single record over the limit is an error.
     *
     * @param  array<string, mixed>  $variables
     * @return \Generator<int, array<string, mixed>>
     */
    private function paginate(string $query, string $root, array $variables, int $first, int $maxPages = 1000): \Generator
    {
        $cursor = null;
        $pages = 0;

        while (true) {
            try {
                $data = $this->graphql($query, $variables + ['first' => $first, 'cursor' => $cursor]);
            } catch (ShopifyException $e) {
                $cost = self::costOver($e);
                if ($cost === null || $first <= 1) {
                    throw $e;
                }
                $first = max(1, min($first - 1, intdiv($first * 900, $cost)));

                continue; // the same page again, smaller
            }

            foreach ($data[$root]['nodes'] ?? [] as $node) {
                if (is_array($node)) {
                    yield $node;
                }
            }

            $page = $data[$root]['pageInfo'] ?? [];
            $cursor = ($page['hasNextPage'] ?? false) ? ($page['endCursor'] ?? null) : null;
            if ($cursor === null || ++$pages >= $maxPages) {
                return;
            }
        }
    }

    /**
     * The requested cost when Shopify refused a query as too costly, else null.
     */
    private static function costOver(ShopifyException $e): ?int
    {
        return preg_match('/Query cost is (\d+), which exceeds/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;
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

    // ------------------------------------------------------------------
    // Stock per warehouse (2.0)
    // ------------------------------------------------------------------

    /**
     * Every quantity of the given inventory items at the given locations.
     *
     * @param  array<int, string>  $itemIds  inventory item gids
     * @param  array<int, string>  $locationIds  location gids
     * @return array<string, array<string, array{quantities: array<string, int>, updated_at: string|null}>> item gid => location gid => level; a missing location means the item is not stocked there
     */
    public function inventoryLevels(array $itemIds, array $locationIds): array
    {
        $levels = $this->levelAliases($locationIds);
        $out = [];

        foreach (array_chunk(array_values(array_unique($itemIds)), 50) as $chunk) {
            $data = $this->graphql(
                'query Levels($ids: [ID!]!) { nodes(ids: $ids) { ... on InventoryItem { id'.$levels.' } } }',
                ['ids' => array_map(fn ($id) => self::gid('InventoryItem', $id), $chunk)],
            );

            foreach ((array) ($data['nodes'] ?? []) as $node) {
                if (is_array($node) && isset($node['id'])) {
                    $out[(string) $node['id']] = self::levelsOf($node);
                }
            }
        }

        return $out;
    }

    /**
     * Every inventory item of the store with its variant and its quantities
     * at the given locations, page by page.
     *
     * @param  array<int, string>  $locationIds
     * @return \Generator<int, array{inventory_item_id: string, sku: string|null, tracked: bool, variant_id: string|null, levels: array<string, array{quantities: array<string, int>, updated_at: string|null}>}>
     */
    public function allInventoryLevels(array $locationIds): \Generator
    {
        $query = 'query InventoryItems($first: Int!, $cursor: String) { inventoryItems(first: $first, after: $cursor) { nodes { id sku tracked variants(first: 1) { nodes { id legacyResourceId } }'
            .$this->levelAliases($locationIds).' } pageInfo { hasNextPage endCursor } } }';

        foreach ($this->paginate($query, 'inventoryItems', [], first: 100) as $node) {
            yield [
                'inventory_item_id' => (string) $node['id'],
                'sku' => isset($node['sku']) && $node['sku'] !== '' ? (string) $node['sku'] : null,
                'tracked' => (bool) ($node['tracked'] ?? true),
                'variant_id' => isset($node['variants']['nodes'][0]['legacyResourceId']) ? (string) $node['variants']['nodes'][0]['legacyResourceId'] : null,
                'levels' => self::levelsOf($node),
            ];
        }
    }

    private function levelAliases(array $locationIds): string
    {
        $names = json_encode(self::QUANTITY_NAMES);
        $levels = '';
        foreach (array_values($locationIds) as $i => $locationId) {
            $levels .= ' l'.$i.': inventoryLevel(locationId: '.json_encode((string) $locationId, JSON_UNESCAPED_SLASHES).') { updatedAt location { id } quantities(names: '.$names.') { name quantity } }';
        }

        return $levels;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, array{quantities: array<string, int>, updated_at: string|null}>
     */
    private static function levelsOf(array $node): array
    {
        $out = [];
        foreach ($node as $key => $level) {
            if (! preg_match('/^l\d+$/', (string) $key) || ! is_array($level) || ! isset($level['location']['id'])) {
                continue;
            }
            $quantities = array_fill_keys(self::QUANTITY_NAMES, 0);
            foreach ((array) ($level['quantities'] ?? []) as $q) {
                if (is_array($q) && isset($q['name'])) {
                    $quantities[(string) $q['name']] = (int) ($q['quantity'] ?? 0);
                }
            }
            $out[(string) $level['location']['id']] = ['quantities' => $quantities, 'updated_at' => $level['updatedAt'] ?? null];
        }

        return $out;
    }

    /**
     * Change one quantity name by deltas (inventoryAdjustQuantities), with
     * compare-and-swap (changeFromQuantity) and an idempotency key.
     *
     * @param  array<int, array{inventory_item_id: string, location_id: string, delta: int, change_from: int|null, ledger_uri?: string|null}>  $changes
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    public function adjustQuantities(string $name, string $reason, array $changes, string $idempotencyKey, string $referenceUri): array
    {
        $input = [
            'name' => $name,
            'reason' => $reason,
            'referenceDocumentUri' => $referenceUri,
            'changes' => array_map(fn (array $c) => array_filter([
                'inventoryItemId' => self::gid('InventoryItem', $c['inventory_item_id']),
                'locationId' => self::gid('Location', $c['location_id']),
                'delta' => (int) $c['delta'],
                'changeFromQuantity' => $c['change_from'],
                // Required for every name but "available".
                'ledgerDocumentUri' => $name === 'available' ? null : ($c['ledger_uri'] ?? $referenceUri),
            ], fn ($v, $k) => $v !== null || $k === 'changeFromQuantity', ARRAY_FILTER_USE_BOTH), array_values($changes)),
        ];

        $data = $this->graphql(
            'mutation Adjust($input: InventoryAdjustQuantitiesInput!, $key: String!) { inventoryAdjustQuantities(input: $input) @idempotent(key: $key) { inventoryAdjustmentGroup { id changes { name delta quantityAfterChange item { id } location { id } } } userErrors { field message code } } }',
            ['input' => $input, 'key' => $idempotencyKey],
        );

        return self::adjustmentResult($data['inventoryAdjustQuantities'] ?? []);
    }

    /**
     * Move units between quantity names at a location (e.g. available to
     * quality_control), with compare-and-swap on the source.
     *
     * @param  array<int, array{inventory_item_id: string, location_id: string, quantity: int, from: string, to: string, change_from: int|null}>  $changes
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    public function moveQuantities(string $reason, array $changes, string $idempotencyKey, string $referenceUri): array
    {
        $input = [
            'reason' => $reason,
            'referenceDocumentUri' => $referenceUri,
            'changes' => array_map(fn (array $c) => [
                'inventoryItemId' => self::gid('InventoryItem', $c['inventory_item_id']),
                'quantity' => (int) $c['quantity'],
                'from' => array_filter([
                    'locationId' => self::gid('Location', $c['location_id']),
                    'name' => $c['from'],
                    'changeFromQuantity' => $c['change_from'],
                    'ledgerDocumentUri' => $c['from'] === 'available' ? null : $referenceUri,
                ], fn ($v, $k) => $v !== null || $k === 'changeFromQuantity', ARRAY_FILTER_USE_BOTH),
                'to' => array_filter([
                    'locationId' => self::gid('Location', $c['location_id']),
                    'name' => $c['to'],
                    'changeFromQuantity' => null,
                    'ledgerDocumentUri' => $c['to'] === 'available' ? null : $referenceUri,
                ], fn ($v, $k) => $v !== null || $k === 'changeFromQuantity', ARRAY_FILTER_USE_BOTH),
            ], array_values($changes)),
        ];

        $data = $this->graphql(
            'mutation Move($input: InventoryMoveQuantitiesInput!, $key: String!) { inventoryMoveQuantities(input: $input) @idempotent(key: $key) { inventoryAdjustmentGroup { id changes { name delta quantityAfterChange item { id } location { id } } } userErrors { field message code } } }',
            ['input' => $input, 'key' => $idempotencyKey],
        );

        return self::adjustmentResult($data['inventoryMoveQuantities'] ?? []);
    }

    /**
     * Set one quantity name to absolute figures, refused when the current
     * figure is no longer the one given (an operator's count).
     *
     * @param  array<int, array{inventory_item_id: string, location_id: string, quantity: int, change_from: int|null}>  $quantities
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    public function setQuantities(string $name, string $reason, array $quantities, string $idempotencyKey, string $referenceUri): array
    {
        $input = [
            'name' => $name,
            'reason' => $reason,
            'referenceDocumentUri' => $referenceUri,
            'quantities' => array_map(fn (array $q) => [
                'inventoryItemId' => self::gid('InventoryItem', $q['inventory_item_id']),
                'locationId' => self::gid('Location', $q['location_id']),
                'quantity' => (int) $q['quantity'],
                'changeFromQuantity' => $q['change_from'],
            ], array_values($quantities)),
        ];

        $data = $this->graphql(
            'mutation SetQ($input: InventorySetQuantitiesInput!, $key: String!) { inventorySetQuantities(input: $input) @idempotent(key: $key) { inventoryAdjustmentGroup { id changes { name delta quantityAfterChange item { id } location { id } } } userErrors { field message code } } }',
            ['input' => $input, 'key' => $idempotencyKey],
        );

        return self::adjustmentResult($data['inventorySetQuantities'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private static function adjustmentResult(array $payload): array
    {
        $group = $payload['inventoryAdjustmentGroup'] ?? null;

        return [
            'group_id' => is_array($group) ? ($group['id'] ?? null) : null,
            'changes' => array_map(fn ($c) => [
                'name' => $c['name'] ?? null,
                'delta' => (int) ($c['delta'] ?? 0),
                'quantity_after' => isset($c['quantityAfterChange']) ? (int) $c['quantityAfterChange'] : null,
                'inventory_item_id' => $c['item']['id'] ?? null,
                'location_id' => $c['location']['id'] ?? null,
            ], is_array($group) ? (array) ($group['changes'] ?? []) : []),
            'errors' => array_map(fn ($e) => [
                'code' => $e['code'] ?? null,
                'message' => (string) ($e['message'] ?? 'Unknown error'),
                'field' => $e['field'] ?? null,
            ], (array) ($payload['userErrors'] ?? [])),
        ];
    }

    /**
     * An order's open fulfilment orders and, for each line, how many units
     * are still to be fulfilled from which location.
     *
     * @return list<array{id: string, status: string, request_status: string|null, location_id: string|null, lines: list<array{id: string, line_item_id: string, inventory_item_id: string|null, variant_id: string|null, remaining: int}>}>
     */
    public function fulfillmentOrders(string $orderId): array
    {
        $data = $this->graphql(
            'query FulfillmentOrders($id: ID!) { order(id: $id) { id fulfillmentOrders(first: 20) { nodes { id status requestStatus assignedLocation { location { id } } lineItems(first: 50) { nodes { id remainingQuantity totalQuantity inventoryItemId lineItem { id } variant { id } } } } } } }',
            ['id' => self::gid('Order', $orderId)],
        );

        $out = [];
        foreach ((array) ($data['order']['fulfillmentOrders']['nodes'] ?? []) as $node) {
            if (! is_array($node) || in_array($node['status'] ?? '', ['CANCELLED', 'CLOSED'], true)) {
                continue;
            }
            $lines = [];
            foreach ((array) ($node['lineItems']['nodes'] ?? []) as $line) {
                $lines[] = [
                    'id' => (string) $line['id'],
                    'line_item_id' => self::legacyId($line['lineItem']['id'] ?? ''),
                    'inventory_item_id' => isset($line['inventoryItemId']) ? (string) $line['inventoryItemId'] : null,
                    'variant_id' => isset($line['variant']['id']) ? self::legacyId($line['variant']['id']) : null,
                    'remaining' => (int) ($line['remainingQuantity'] ?? 0),
                ];
            }
            $out[] = [
                'id' => (string) $node['id'],
                'status' => (string) ($node['status'] ?? ''),
                'request_status' => $node['requestStatus'] ?? null,
                'location_id' => $node['assignedLocation']['location']['id'] ?? null,
                'lines' => $lines,
            ];
        }

        return $out;
    }

    /**
     * Fulfil fulfilment order lines (the units leave), with tracking.
     *
     * @param  array<string, array<int, array{id: string, quantity: int}>>  $linesByFulfillmentOrder  fulfilment order gid => its lines
     * @param  array{company?: string|null, number?: string|null, url?: string|null}  $tracking
     * @return array{id: string|null, legacy_id: string|null, errors: array<int, string>}
     */
    public function createFulfillment(array $linesByFulfillmentOrder, array $tracking, bool $notifyCustomer): array
    {
        $fulfillment = [
            'notifyCustomer' => $notifyCustomer,
            'lineItemsByFulfillmentOrder' => array_map(fn ($foId, $lines) => [
                'fulfillmentOrderId' => $foId,
                'fulfillmentOrderLineItems' => array_map(fn ($l) => ['id' => $l['id'], 'quantity' => (int) $l['quantity']], array_values($lines)),
            ], array_keys($linesByFulfillmentOrder), array_values($linesByFulfillmentOrder)),
        ];

        $tracking = array_filter([
            'company' => $tracking['company'] ?? null,
            'number' => $tracking['number'] ?? null,
            'url' => $tracking['url'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        if ($tracking !== []) {
            $fulfillment['trackingInfo'] = $tracking;
        }

        $data = $this->graphql(
            'mutation CreateFulfillment($fulfillment: FulfillmentInput!) { fulfillmentCreate(fulfillment: $fulfillment) { fulfillment { id legacyResourceId status location { id } } userErrors { field message } } }',
            ['fulfillment' => $fulfillment],
        );
        $payload = $data['fulfillmentCreate'] ?? [];

        return [
            'id' => $payload['fulfillment']['id'] ?? null,
            'legacy_id' => isset($payload['fulfillment']['legacyResourceId']) ? (string) $payload['fulfillment']['legacyResourceId'] : null,
            'errors' => array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($payload['userErrors'] ?? [])),
        ];
    }

    /**
     * Assign some units of a fulfilment order to another location.
     *
     * @param  array<int, array{id: string, quantity: int}>  $lines  fulfilment order line items
     * @return array{moved_id: string|null, errors: array<int, string>}
     */
    public function moveFulfillmentOrder(string $fulfillmentOrderId, string $locationId, array $lines): array
    {
        $data = $this->graphql(
            'mutation MoveFO($id: ID!, $newLocationId: ID!, $lineItems: [FulfillmentOrderLineItemInput!]) { fulfillmentOrderMove(id: $id, newLocationId: $newLocationId, fulfillmentOrderLineItems: $lineItems) { movedFulfillmentOrder { id status assignedLocation { location { id } } } originalFulfillmentOrder { id status } remainingFulfillmentOrder { id } userErrors { field message } } }',
            [
                'id' => $fulfillmentOrderId,
                'newLocationId' => self::gid('Location', $locationId),
                'lineItems' => array_map(fn ($l) => ['id' => $l['id'], 'quantity' => (int) $l['quantity']], array_values($lines)),
            ],
        );

        $payload = $data['fulfillmentOrderMove'] ?? [];

        return [
            'moved_id' => $payload['movedFulfillmentOrder']['id'] ?? null,
            'errors' => array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($payload['userErrors'] ?? [])),
        ];
    }

    /**
     * An inventory transfer by its name (e.g. "#T0001") with its lines and
     * shipments, or null.
     *
     * @return array<string, mixed>|null
     */
    public function inventoryTransfer(string $name): ?array
    {
        $data = $this->graphql(
            'query Transfers($query: String!) { inventoryTransfers(first: 5, query: $query) { nodes { id name status referenceName dateCreated totalQuantity receivedQuantity origin { name location { id } } destination { name location { id } } lineItems(first: 100) { nodes { id title totalQuantity shippedQuantity processableQuantity inventoryItem { id sku } } } shipments(first: 10) { nodes { id name status lineItems(first: 100) { nodes { id quantity unreceivedQuantity acceptedQuantity rejectedQuantity inventoryItem { id sku } } } } } } } }',
            ['query' => ltrim($name, '#')],
        );

        foreach ((array) ($data['inventoryTransfers']['nodes'] ?? []) as $node) {
            if (is_array($node) && ltrim((string) ($node['name'] ?? ''), '#') === ltrim($name, '#')) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Change the destination of a transfer (Shopify allows it on drafts).
     *
     * @return array<int, string> errors
     */
    public function editTransferDestination(string $transferId, string $locationId): array
    {
        $data = $this->graphql(
            'mutation EditTransfer($id: ID!, $input: InventoryTransferEditInput!) { inventoryTransferEdit(id: $id, input: $input) { inventoryTransfer { id status destination { location { id } } } userErrors { field message code } } }',
            ['id' => $transferId, 'input' => ['destinationId' => self::gid('Location', $locationId)]],
        );

        return array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($data['inventoryTransferEdit']['userErrors'] ?? []));
    }

    /**
     * Cancel a transfer (releases what it held at the origin).
     *
     * @return array<int, string> errors
     */
    public function cancelTransfer(string $transferId): array
    {
        $data = $this->graphql(
            'mutation CancelTransfer($id: ID!) { inventoryTransferCancel(id: $id) { inventoryTransfer { id status } userErrors { field message code } } }',
            ['id' => $transferId],
        );

        return array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($data['inventoryTransferCancel']['userErrors'] ?? []));
    }

    /**
     * Copy a transfer into a new draft.
     *
     * @return array{id: string|null, name: string|null, errors: array<int, string>}
     */
    public function duplicateTransfer(string $transferId): array
    {
        $data = $this->graphql(
            'mutation DuplicateTransfer($id: ID!) { inventoryTransferDuplicate(id: $id) { inventoryTransfer { id name status } userErrors { field message code } } }',
            ['id' => $transferId],
        );
        $payload = $data['inventoryTransferDuplicate'] ?? [];

        return [
            'id' => $payload['inventoryTransfer']['id'] ?? null,
            'name' => $payload['inventoryTransfer']['name'] ?? null,
            'errors' => array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($payload['userErrors'] ?? [])),
        ];
    }

    /**
     * @return array<int, string> errors
     */
    public function markTransferReady(string $transferId, string $idempotencyKey): array
    {
        $data = $this->graphql(
            'mutation ReadyTransfer($id: ID!, $key: String!) { inventoryTransferMarkAsReadyToShip(id: $id) @idempotent(key: $key) { inventoryTransfer { id status } userErrors { field message code } } }',
            ['id' => $transferId, 'key' => $idempotencyKey],
        );

        return array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($data['inventoryTransferMarkAsReadyToShip']['userErrors'] ?? []));
    }

    /**
     * Receive shipment lines, each accepted (into the destination's
     * available) or rejected (not added there).
     *
     * @param  array<int, array{id: string, quantity: int, reason: 'ACCEPTED'|'REJECTED'}>  $lines
     * @return array<int, string> errors
     */
    public function receiveShipment(string $shipmentId, array $lines, string $idempotencyKey): array
    {
        $data = $this->graphql(
            'mutation ReceiveShipment($id: ID!, $lineItems: [InventoryShipmentReceiveItemInput!], $key: String!) { inventoryShipmentReceive(id: $id, lineItems: $lineItems) @idempotent(key: $key) { inventoryShipment { id status } userErrors { field message code } } }',
            [
                'id' => $shipmentId,
                'lineItems' => array_map(fn ($l) => ['shipmentLineItemId' => $l['id'], 'quantity' => (int) $l['quantity'], 'reason' => $l['reason']], array_values($lines)),
                'key' => $idempotencyKey,
            ],
        );

        return array_map(fn ($e) => (string) ($e['message'] ?? 'Unknown error'), (array) ($data['inventoryShipmentReceive']['userErrors'] ?? []));
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
        $item = is_array($node) ? ($node['inventoryItem'] ?? null) : null;

        if (! is_array($node) || empty($node['id']) || ! is_array($item) || empty($item['id'])) {
            return null;
        }

        return [
            'sku' => self::effectiveSku($node['sku'] ?? null, (string) $node['id']),
            'variant_id' => (string) $node['id'],
            'inventory_item_id' => (string) $item['id'],
            'tracked' => (bool) ($item['tracked'] ?? true),
        ];
    }

    /** Prefix of the SKU Inventoros gives a Shopify variant that has none. */
    public const GENERATED_SKU_PREFIX = 'SHOPIFY-';

    /**
     * The SKU a Shopify variant goes by in Inventoros: its own, or
     * SHOPIFY-<variant id> when it has none, so variants without a SKU can
     * still be matched (orders, stock, catalogue).
     */
    public static function effectiveSku(?string $sku, string|int $variantId): string
    {
        $sku = trim((string) $sku);

        return $sku !== '' ? $sku : self::generatedSku($variantId);
    }

    public static function generatedSku(string|int $variantId): string
    {
        return self::GENERATED_SKU_PREFIX.self::legacyId($variantId);
    }

    public static function isGeneratedSku(string $sku): bool
    {
        return str_starts_with($sku, self::GENERATED_SKU_PREFIX);
    }
}
