<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins\Concerns;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\User;
use App\Services\PluginService;
use App\Support\PluginPageRoutes;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\ShopifyClient;

/**
 * Activates the bundled Shopify plugin and fakes Shopify (token endpoint and
 * Admin GraphQL API) for its tests. Needs BuildsApiFixtures.
 */
trait InteractsWithShopify
{
    protected const SECRET = 'shpss_test_secret';

    protected const LOCATION = 'gid://shopify/Location/1';

    protected Organization $org;

    protected User $admin;

    private bool $assetsExisted;

    /** @var array<int, array{query: string, variables: array<string, mixed>}> */
    protected array $graphqlCalls = [];

    /** @var array<string, mixed> */
    protected array $shopify = [];

    private bool $shopifyFaked = false;

    protected function setUpShopify(): void
    {
        $this->markInstalled();

        $this->assetsExisted = File::isDirectory(public_path('plugin-assets/shopify'));
        app(PluginService::class)->activatePlugin('shopify');
        // In a running app the next request adds the page route at boot.
        PluginPageRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        $this->org = $this->makeOrganization('Shop Org');
        $this->admin = $this->makeAdmin($this->org);

        // Shopify is never really called: an empty store unless a test says otherwise.
        $this->fakeShopify();
    }

    protected function tearDownShopify(): void
    {
        if (! $this->assetsExisted) {
            File::deleteDirectory(public_path('plugin-assets/shopify'));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function connect(array $attributes = []): ShopifyConnection
    {
        return ShopifyConnection::query()->create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $this->admin->id,
            'shop_domain' => 'test-store.myshopify.com',
            'shop_name' => 'Test Store',
            'client_id' => 'client-id',
            'client_secret' => self::SECRET,
            'location_map' => [['location_id' => self::LOCATION, 'warehouse_id' => null]],
            'locations' => [['id' => self::LOCATION, 'name' => 'Main warehouse']],
            'import_orders' => true,
            'push_inventory' => true,
        ], $attributes));
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    protected function makeVariantProduct(string $sku, int $stock): array
    {
        $parent = $this->makeProduct($this->org, ['sku' => $sku.'-PARENT', 'stock' => 0, 'has_variants' => true]);
        $variant = ProductVariant::query()->create([
            'organization_id' => $this->org->id,
            'product_id' => $parent->id,
            'sku' => $sku,
            'title' => $sku,
            'option_values' => ['Size' => 'M'],
            'price' => 49.00,
            'stock' => $stock,
            'is_active' => true,
        ]);

        return [$parent, $variant];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(string $topic, array $payload, string $token): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', route('plg.shopify.webhooks.receive', ['token' => $token]), [], [], [], [
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function orderPayload(array $lines, array $extra = []): array
    {
        return array_merge([
            'id' => 1001,
            'name' => '#1001',
            'email' => 'ada@example.com',
            'currency' => 'USD',
            'financial_status' => 'pending',
            'created_at' => '2026-10-01T10:00:00+02:00',
            'cancelled_at' => null,
            'shipping_address' => [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'address1' => '1 Analytical St',
                'city' => 'London',
                'zip' => 'N1',
                'country' => 'United Kingdom',
            ],
            'line_items' => $lines,
            'shipping_lines' => [],
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    protected function line(string $sku, int $quantity, string $price, ?string $discount = null, ?string $tax = null): array
    {
        static $id = 1;

        return [
            'id' => $id++,
            'sku' => $sku,
            'title' => $sku,
            'quantity' => $quantity,
            'current_quantity' => $quantity,
            'price' => $price,
            'price_set' => ['shop_money' => ['amount' => $price, 'currency_code' => 'USD']],
            'discount_allocations' => $discount === null ? [] : [['amount' => $discount, 'amount_set' => ['shop_money' => ['amount' => $discount]]]],
            'tax_lines' => $tax === null ? [] : [['price' => $tax, 'rate' => 0.22, 'title' => 'VAT']],
        ];
    }

    /**
     * Fake the token endpoint and the GraphQL API, recording each operation.
     * Calling it again changes what the fake store holds.
     *
     * @param  array<int, array{sku: string, item: string, tracked?: bool}>  $variants
     * @param  array<int, array{id: string, name: string, isActive: bool}>  $locations
     * @param  array<int, array<string, mixed>>  $orders  GraphQL order nodes
     * @param  array<int, string>  $assigned  location gids an order's fulfilment orders are assigned to
     * @param  array<int, array<string, mixed>>  $products  GraphQL product nodes
     * @param  array<int, array{topic: string, uri: string}>  $webhooks  existing subscriptions
     */
    protected function fakeShopify(array $variants = [], array $locations = [], bool $notStockedOnce = false, array $orders = [], array $assigned = [], array $products = [], array $webhooks = [], ?array $scopes = null, bool $denyOnce = false, ?int $maxFirst = null): void
    {
        $this->shopify = [
            'max_first' => $maxFirst,
            'scopes' => $scopes ?? [...ShopifyClient::REQUIRED_SCOPES, ...ShopifyClient::OPTIONAL_SCOPES],
            'deny_once' => $denyOnce,
            'nodes' => array_map(fn ($v) => [
                'id' => 'gid://shopify/ProductVariant/'.md5($v['sku']),
                'sku' => $v['sku'],
                'inventoryItem' => ['id' => $v['item'], 'tracked' => $v['tracked'] ?? true],
            ], $variants),
            'locations' => $locations,
            'fail_next_set' => $notStockedOnce,
            'orders' => $orders,
            'assigned' => $assigned,
            'products' => $products,
            'webhooks' => array_map(fn ($w, $i) => ['id' => 'gid://shopify/WebhookSubscription/'.($i + 100)] + $w, $webhooks, array_keys($webhooks)),
        ];
        $this->graphqlCalls = [];

        if ($this->shopifyFaked) {
            return; // the registered fake reads $this->shopify
        }
        $this->shopifyFaked = true;

        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shpat_token', 'scope' => 'read_orders', 'expires_in' => 86399]);
            }

            $query = (string) $request['query'];
            $variables = $request['variables'] ?? [];
            $this->graphqlCalls[] = ['query' => $query, 'variables' => $variables];

            // Shopify's 1000-point limit: pages larger than max_first cost too much.
            if ($this->shopify['max_first'] !== null && ($variables['first'] ?? 0) > $this->shopify['max_first']) {
                $cost = 400 * $variables['first'];

                return Http::response(['errors' => [['message' => "Query cost is {$cost}, which exceeds the single query max cost limit (1000).", 'extensions' => ['code' => 'MAX_COST_EXCEEDED', 'cost' => $cost, 'maxCost' => 1000]]]]);
            }

            if ($this->shopify['deny_once'] && ! str_contains($query, 'GrantedScopes')) {
                $this->shopify['deny_once'] = false;

                return Http::response(['errors' => [['message' => 'Access denied for locations field.', 'extensions' => ['code' => 'ACCESS_DENIED']]]]);
            }

            $failSet = str_contains($query, 'inventorySetQuantities') && $this->shopify['fail_next_set'];
            if ($failSet) {
                $this->shopify['fail_next_set'] = false;
            }

            $page = fn (array $nodes) => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

            return Http::response(['data' => match (true) {
                str_contains($query, 'GrantedScopes') => ['currentAppInstallation' => ['accessScopes' => array_map(fn ($h) => ['handle' => $h], $this->shopify['scopes'])]],
                str_contains($query, 'ShopInfo') => ['shop' => ['name' => 'Test Store', 'myshopifyDomain' => 'test-store.myshopify.com'], 'locations' => ['nodes' => $this->shopify['locations']]],
                str_contains($query, 'VariantsBySku'), str_contains($query, 'AllVariants') => ['productVariants' => $page($this->shopify['nodes'])],
                $failSet => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => null, 'userErrors' => [['field' => ['input', 'quantities', '0', 'locationId'], 'message' => 'Not stocked', 'code' => 'ITEM_NOT_STOCKED_AT_LOCATION']]]],
                str_contains($query, 'inventorySetQuantities') => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['id' => 'gid://shopify/InventoryAdjustmentGroup/1'], 'userErrors' => []]],
                str_contains($query, 'inventoryActivate') => ['inventoryActivate' => ['inventoryLevel' => ['id' => 'gid://shopify/InventoryLevel/1'], 'userErrors' => []]],
                str_contains($query, 'webhookSubscriptions(') => ['webhookSubscriptions' => ['nodes' => $this->shopify['webhooks']]],
                str_contains($query, 'webhookSubscriptionCreate') => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]],
                str_contains($query, 'webhookSubscriptionDelete') => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => $variables['id'] ?? null, 'userErrors' => []]],
                str_contains($query, 'AssignedLocations') => ['order' => ['fulfillmentOrders' => ['nodes' => array_map(fn ($id) => ['status' => 'OPEN', 'assignedLocation' => ['location' => ['id' => $id]]], $this->shopify['assigned'])]]],
                str_contains($query, 'query Orders') => ['orders' => $page($this->shopify['orders'])],
                str_contains($query, 'query Order(') => ['order' => $this->shopify['orders'][0] ?? null],
                str_contains($query, 'query Products') => ['products' => $page($this->shopify['products'])],
                str_contains($query, 'productSet') => ['productSet' => [
                    'product' => ['id' => 'gid://shopify/Product/900', 'variants' => ['nodes' => array_map(fn ($v, $i) => [
                        'id' => 'gid://shopify/ProductVariant/90'.$i,
                        'sku' => $v['sku'],
                        'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/90'.$i, 'tracked' => true],
                    ], $variables['input']['variants'] ?? [], array_keys($variables['input']['variants'] ?? []))]],
                    'userErrors' => [],
                ]],
                default => [],
            }]);
        });
    }

    /**
     * @return array<int, array{query: string, variables: array<string, mixed>}>
     */
    protected function callsTo(string $operation): array
    {
        return array_values(array_filter($this->graphqlCalls, fn ($c) => str_contains($c['query'], $operation.'(')));
    }
}
