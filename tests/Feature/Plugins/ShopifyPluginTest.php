<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\User;
use App\Services\PluginService;
use App\Support\PluginPageRoutes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inventoros\Plugins\Shopify\Http\SettingsController;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\TestCase;

/**
 * The bundled Shopify plugin (plugins/shopify): order webhooks become
 * Inventoros orders, stock changes are pushed to Shopify, and the settings
 * page connects a store. Shopify itself is faked with Http::fake().
 */
final class ShopifyPluginTest extends TestCase
{
    use BuildsApiFixtures;
    use RefreshDatabase;

    private const SECRET = 'shpss_test_secret';

    private const LOCATION = 'gid://shopify/Location/1';

    private Organization $org;

    private User $admin;

    private bool $assetsExisted;

    /** @var array<int, array{query: string, variables: array<string, mixed>}> */
    private array $graphqlCalls = [];

    /** @var array{nodes: array<int, mixed>, locations: array<int, mixed>, fail_next_set: bool} */
    private array $shopify = ['nodes' => [], 'locations' => [], 'fail_next_set' => false];

    private bool $shopifyFaked = false;

    protected function setUp(): void
    {
        parent::setUp();
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

    protected function tearDown(): void
    {
        if (! $this->assetsExisted) {
            File::deleteDirectory(public_path('plugin-assets/shopify'));
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Activation
    // ------------------------------------------------------------------

    public function test_activation_creates_tables_routes_and_permission(): void
    {
        $this->assertTrue(\Schema::hasTable('shopify_connections'));
        $this->assertTrue(\Schema::hasTable('shopify_orders'));
        $this->assertTrue(\Schema::hasTable('shopify_inventory_items'));

        $this->assertTrue(Route::has('plg.shopify.settings'));
        $this->assertTrue(Route::has('plg.shopify.save'));
        $this->assertTrue(Route::has('plg.shopify.webhooks.receive'));

        $this->assertTrue($this->admin->hasPermission('shopify.manage'));
    }

    // ------------------------------------------------------------------
    // Webhooks: authentication
    // ------------------------------------------------------------------

    public function test_webhook_with_unknown_token_is_404(): void
    {
        $this->postWebhook('orders/create', $this->orderPayload([]), 'nosuchtoken0000000000000')->assertNotFound();
    }

    public function test_webhook_with_bad_signature_is_refused(): void
    {
        $connection = $this->connect();
        $body = json_encode($this->orderPayload([]));

        $this->call('POST', route('plg.shopify.webhooks.receive', ['token' => $connection->webhook_token]), [], [], [], [
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true)),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(0, ShopifyOrder::query()->count());
    }

    // ------------------------------------------------------------------
    // Order import
    // ------------------------------------------------------------------

    public function test_order_webhook_creates_an_inventoros_order_and_takes_stock(): void
    {
        $connection = $this->connect();
        $simple = $this->makeProduct($this->org, ['sku' => 'TEE-PLAIN', 'stock' => 10]);
        [$parent, $variant] = $this->makeVariantProduct('HOODIE-M', 6);

        $payload = $this->orderPayload([
            $this->line('TEE-PLAIN', 2, '19.90', discount: '4.00', tax: '7.88'),
            $this->line('HOODIE-M', 1, '49.00'),
        ], ['financial_status' => 'paid', 'shipping_lines' => [['price' => '5.00', 'discounted_price' => '4.50']]]);

        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();

        $order = Order::query()->where('source', 'shopify')->sole();
        $this->assertSame('1001', $order->external_id);
        $this->assertSame('#1001', $order->external_reference);
        $this->assertSame('Ada Lovelace', $order->customer_name);
        $this->assertSame('ada@example.com', $order->customer_email);
        $this->assertSame('processing', $order->status->value);
        $this->assertSame('4.50', (string) $order->shipping);
        $this->assertSame('1001', $order->metadata['shopify']['id']);
        $this->assertCount(2, $order->items);

        $tee = $order->items->firstWhere('sku', 'TEE-PLAIN');
        $this->assertSame(2, (int) $tee->quantity);
        $this->assertSame('19.90', (string) $tee->unit_price);
        $this->assertSame('4.00', (string) $tee->discount_amount);
        $this->assertSame('7.88', (string) $tee->tax);

        $this->assertSame(8, $simple->fresh()->stock);
        $this->assertSame(5, $variant->fresh()->stock);

        $record = ShopifyOrder::query()->sole();
        $this->assertSame(ShopifyOrder::IMPORTED, $record->status);
        $this->assertSame($order->id, $record->order_id);
    }

    public function test_a_duplicate_delivery_creates_one_order(): void
    {
        $connection = $this->connect();
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $payload = $this->orderPayload([$this->line('MUG', 1, '9.00')]);

        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();
        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_unmatched_lines_are_left_out_and_noted(): void
    {
        $connection = $this->connect();
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);

        $this->postWebhook('orders/create', $this->orderPayload([
            $this->line('MUG', 1, '9.00'),
            $this->line('GIFT-CARD', 1, '25.00'),
        ]), $connection->webhook_token)->assertOk();

        $order = Order::query()->sole();
        $this->assertCount(1, $order->items);
        $this->assertStringContainsString('GIFT-CARD', $order->notes);
        $this->assertStringContainsString('GIFT-CARD', ShopifyOrder::query()->sole()->message);
    }

    public function test_an_order_with_no_matching_sku_is_skipped(): void
    {
        $connection = $this->connect();

        $this->postWebhook('orders/create', $this->orderPayload([$this->line('UNKNOWN', 1, '9.00')]), $connection->webhook_token)->assertOk();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(ShopifyOrder::SKIPPED, ShopifyOrder::query()->sole()->status);
    }

    public function test_insufficient_stock_fails_and_can_be_retried_after_restocking(): void
    {
        $connection = $this->connect();
        $product = $this->makeProduct($this->org, ['sku' => 'LAMP', 'stock' => 1]);

        $this->postWebhook('orders/create', $this->orderPayload([$this->line('LAMP', 3, '30.00')]), $connection->webhook_token)->assertOk();

        $record = ShopifyOrder::query()->sole();
        $this->assertSame(ShopifyOrder::FAILED, $record->status);
        $this->assertStringContainsString('Insufficient stock', $record->message);
        $this->assertSame(0, Order::query()->count());

        StockAdjustment::adjust($product, 5, 'manual', 'Restock', actor: $this->admin);

        $this->actingAs($this->admin)
            ->post(route('plg.shopify.orders.retry', $record->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ShopifyOrder::IMPORTED, $record->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_cancelling_in_shopify_cancels_the_order_and_returns_stock(): void
    {
        $connection = $this->connect();
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $payload = $this->orderPayload([$this->line('MUG', 4, '9.00')]);

        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();
        $this->assertSame(6, $product->fresh()->stock);

        $this->postWebhook('orders/cancelled', $payload + ['cancelled_at' => now()->toIso8601String()], $connection->webhook_token)->assertOk();

        $this->assertSame('cancelled', Order::query()->sole()->status->value);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(ShopifyOrder::CANCELLED, ShopifyOrder::query()->sole()->status);
    }

    public function test_a_cancellation_that_overtakes_the_order_stops_its_import(): void
    {
        $connection = $this->connect();
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $payload = $this->orderPayload([$this->line('MUG', 4, '9.00')]);

        $this->postWebhook('orders/cancelled', $payload + ['cancelled_at' => now()->toIso8601String()], $connection->webhook_token)->assertOk();
        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(ShopifyOrder::CANCELLED, ShopifyOrder::query()->sole()->status);
    }

    public function test_orders_are_not_imported_when_turned_off(): void
    {
        $connection = $this->connect(['import_orders' => false]);
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);

        $this->postWebhook('orders/create', $this->orderPayload([$this->line('MUG', 1, '9.00')]), $connection->webhook_token)->assertOk();

        $this->assertSame(0, ShopifyOrder::query()->count());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_orders_land_in_the_connections_organization_only(): void
    {
        $other = $this->makeOrganization('Other Org');
        $this->makeProduct($other, ['sku' => 'MUG', 'stock' => 10]);
        $connection = $this->connect();

        $this->postWebhook('orders/create', $this->orderPayload([$this->line('MUG', 1, '9.00')]), $connection->webhook_token)->assertOk();

        // The other organization's MUG is not this store's MUG.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(ShopifyOrder::SKIPPED, ShopifyOrder::query()->sole()->status);
    }

    // ------------------------------------------------------------------
    // Stock push
    // ------------------------------------------------------------------

    public function test_a_stock_change_pushes_the_available_quantity_to_shopify(): void
    {
        $this->fakeShopify(variants: [['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/77']]);
        $this->connect();
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->graphqlCalls = [];

        StockAdjustment::adjust($product, -3, 'manual', 'Damaged', actor: $this->admin);

        $set = $this->callsTo('inventorySetQuantities');
        $this->assertCount(1, $set);
        $quantities = $set[0]['variables']['input']['quantities'];
        $this->assertSame([[
            'inventoryItemId' => 'gid://shopify/InventoryItem/77',
            'locationId' => self::LOCATION,
            'quantity' => 7,
            'changeFromQuantity' => null,
        ]], $quantities);
        $this->assertSame('available', $set[0]['variables']['input']['name']);
        $this->assertStringContainsString('@idempotent(key:', $set[0]['query']);

        $item = ShopifyInventoryItem::query()->where('sku', 'MUG')->sole();
        $this->assertSame(7, $item->last_pushed_quantity);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-Shopify-Access-Token', 'shpat_token'));
    }

    public function test_an_imported_order_pushes_the_reduced_stock_back_to_shopify(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $connection = $this->connect();
        $this->fakeShopify(variants: [['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/77']]);

        $this->postWebhook('orders/create', $this->orderPayload([$this->line('MUG', 4, '9.00')]), $connection->webhook_token)->assertOk();

        $this->assertSame(6, $product->fresh()->stock);
        $set = $this->callsTo('inventorySetQuantities');
        $this->assertCount(1, $set);
        $this->assertSame(6, $set[0]['variables']['input']['quantities'][0]['quantity']);
    }

    public function test_variant_stock_is_pushed_under_the_variant_sku(): void
    {
        $this->fakeShopify(variants: [['sku' => 'HOODIE-M', 'item' => 'gid://shopify/InventoryItem/5']]);
        $this->connect();
        [, $variant] = $this->makeVariantProduct('HOODIE-M', 6);
        $this->graphqlCalls = [];

        StockAdjustment::adjustVariant($variant, 4, 'manual', 'Count', actor: $this->admin);

        $set = $this->callsTo('inventorySetQuantities');
        $this->assertNotEmpty($set);
        $this->assertSame(10, end($set)['variables']['input']['quantities'][0]['quantity']);
    }

    public function test_nothing_is_pushed_without_a_location_or_when_turned_off(): void
    {
        $this->fakeShopify(variants: [['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/77']]);
        $this->connect(['push_inventory' => false]);
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);

        StockAdjustment::adjust($product, -3, 'manual', 'Damaged', actor: $this->admin);

        $this->assertSame([], $this->callsTo('inventorySetQuantities'));
    }

    public function test_an_item_not_stocked_at_the_location_is_activated_and_pushed_again(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->connect();
        $this->fakeShopify(variants: [['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/77']], notStockedOnce: true);

        StockAdjustment::adjust($product, 2, 'manual', 'Found', actor: $this->admin);

        $this->assertCount(1, $this->callsTo('inventoryActivate'));
        $this->assertCount(2, $this->callsTo('inventorySetQuantities'));
        $this->assertSame(12, ShopifyInventoryItem::query()->where('sku', 'MUG')->sole()->last_pushed_quantity);
    }

    public function test_full_sync_matches_skus_and_reports(): void
    {
        $this->fakeShopify(variants: [
            ['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/1'],
            ['sku' => 'HOODIE-M', 'item' => 'gid://shopify/InventoryItem/2'],
            ['sku' => 'ONLY-SHOPIFY', 'item' => 'gid://shopify/InventoryItem/3'],
            ['sku' => 'NOT-TRACKED', 'item' => 'gid://shopify/InventoryItem/4', 'tracked' => false],
        ]);
        $this->connect();
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->makeProduct($this->org, ['sku' => 'NOT-TRACKED', 'stock' => 1]);
        $this->makeProduct($this->org, ['sku' => 'ONLY-HERE', 'stock' => 3]);
        $this->makeVariantProduct('HOODIE-M', 6);
        $this->graphqlCalls = [];

        $this->actingAs($this->admin)->post(route('plg.shopify.sync'))->assertSessionHas('success');

        $summary = ShopifyConnection::forOrganization($this->org->id)->last_full_sync_summary;
        $this->assertSame(4, $summary['shopify_skus']);
        $this->assertSame(2, $summary['pushed']);
        $this->assertSame(1, $summary['untracked']);
        $this->assertSame(['ONLY-SHOPIFY'], $summary['only_in_shopify_sample']);
        $this->assertContains('ONLY-HERE', $summary['only_in_inventoros_sample']);

        $set = $this->callsTo('inventorySetQuantities');
        $this->assertCount(1, $set);
        $pushed = collect($set[0]['variables']['input']['quantities'])->pluck('quantity', 'inventoryItemId')->all();
        $this->assertSame(['gid://shopify/InventoryItem/2' => 6, 'gid://shopify/InventoryItem/1' => 10], $pushed);
    }

    // ------------------------------------------------------------------
    // Settings page
    // ------------------------------------------------------------------

    public function test_saving_settings_tests_the_connection_and_keeps_the_secret_server_side(): void
    {
        $this->fakeShopify(locations: [['id' => self::LOCATION, 'name' => 'Main warehouse', 'isActive' => true]]);

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'https://My-Store.myshopify.com/admin',
            'client_id' => 'client-id',
            'client_secret' => self::SECRET,
            'import_orders' => true,
            'push_inventory' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $connection = ShopifyConnection::forOrganization($this->org->id);
        $this->assertSame('my-store.myshopify.com', $connection->shop_domain);
        $this->assertSame('Test Store', $connection->shop_name);
        $this->assertSame(self::LOCATION, $connection->location_id, 'the only location is chosen');
        $this->assertSame(self::SECRET, $connection->client_secret);
        $this->assertNotSame(self::SECRET, $connection->getRawOriginal('client_secret'), 'stored encrypted');
        $this->assertSame($this->admin->id, $connection->user_id);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://my-store.myshopify.com/admin/oauth/access_token'
            && $r['grant_type'] === 'client_credentials' && $r['client_secret'] === self::SECRET);

        $props = SettingsController::props(request(), $this->admin);
        $encoded = json_encode($props);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
        $this->assertStringNotContainsString('shpat_token', $encoded);
        $this->assertTrue($props['connection']['has_client_secret']);
    }

    public function test_settings_refuse_an_address_that_is_not_a_shopify_store(): void
    {
        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'evil.example.com',
            'client_id' => 'client-id',
            'client_secret' => self::SECRET,
        ])->assertSessionHasErrors('shop_domain');

        $this->assertNull(ShopifyConnection::forOrganization($this->org->id));
    }

    public function test_the_settings_page_and_actions_need_the_permission(): void
    {
        $member = $this->makeMember($this->org, ['view_products']);

        $this->actingAs($member)->get(route('plg.shopify.settings'))->assertForbidden();
        $this->actingAs($member)->post(route('plg.shopify.save'), [])->assertForbidden();

        $this->actingAs($this->admin)->get(route('plg.shopify.settings'))->assertOk();
    }

    public function test_registering_webhooks_creates_both_subscriptions(): void
    {
        config(['app.url' => 'https://inventory.example.com']);
        $this->fakeShopify();
        $connection = $this->connect();

        // Without https Shopify cannot deliver, so nothing is registered.
        config(['app.url' => 'http://localhost']);
        $this->actingAs($this->admin)->post(route('plg.shopify.webhooks'))->assertSessionHas('error');
        $this->assertSame([], $this->callsTo('webhookSubscriptionCreate'));
        config(['app.url' => 'https://inventory.example.com']);

        $response = $this->actingAs($this->admin)->post(route('plg.shopify.webhooks'));
        $this->assertNull(session('error'));
        $response->assertSessionHas('success');

        $created = $this->callsTo('webhookSubscriptionCreate');
        $this->assertSame(['ORDERS_CREATE', 'ORDERS_CANCELLED'], array_map(fn ($c) => $c['variables']['topic'], $created));
        $this->assertSame('https://inventory.example.com/webhooks/plugins/shopify/'.$connection->webhook_token, $created[0]['variables']['uri']);
        $this->assertNotNull($connection->fresh()->webhooks_registered_at);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connect(array $attributes = []): ShopifyConnection
    {
        return ShopifyConnection::query()->create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $this->admin->id,
            'shop_domain' => 'test-store.myshopify.com',
            'shop_name' => 'Test Store',
            'client_id' => 'client-id',
            'client_secret' => self::SECRET,
            'location_id' => self::LOCATION,
            'locations' => [['id' => self::LOCATION, 'name' => 'Main warehouse']],
            'import_orders' => true,
            'push_inventory' => true,
        ], $attributes));
    }

    /**
     * @return array{0: Product, 1: ProductVariant}
     */
    private function makeVariantProduct(string $sku, int $stock): array
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
    private function postWebhook(string $topic, array $payload, string $token): TestResponse
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
    private function orderPayload(array $lines, array $extra = []): array
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
    private function line(string $sku, int $quantity, string $price, ?string $discount = null, ?string $tax = null): array
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
     *
     * @param  array<int, array{sku: string, item: string, tracked?: bool}>  $variants
     * @param  array<int, array{id: string, name: string, isActive: bool}>  $locations
     */
    private function fakeShopify(array $variants = [], array $locations = [], bool $notStockedOnce = false): void
    {
        $this->shopify = [
            'nodes' => array_map(fn ($v) => [
                'id' => 'gid://shopify/ProductVariant/'.md5($v['sku']),
                'sku' => $v['sku'],
                'inventoryItem' => ['id' => $v['item'], 'tracked' => $v['tracked'] ?? true],
            ], $variants),
            'locations' => $locations,
            'fail_next_set' => $notStockedOnce,
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
            $this->graphqlCalls[] = ['query' => $query, 'variables' => $request['variables'] ?? []];

            $failSet = str_contains($query, 'inventorySetQuantities') && $this->shopify['fail_next_set'];
            if ($failSet) {
                $this->shopify['fail_next_set'] = false;
            }

            return Http::response(['data' => match (true) {
                str_contains($query, 'ShopInfo') => ['shop' => ['name' => 'Test Store', 'myshopifyDomain' => 'test-store.myshopify.com'], 'locations' => ['nodes' => $this->shopify['locations']]],
                str_contains($query, 'VariantsBySku'), str_contains($query, 'AllVariants') => ['productVariants' => ['nodes' => $this->shopify['nodes'], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]],
                $failSet => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => null, 'userErrors' => [['field' => ['input', 'quantities', '0', 'locationId'], 'message' => 'Not stocked', 'code' => 'ITEM_NOT_STOCKED_AT_LOCATION']]]],
                str_contains($query, 'inventorySetQuantities') => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['id' => 'gid://shopify/InventoryAdjustmentGroup/1'], 'userErrors' => []]],
                str_contains($query, 'inventoryActivate') => ['inventoryActivate' => ['inventoryLevel' => ['id' => 'gid://shopify/InventoryLevel/1'], 'userErrors' => []]],
                str_contains($query, 'webhookSubscriptions(') => ['webhookSubscriptions' => ['nodes' => []]],
                str_contains($query, 'webhookSubscriptionCreate') => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]],
                default => [],
            }]);
        });
    }

    /**
     * @return array<int, array{query: string, variables: array<string, mixed>}>
     */
    private function callsTo(string $operation): array
    {
        return array_values(array_filter($this->graphqlCalls, fn ($c) => str_contains($c['query'], $operation.'(')));
    }
}
