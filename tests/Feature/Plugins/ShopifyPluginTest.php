<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Auth\Organization;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Shopify\Http\SettingsController;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\Feature\Plugins\Concerns\InteractsWithShopify;
use Tests\TestCase;

/**
 * The bundled Shopify plugin (plugins/shopify): order webhooks become
 * Inventoros orders, stock changes are pushed to Shopify, and the settings
 * page connects a store. Shopify itself is faked with Http::fake().
 */
final class ShopifyPluginTest extends TestCase
{
    use BuildsApiFixtures;
    use InteractsWithShopify;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
    }

    protected function tearDown(): void
    {
        $this->tearDownShopify();
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

    public function test_saving_settings_names_the_scopes_the_app_was_not_granted(): void
    {
        $this->connect();
        $this->fakeShopify(scopes: ['read_orders', 'write_products', 'read_inventory']);

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'test-store.myshopify.com',
            'client_id' => 'client-id',
        ])->assertSessionHas('error');

        $error = ShopifyConnection::forOrganization($this->org->id)->last_error;
        // write_products implies read_products; write_inventory and read_locations are missing.
        $this->assertStringContainsString('missing these access scopes: write_inventory, read_locations.', $error);
        $this->assertSame([], $this->callsTo('locations'), 'no field is queried before the scopes are known');
    }

    public function test_missing_optional_scopes_are_kept_for_the_settings_page(): void
    {
        $this->connect();
        $this->fakeShopify(locations: [['id' => self::LOCATION, 'name' => 'Main', 'isActive' => true]], scopes: ShopifyClient::REQUIRED_SCOPES);

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'test-store.myshopify.com',
            'client_id' => 'client-id',
        ])->assertSessionHas('success');

        $this->assertSame(ShopifyClient::OPTIONAL_SCOPES, ShopifyConnection::forOrganization($this->org->id)->missing_scopes);
    }

    public function test_saving_settings_always_gets_a_fresh_token(): void
    {
        // A token cached before the app was granted more scopes keeps the
        // old scopes for up to 24 hours.
        $this->connect(['access_token' => 'shpat_old', 'token_expires_at' => now()->addHours(20)]);
        $this->fakeShopify(locations: [['id' => self::LOCATION, 'name' => 'Main', 'isActive' => true]]);

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'test-store.myshopify.com',
            'client_id' => 'client-id',
        ])->assertSessionHas('success');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/admin/oauth/access_token'));
        Http::assertNotSent(fn (HttpRequest $r) => $r->hasHeader('X-Shopify-Access-Token', 'shpat_old'));
    }

    public function test_an_access_denied_answer_gets_a_new_token_and_retries(): void
    {
        $connection = $this->connect(['access_token' => 'shpat_old', 'token_expires_at' => now()->addHours(20)]);
        $this->fakeShopify(denyOnce: true);

        $info = (new ShopifyClient($connection))->graphql('query ShopInfo { shop { name } }');

        $this->assertSame('Test Store', $info['shop']['name']);
        $this->assertSame('shpat_token', $connection->fresh()->access_token);
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
        $this->assertSame(['ORDERS_CREATE', 'ORDERS_UPDATED', 'ORDERS_CANCELLED'], array_map(fn ($c) => $c['variables']['topic'], $created));
        $this->assertSame('https://inventory.example.com/webhooks/plugins/shopify/'.$connection->webhook_token, $created[0]['variables']['uri']);
        $this->assertNotNull($connection->fresh()->webhooks_registered_at);
    }
}
