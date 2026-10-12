<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ShipmentStatus;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductOption;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Order\Order;
use App\Models\Order\OrderPayment;
use App\Models\Order\ReturnOrder;
use App\Models\Shipping\Shipment;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inventoros\Plugins\Shopify\Jobs\RecoverOrders;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\Models\ShopifyLink;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\Feature\Plugins\Concerns\InteractsWithShopify;
use Tests\TestCase;

/**
 * The Shopify plugin beyond order creation and single-location stock:
 * order edits, fulfilments and refunds (reconciliation), several Shopify
 * locations fed by their own warehouses, recovery of missed orders and
 * webhooks, and copying the catalogue in both directions.
 */
final class ShopifySyncTest extends TestCase
{
    use BuildsApiFixtures;
    use InteractsWithShopify;
    use RefreshDatabase;

    private const LOCATION_2 = 'gid://shopify/Location/2';

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
    // Order reconciliation
    // ------------------------------------------------------------------

    public function test_an_order_edit_in_shopify_changes_the_lines_and_returns_stock(): void
    {
        [$connection, $product, $payload] = $this->importedOrder(quantity: 4);
        $this->assertSame(6, $product->fresh()->stock);

        $payload['line_items'][0]['current_quantity'] = 1;
        $payload['updated_at'] = '2026-10-01T11:00:00+00:00';
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $order = Order::query()->sole();
        $this->assertSame(1, (int) $order->items()->sole()->quantity);
        $this->assertSame('9.00', (string) $order->subtotal);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertStringContainsString('Items updated', ShopifyOrder::query()->sole()->message);
    }

    public function test_a_refund_that_cancels_items_before_shipping_reduces_the_order(): void
    {
        [$connection, $product, $payload] = $this->importedOrder(quantity: 4);
        $lineId = $payload['line_items'][0]['id'];

        $payload['line_items'][0]['current_quantity'] = 1;
        $payload['refunds'] = [['id' => 7001, 'refund_line_items' => [['line_item_id' => $lineId, 'quantity' => 3, 'restock_type' => 'cancel']]]];
        $payload['updated_at'] = '2026-10-01T11:00:00+00:00';
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $this->assertSame(1, (int) Order::query()->sole()->items()->sole()->quantity);
        $this->assertSame(9, $product->fresh()->stock);
        $this->assertSame(0, ReturnOrder::query()->count());
        $this->assertSame('no_return', ShopifyLink::query()->where('kind', ShopifyLink::REFUND)->sole()->state);
    }

    public function test_a_fulfilment_becomes_a_shipped_shipment_and_later_delivered(): void
    {
        [$connection, $product, $payload] = $this->importedOrder(quantity: 4);

        $payload['fulfillments'] = [$this->fulfillment(5001, $payload['line_items'][0]['id'], 4)];
        $payload['updated_at'] = '2026-10-01T11:00:00+00:00';
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $shipment = Shipment::query()->sole();
        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->status);
        $this->assertSame('1Z999', $shipment->tracking_number);
        $this->assertSame('UPS', $shipment->carrier_name);
        $this->assertSame(4, (int) $shipment->items()->sole()->quantity);
        $this->assertSame('shipped', Order::query()->sole()->status->value);
        $this->assertSame(6, $product->fresh()->stock, 'shipping does not move stock again');

        // The same state again changes nothing.
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();
        $this->assertSame(1, Shipment::query()->count());

        $payload['fulfillments'][0]['shipment_status'] = 'delivered';
        $payload['updated_at'] = '2026-10-02T09:00:00+00:00';
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $this->assertSame(ShipmentStatus::DELIVERED, $shipment->fresh()->status);
    }

    public function test_a_refund_with_return_restocks_the_goods_through_a_return(): void
    {
        [$connection, $product, $payload] = $this->importedOrder(quantity: 4);
        $lineId = $payload['line_items'][0]['id'];

        $payload['fulfillments'] = [$this->fulfillment(5001, $lineId, 4)];
        $payload['line_items'][0]['current_quantity'] = 2;
        $payload['refunds'] = [['id' => 7002, 'refund_line_items' => [['line_item_id' => $lineId, 'quantity' => 2, 'restock_type' => 'return']]]];
        $payload['updated_at'] = '2026-10-03T09:00:00+00:00';
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $return = ReturnOrder::query()->sole();
        $this->assertSame('completed', $return->status);
        $this->assertSame(2, (int) $return->items()->sole()->quantity);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(4, (int) Order::query()->sole()->items()->sole()->quantity, 'returned goods stay on the order');

        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();
        $this->assertSame(1, ReturnOrder::query()->count());
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_an_older_update_never_undoes_a_newer_one(): void
    {
        [$connection, $product, $payload] = $this->importedOrder(quantity: 4);

        $newer = $payload;
        $newer['line_items'][0]['current_quantity'] = 1;
        $newer['updated_at'] = '2026-10-01T12:00:00+00:00';
        $older = $payload;
        $older['updated_at'] = '2026-10-01T11:00:00+00:00';

        $this->postWebhook('orders/updated', $newer, $connection->webhook_token)->assertOk();
        $this->postWebhook('orders/updated', $older, $connection->webhook_token)->assertOk();

        $this->assertSame(1, (int) Order::query()->sole()->items()->sole()->quantity);
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_an_update_to_an_order_from_before_the_connection_does_not_import_it(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $connection = $this->connect(['push_inventory' => false, 'orders_since' => now()->subHour()]);
        $old = $this->orderPayload([$this->line('MUG', 1, '9.00')], ['created_at' => now()->subDays(5)->toIso8601String()]);

        $this->postWebhook('orders/updated', $old, $connection->webhook_token)->assertOk();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, ShopifyOrder::query()->count());
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_an_order_line_without_a_sku_matches_by_shopify_variant(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'SHOPIFY-777', 'stock' => 5]);
        $connection = $this->connect(['push_inventory' => false]);
        $line = $this->line('', 1, '80.00') + ['variant_id' => 777];

        $this->postWebhook('orders/create', $this->orderPayload([$line]), $connection->webhook_token)->assertOk();

        $this->assertSame($product->id, Order::query()->sole()->items()->sole()->product_id);
        $this->assertSame(4, $product->fresh()->stock);
    }

    public function test_an_order_line_whose_sku_changed_matches_by_shopify_variant(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'LOAFER-NEW', 'stock' => 5]);
        $connection = $this->connect(['push_inventory' => false]);
        ShopifyInventoryItem::query()->create(['organization_id' => $this->org->id, 'sku' => 'LOAFER-NEW', 'inventory_item_id' => 'gid://shopify/InventoryItem/88', 'variant_id' => 'gid://shopify/ProductVariant/88']);
        $line = $this->line('0087010042180610', 1, '80.00') + ['variant_id' => 88];

        $this->postWebhook('orders/create', $this->orderPayload([$line]), $connection->webhook_token)->assertOk();

        $this->assertSame($product->id, Order::query()->sole()->items()->sole()->product_id);
    }

    public function test_stock_is_pushed_for_a_variant_imported_without_a_sku(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'SHOPIFY-321', 'stock' => 5]);
        $this->connect();
        $this->fakeShopify(variants: [['sku' => '', 'id' => 'gid://shopify/ProductVariant/321', 'item' => 'gid://shopify/InventoryItem/321']]);

        StockAdjustment::adjust($product->fresh(), -2, 'manual', 'Sold', actor: $this->admin);

        $set = $this->callsTo('inventorySetQuantities');
        $this->assertSame('gid://shopify/InventoryItem/321', $set[0]['variables']['input']['quantities'][0]['inventoryItemId']);
        $this->assertSame(3, $set[0]['variables']['input']['quantities'][0]['quantity']);
    }

    public function test_a_paid_shopify_order_is_paid_in_inventoros(): void
    {
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $connection = $this->connect(['push_inventory' => false]);
        $payload = $this->orderPayload([$this->line('MUG', 2, '9.00')], ['financial_status' => 'paid', 'name' => '#1001']);

        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $order = Order::query()->sole();
        $this->assertSame('paid', $order->payment_status->value);
        $this->assertSame('18.00', (string) $order->amount_paid);
        $payment = OrderPayment::query()->sole();
        $this->assertSame('Shopify #1001', $payment->reference);
    }

    public function test_a_partly_paid_order_records_what_shopify_received(): void
    {
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $connection = $this->connect(['push_inventory' => false]);
        $payload = $this->orderPayload([$this->line('MUG', 5, '10.00')], ['financial_status' => 'partially_paid', 'total_price' => '50.00', 'total_outstanding' => '20.00']);

        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();

        $order = Order::query()->sole();
        $this->assertSame('30.00', (string) $order->amount_paid);
        $this->assertSame('partial', $order->payment_status->value);

        // Paid in full later: only the rest is recorded.
        $payload['financial_status'] = 'paid';
        $payload['updated_at'] = now()->addMinute()->toIso8601String();
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $this->assertSame('50.00', (string) $order->fresh()->amount_paid);
        $this->assertSame(2, OrderPayment::query()->count());
    }

    public function test_money_refunded_in_shopify_is_refunded_in_inventoros(): void
    {
        [$connection, , $payload] = $this->importedOrder(quantity: 2);
        $payload['financial_status'] = 'partially_refunded';
        $payload['refunds'] = [['id' => 7301, 'created_at' => now()->toIso8601String(), 'refund_line_items' => [], 'transactions' => [['kind' => 'refund', 'status' => 'success', 'amount' => '5.00']]]];
        $payload['updated_at'] = '2026-10-01T12:00:00+00:00';

        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();
        $this->postWebhook('orders/updated', $payload, $connection->webhook_token)->assertOk();

        $order = Order::query()->sole();
        $this->assertSame('13.00', (string) $order->amount_paid);
        $this->assertSame(1, OrderPayment::query()->where('type', 'refund')->count());
    }

    // ------------------------------------------------------------------
    // Several locations
    // ------------------------------------------------------------------

    public function test_each_mapped_location_gets_its_warehouses_stock(): void
    {
        [$north, $northBin] = $this->warehouse('North');
        [$south, $southBin] = $this->warehouse('South');
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 0, 'location_id' => $northBin->id]);
        StockAdjustment::adjust($product->fresh(), 5, 'manual', 'In', actor: $this->admin, locationId: $northBin->id);
        StockAdjustment::adjust($product->fresh(), 3, 'manual', 'In', actor: $this->admin, locationId: $southBin->id);

        $this->connect(['location_map' => [
            ['location_id' => self::LOCATION, 'warehouse_id' => $north->id],
            ['location_id' => self::LOCATION_2, 'warehouse_id' => $south->id],
        ]]);
        $this->fakeShopify(variants: [['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/77']]);

        StockAdjustment::adjust($product->fresh(), -1, 'manual', 'Broken', actor: $this->admin, locationId: $northBin->id);

        $quantities = collect($this->callsTo('inventorySetQuantities')[0]['variables']['input']['quantities'])->pluck('quantity', 'locationId')->all();
        $this->assertSame([self::LOCATION => 4, self::LOCATION_2 => 3], $quantities);
    }

    public function test_variant_stock_goes_to_the_primary_location_only(): void
    {
        [$north] = $this->warehouse('North');
        [$south] = $this->warehouse('South');
        [, $variant] = $this->makeVariantProduct('HOODIE-M', 6);
        $this->connect(['location_map' => [
            ['location_id' => self::LOCATION, 'warehouse_id' => $north->id],
            ['location_id' => self::LOCATION_2, 'warehouse_id' => $south->id],
        ]]);
        $this->fakeShopify(variants: [['sku' => 'HOODIE-M', 'item' => 'gid://shopify/InventoryItem/5']]);

        StockAdjustment::adjustVariant($variant, 1, 'manual', 'Count', actor: $this->admin);

        $quantities = $this->callsTo('inventorySetQuantities')[0]['variables']['input']['quantities'];
        $this->assertCount(1, $quantities);
        $this->assertSame(self::LOCATION, $quantities[0]['locationId']);
        $this->assertSame(7, $quantities[0]['quantity']);
    }

    public function test_an_order_ships_from_the_warehouse_of_its_assigned_location(): void
    {
        [$north, $northBin] = $this->warehouse('North');
        [$south, $southBin] = $this->warehouse('South');
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 0, 'location_id' => $northBin->id]);
        StockAdjustment::adjust($product->fresh(), 5, 'manual', 'In', actor: $this->admin, locationId: $northBin->id);
        StockAdjustment::adjust($product->fresh(), 5, 'manual', 'In', actor: $this->admin, locationId: $southBin->id);
        $connection = $this->connect(['push_inventory' => false, 'location_map' => [
            ['location_id' => self::LOCATION, 'warehouse_id' => $north->id],
            ['location_id' => self::LOCATION_2, 'warehouse_id' => $south->id],
        ]]);
        $this->fakeShopify(assigned: [self::LOCATION_2]);

        $this->postWebhook('orders/create', $this->orderPayload([$this->line('MUG', 2, '9.00')]), $connection->webhook_token)->assertOk();

        $this->assertSame($south->id, (int) Order::query()->sole()->warehouse_id);
        $this->assertSame(3, $this->binQuantity($product, $southBin));
        $this->assertSame(5, $this->binQuantity($product, $northBin));
    }

    public function test_settings_refuse_two_locations_sharing_a_warehouse(): void
    {
        [$north] = $this->warehouse('North');
        $this->connect();
        $this->fakeShopify(locations: [
            ['id' => self::LOCATION, 'name' => 'Shop', 'isActive' => true],
            ['id' => self::LOCATION_2, 'name' => 'Pop-up', 'isActive' => true],
        ]);

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'test-store.myshopify.com',
            'client_id' => 'client-id',
            'location_map' => [
                ['location_id' => self::LOCATION, 'warehouse_id' => $north->id],
                ['location_id' => self::LOCATION_2, 'warehouse_id' => $north->id],
            ],
        ])->assertSessionHasErrors('location_map');

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'test-store.myshopify.com',
            'client_id' => 'client-id',
            'location_map' => [
                ['location_id' => self::LOCATION, 'warehouse_id' => null],
                ['location_id' => self::LOCATION_2, 'warehouse_id' => $north->id],
            ],
        ])->assertSessionHasErrors('location_map');

        $this->actingAs($this->admin)->post(route('plg.shopify.save'), [
            'shop_domain' => 'test-store.myshopify.com',
            'client_id' => 'client-id',
            'location_map' => [['location_id' => self::LOCATION_2, 'warehouse_id' => $north->id]],
        ])->assertSessionHas('success');

        $connection = ShopifyConnection::forOrganization($this->org->id);
        $this->assertSame([['location_id' => self::LOCATION_2, 'warehouse_id' => $north->id]], $connection->locationMap());
        $this->assertSame(self::LOCATION_2, $connection->location_id);
    }

    // ------------------------------------------------------------------
    // Recovery
    // ------------------------------------------------------------------

    public function test_recovery_imports_a_missed_order_and_leaves_older_ones_alone(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->connect(['orders_since' => now()->subHour()]);
        $this->fakeShopify(orders: [
            $this->graphqlOrder(2001, now()->subMinutes(10), [['sku' => 'MUG', 'quantity' => 2]]),
            $this->graphqlOrder(2002, now()->subDays(2), [['sku' => 'MUG', 'quantity' => 1]]),
        ]);

        RecoverOrders::dispatch($this->org->id);

        $order = Order::query()->sole();
        $this->assertSame('2001', $order->external_id);
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(0, ShopifyOrder::query()->where('shopify_order_id', '2002')->count());

        $summary = ShopifyConnection::forOrganization($this->org->id)->orders_sync_summary;
        $this->assertSame(1, $summary['imported']);
        $this->assertSame(1, $summary['ignored']);
        $this->assertNotNull(ShopifyConnection::forOrganization($this->org->id)->orders_synced_at);
    }

    public function test_a_manual_recovery_from_a_date_imports_older_orders(): void
    {
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->connect(['orders_since' => now()->subHour()]);
        $this->fakeShopify(orders: [$this->graphqlOrder(2002, now()->subDays(2), [['sku' => 'MUG', 'quantity' => 1]])]);

        $this->actingAs($this->admin)
            ->post(route('plg.shopify.recover'), ['from' => now()->subDays(3)->toDateString()])
            ->assertSessionHas('success');

        $this->assertSame('2002', Order::query()->sole()->external_id);
    }

    public function test_orders_placed_before_the_stock_was_taken_are_imported_as_history(): void
    {
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->connect(['orders_since' => now()->subHour()]);
        $old = $this->graphqlOrder(2004, now()->subDays(3), [['sku' => 'MUG', 'quantity' => 4, 'id' => 901]]);
        $old['fulfillments'] = [[
            'id' => 'gid://shopify/Fulfillment/5101',
            'status' => 'SUCCESS',
            'displayStatus' => 'DELIVERED',
            'createdAt' => now()->subDays(2)->toIso8601String(),
            'location' => null,
            'trackingInfo' => [],
            'fulfillmentLineItems' => ['nodes' => [['quantity' => 4, 'lineItem' => ['id' => 'gid://shopify/LineItem/901', 'sku' => 'MUG']]], 'pageInfo' => ['hasNextPage' => false]],
        ]];
        $old['refunds'] = [[
            'id' => 'gid://shopify/Refund/7101',
            'createdAt' => now()->subDays(1)->toIso8601String(),
            'refundLineItems' => ['nodes' => [['quantity' => 1, 'restockType' => 'RETURN', 'lineItem' => ['id' => 'gid://shopify/LineItem/901', 'sku' => 'MUG']]], 'pageInfo' => ['hasNextPage' => false]],
        ]];
        $this->fakeShopify(orders: [$old]);

        $this->actingAs($this->admin)
            ->post(route('plg.shopify.recover'), ['from' => now()->subDays(7)->toDateString()])
            ->assertSessionHas('success');

        $order = Order::query()->sole();
        $this->assertSame('2004', $order->external_id);
        $this->assertFalse((bool) $order->stock_committed);
        $this->assertSame(10, $product->fresh()->stock, 'the stock taken from Shopify already counts this order and its return');
        $this->assertSame(ShipmentStatus::DELIVERED, Shipment::query()->sole()->status);
        $this->assertSame(0, ReturnOrder::query()->count());
        $this->assertSame('history', ShopifyLink::query()->where('kind', ShopifyLink::REFUND)->sole()->state);
        $this->assertStringContainsString('Imported as history', ShopifyOrder::query()->sole()->message);
    }

    public function test_recovering_older_than_60_days_needs_read_all_orders(): void
    {
        $connection = $this->connect(['missing_scopes' => ['read_all_orders']]);
        $this->actingAs($this->admin)
            ->post(route('plg.shopify.recover'), ['from' => now()->subDays(120)->toDateString()])
            ->assertSessionHasErrors('from');

        $connection->forceFill(['missing_scopes' => []])->save();
        $this->actingAs($this->admin)
            ->post(route('plg.shopify.recover'), ['from' => now()->subDays(120)->toDateString()])
            ->assertSessionHas('success');
    }

    public function test_recovery_applies_a_fulfilment_whose_webhook_was_missed(): void
    {
        [$connection, , $payload] = $this->importedOrder(quantity: 2);
        $node = $this->graphqlOrder((int) $payload['id'], now()->subMinutes(30), [['sku' => 'MUG', 'quantity' => 2, 'id' => $payload['line_items'][0]['id']]]);
        $node['fulfillments'] = [[
            'id' => 'gid://shopify/Fulfillment/5001',
            'status' => 'SUCCESS',
            'displayStatus' => 'IN_TRANSIT',
            'createdAt' => now()->subMinutes(5)->toIso8601String(),
            'location' => null,
            'trackingInfo' => [['company' => 'DHL', 'number' => 'JD01', 'url' => null]],
            'fulfillmentLineItems' => ['nodes' => [['quantity' => 2, 'lineItem' => ['id' => 'gid://shopify/LineItem/'.$payload['line_items'][0]['id'], 'sku' => 'MUG']]]],
        ]];
        $this->fakeShopify(orders: [$node]);

        RecoverOrders::dispatch($connection->organization_id);

        $this->assertSame('JD01', Shipment::query()->sole()->tracking_number);
        $this->assertSame(1, ShopifyConnection::forOrganization($this->org->id)->orders_sync_summary['updated']);
    }

    public function test_recovery_does_not_import_an_order_with_more_lines_than_one_page(): void
    {
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->connect(['orders_since' => now()->subHour()]);
        $node = $this->graphqlOrder(2003, now()->subMinutes(5), [['sku' => 'MUG', 'quantity' => 1]]);
        $node['lineItems']['pageInfo'] = ['hasNextPage' => true];
        $this->fakeShopify(orders: [$node]);

        RecoverOrders::dispatch($this->org->id);

        $this->assertSame(0, Order::query()->count());
        $record = ShopifyOrder::query()->sole();
        $this->assertSame(ShopifyOrder::FAILED, $record->status);
        $this->assertStringContainsString('Enter it by hand', $record->message);
    }

    public function test_recovery_registers_missing_webhooks_again(): void
    {
        config(['app.url' => 'https://inventory.example.com']);
        $connection = $this->connect(['webhooks_registered_at' => now()->subDay()]);
        $uri = 'https://inventory.example.com/webhooks/plugins/shopify/'.$connection->webhook_token;
        $this->fakeShopify(webhooks: [['topic' => 'ORDERS_CREATE', 'uri' => $uri]]);

        RecoverOrders::dispatch($connection->organization_id);

        $created = array_map(fn ($c) => $c['variables']['topic'], $this->callsTo('webhookSubscriptionCreate'));
        $this->assertSame(['ORDERS_UPDATED', 'ORDERS_CANCELLED'], $created);
        $this->assertSame(['ORDERS_UPDATED', 'ORDERS_CANCELLED'], ShopifyConnection::forOrganization($this->org->id)->orders_sync_summary['webhooks_repaired']);
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    public function test_catalogue_import_creates_products_and_variants_with_opening_stock(): void
    {
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->connect();
        $this->fakeShopify(products: [
            $this->graphqlProduct(1, 'Beeswax candle', 'beeswax-candle', [], [['sku' => 'CANDLE', 'price' => '12.00', 'available' => 7]]),
            $this->graphqlProduct(2, 'Classic hoodie', 'classic-hoodie', [['name' => 'Size', 'values' => ['S', 'M']]], [
                ['sku' => 'HOODIE-S', 'price' => '45.00', 'available' => 2, 'options' => ['Size' => 'S']],
                ['sku' => 'HOODIE-M', 'price' => '49.00', 'available' => 3, 'options' => ['Size' => 'M']],
            ]),
            $this->graphqlProduct(3, 'Mug', 'mug', [], [['sku' => 'MUG', 'price' => '9.00', 'available' => 1]]),
            $this->graphqlProduct(4, 'Gift card', 'gift-card', [], [['sku' => '', 'price' => '25.00', 'available' => 0]]),
        ]);

        $this->actingAs($this->admin)->post(route('plg.shopify.catalogue.import'), ['with_stock' => true])->assertSessionHas('success');

        $candle = Product::query()->where('sku', 'CANDLE')->sole();
        $this->assertSame('Beeswax candle', $candle->name);
        $this->assertSame('12.00', (string) $candle->price);
        $this->assertSame(7, $candle->stock);

        $hoodie = Product::query()->where('sku', 'CLASSIC-HOODIE')->sole();
        $this->assertTrue((bool) $hoodie->has_variants);
        $this->assertSame(['S', 'M'], $hoodie->options()->sole()->values);
        $this->assertSame(2, ProductVariant::query()->where('sku', 'HOODIE-S')->sole()->stock);
        $this->assertSame(3, ProductVariant::query()->where('sku', 'HOODIE-M')->sole()->stock);
        $this->assertSame(['Size' => 'M'], ProductVariant::query()->where('sku', 'HOODIE-M')->sole()->option_values);

        $this->assertSame(10, Product::query()->where('sku', 'MUG')->sole()->stock, 'existing products are not touched');
        $this->assertSame([], $this->callsTo('inventorySetQuantities'), 'Shopify already has these quantities');
        $this->assertSame(4, ShopifyInventoryItem::query()->count());

        $summary = ShopifyConnection::forOrganization($this->org->id)->catalogue_import_summary;
        // The gift card has no SKU: imported as SHOPIFY-<variant id>.
        $this->assertSame([3, 4, 1, 0, 1, 0], [$summary['products'], $summary['variants'], $summary['existing'], $summary['no_sku'], $summary['generated_skus'], $summary['failed']]);
        $this->assertSame('Gift card', Product::query()->where('sku', 'SHOPIFY-40')->sole()->name);
    }

    public function test_a_catalogue_import_with_stock_makes_earlier_orders_history(): void
    {
        $this->connect(['orders_since' => now()->subDays(2)]);
        $this->fakeShopify(products: [$this->graphqlProduct(1, 'Candle', 'candle', [], [['sku' => 'CANDLE', 'price' => '12.00', 'available' => 7]])]);

        $this->actingAs($this->admin)->post(route('plg.shopify.catalogue.import'), ['with_stock' => true]);

        $this->assertTrue(ShopifyConnection::forOrganization($this->org->id)->orders_since->gt(now()->subMinute()));
    }

    public function test_importing_again_adds_the_variants_a_product_was_missing(): void
    {
        // Imported before with only the variant that had a SKU.
        [$hoodie, $small] = $this->makeVariantProduct('HOODIE-S', 2);
        ProductOption::query()->create(['product_id' => $hoodie->id, 'name' => 'Size', 'values' => ['S'], 'position' => 0]);
        $this->connect();
        ShopifyInventoryItem::query()->create(['organization_id' => $this->org->id, 'sku' => 'HOODIE-S', 'inventory_item_id' => 'gid://shopify/InventoryItem/20', 'variant_id' => 'gid://shopify/ProductVariant/20']);
        $this->fakeShopify(products: [
            $this->graphqlProduct(2, 'Classic hoodie', 'classic-hoodie', [['name' => 'Size', 'values' => ['S', 'M']]], [
                ['sku' => 'HOODIE-S', 'price' => '45.00', 'available' => 2, 'options' => ['Size' => 'S']],
                ['sku' => '', 'price' => '49.00', 'available' => 3, 'options' => ['Size' => 'M']],
            ]),
        ]);

        $this->actingAs($this->admin)->post(route('plg.shopify.catalogue.import'), ['with_stock' => true]);

        $added = ProductVariant::query()->where('sku', 'SHOPIFY-21')->sole();
        $this->assertSame($hoodie->id, $added->product_id);
        $this->assertSame(['Size' => 'M'], $added->option_values);
        $this->assertSame(3, $added->stock);
        $this->assertSame(['S', 'M'], $hoodie->options()->sole()->values);
        $this->assertSame(2, $small->fresh()->stock, 'existing variants are not touched');

        $summary = ShopifyConnection::forOrganization($this->org->id)->catalogue_import_summary;
        $this->assertSame([0, 1, 1, 1], [$summary['products'], $summary['extended'], $summary['variants_added'], $summary['generated_skus']]);
    }

    public function test_a_sku_shared_by_two_shopify_products_gets_its_own_sku_in_inventoros(): void
    {
        $this->makeProduct($this->org, ['sku' => 'LOAFER-42', 'stock' => 1]);
        $this->connect();
        ShopifyInventoryItem::query()->create(['organization_id' => $this->org->id, 'sku' => 'LOAFER-42', 'inventory_item_id' => 'gid://shopify/InventoryItem/10', 'variant_id' => 'gid://shopify/ProductVariant/10']);
        $this->fakeShopify(products: [$this->graphqlProduct(5, 'Loafer (old listing)', 'loafer-old', [], [['sku' => 'LOAFER-42', 'price' => '99.00', 'available' => 4]])]);

        $this->actingAs($this->admin)->post(route('plg.shopify.catalogue.import'), ['with_stock' => true]);

        $this->assertSame('Loafer (old listing)', Product::query()->where('sku', 'SHOPIFY-50')->sole()->name);
        $this->assertSame(1, Product::query()->where('sku', 'LOAFER-42')->sole()->stock);
    }

    public function test_pages_over_shopifys_cost_limit_are_asked_for_again_smaller(): void
    {
        $this->connect();
        $this->fakeShopify(maxFirst: 2, products: [
            $this->graphqlProduct(1, 'Candle', 'candle', [], [['sku' => 'CANDLE', 'price' => '12.00', 'available' => 7]]),
            $this->graphqlProduct(2, 'Soap', 'soap', [], [['sku' => 'SOAP', 'price' => '5.00', 'available' => 3]]),
        ]);

        $this->actingAs($this->admin)->post(route('plg.shopify.catalogue.import'), ['with_stock' => true])->assertSessionHas('success');

        $this->assertSame(7, Product::query()->where('sku', 'CANDLE')->sole()->stock);
        $this->assertSame(3, Product::query()->where('sku', 'SOAP')->sole()->stock);
        $this->assertNull(ShopifyConnection::forOrganization($this->org->id)->last_error);

        $sizes = array_map(fn ($c) => $c['variables']['first'], $this->callsTo('products'));
        $this->assertSame(10, $sizes[0]);
        $this->assertSame(2, end($sizes), 'shrunk until Shopify accepted it');
    }

    public function test_catalogue_publish_creates_the_missing_products_in_shopify(): void
    {
        $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $this->makeProduct($this->org, ['sku' => 'LAMP', 'name' => 'Desk lamp', 'price' => 30.00, 'stock' => 5, 'barcode' => '4006381333931']);
        [$hoodie] = $this->makeVariantProduct('HOODIE-M', 6);
        ProductOption::query()->create(['product_id' => $hoodie->id, 'name' => 'Size', 'values' => ['M'], 'position' => 0]);
        $this->connect();
        $this->fakeShopify(variants: [['sku' => 'MUG', 'item' => 'gid://shopify/InventoryItem/1']]);

        $this->actingAs($this->admin)->post(route('plg.shopify.catalogue.publish'), ['status' => 'draft'])->assertSessionHas('success');

        $created = collect($this->callsTo('productSet'))->map(fn ($c) => $c['variables']['input'])->keyBy('title');
        $this->assertCount(2, $created);

        $lamp = $created['Desk lamp'];
        $this->assertSame('DRAFT', $lamp['status']);
        $this->assertSame([['name' => 'Title', 'values' => [['name' => 'Default Title']]]], $lamp['productOptions']);
        $this->assertSame('LAMP', $lamp['variants'][0]['sku']);
        $this->assertSame('30.00', $lamp['variants'][0]['price']);
        $this->assertSame('4006381333931', $lamp['variants'][0]['barcode']);
        $this->assertSame([['locationId' => self::LOCATION, 'name' => 'available', 'quantity' => 5]], $lamp['variants'][0]['inventoryQuantities']);

        $hoodieInput = $created[$hoodie->name];
        $this->assertSame([['name' => 'Size', 'values' => [['name' => 'M']]]], $hoodieInput['productOptions']);
        $this->assertSame([['optionName' => 'Size', 'name' => 'M']], $hoodieInput['variants'][0]['optionValues']);
        $this->assertSame(6, $hoodieInput['variants'][0]['inventoryQuantities'][0]['quantity']);

        $summary = ShopifyConnection::forOrganization($this->org->id)->catalogue_publish_summary;
        $this->assertSame([2, 1, 0], [$summary['products'], $summary['already'], $summary['failed']]);
        $this->assertNotNull(ShopifyInventoryItem::query()->where('sku', 'LAMP')->first(), 'new items are cached for stock pushes');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A Shopify order for $quantity MUGs, imported, with stock pushes off.
     *
     * @return array{0: ShopifyConnection, 1: Product, 2: array<string, mixed>}
     */
    private function importedOrder(int $quantity): array
    {
        $product = $this->makeProduct($this->org, ['sku' => 'MUG', 'stock' => 10]);
        $connection = $this->connect(['push_inventory' => false]);
        $payload = $this->orderPayload([$this->line('MUG', $quantity, '9.00')], ['updated_at' => '2026-10-01T10:00:00+00:00']);

        $this->postWebhook('orders/create', $payload, $connection->webhook_token)->assertOk();
        $this->assertSame(ShopifyOrder::IMPORTED, ShopifyOrder::query()->sole()->status);

        return [$connection, $product, $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function fulfillment(int $id, int|string $lineId, int $quantity): array
    {
        return [
            'id' => $id,
            'status' => 'success',
            'shipment_status' => 'in_transit',
            'created_at' => '2026-10-01T10:30:00+00:00',
            'location_id' => null,
            'tracking_company' => 'UPS',
            'tracking_number' => '1Z999',
            'tracking_url' => null,
            'line_items' => [['id' => $lineId, 'sku' => 'MUG', 'quantity' => $quantity]],
        ];
    }

    /**
     * @return array{0: Warehouse, 1: ProductLocation}
     */
    private function warehouse(string $name): array
    {
        $warehouse = Warehouse::query()->create(['organization_id' => $this->org->id, 'name' => $name, 'code' => strtoupper($name), 'is_active' => true]);
        $bin = ProductLocation::query()->create(['organization_id' => $this->org->id, 'name' => $name.' bin', 'code' => strtoupper($name).'-1', 'is_active' => true, 'warehouse_id' => $warehouse->id]);

        return [$warehouse, $bin];
    }

    private function binQuantity(Product $product, ProductLocation $bin): int
    {
        return (int) ProductLocationStock::query()->where('product_id', $product->id)->where('location_id', $bin->id)->value('quantity');
    }

    /**
     * @param  array<int, array{sku: string, quantity: int, id?: int|string}>  $lines
     * @return array<string, mixed>
     */
    private function graphqlOrder(int $id, \DateTimeInterface $createdAt, array $lines): array
    {
        static $lineId = 500;

        return [
            'id' => 'gid://shopify/Order/'.$id,
            'legacyResourceId' => (string) $id,
            'name' => '#'.$id,
            'email' => 'ada@example.com',
            'note' => null,
            'createdAt' => $createdAt->format(DATE_ATOM),
            'processedAt' => $createdAt->format(DATE_ATOM),
            'updatedAt' => now()->subMinute()->toIso8601String(),
            'cancelledAt' => null,
            'currencyCode' => 'USD',
            'displayFinancialStatus' => 'PAID',
            'shippingAddress' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'company' => null, 'address1' => '1 Analytical St', 'address2' => null, 'city' => 'London', 'zip' => 'N1', 'provinceCode' => null, 'country' => 'United Kingdom', 'phone' => null],
            'billingAddress' => null,
            'lineItems' => ['nodes' => array_map(fn ($l) => [
                'id' => 'gid://shopify/LineItem/'.($l['id'] ?? $lineId++),
                'sku' => $l['sku'],
                'name' => $l['sku'],
                'quantity' => $l['quantity'],
                'currentQuantity' => $l['quantity'],
                'originalUnitPriceSet' => ['shopMoney' => ['amount' => '9.00']],
                'discountAllocations' => [],
                'taxLines' => [],
            ], $lines)],
            'shippingLines' => ['nodes' => []],
            'refunds' => [],
            'fulfillments' => [],
        ];
    }

    /**
     * @param  array<int, array{name: string, values: array<int, string>}>  $options
     * @param  array<int, array{sku: string, price: string, available: int, options?: array<string, string>}>  $variants
     * @return array<string, mixed>
     */
    private function graphqlProduct(int $id, string $title, string $handle, array $options, array $variants): array
    {
        return [
            'id' => 'gid://shopify/Product/'.$id,
            'legacyResourceId' => (string) $id,
            'title' => $title,
            'handle' => $handle,
            'descriptionHtml' => '<p>'.$title.'</p>',
            'status' => 'ACTIVE',
            'vendor' => 'Test',
            'productType' => '',
            'options' => $options === [] ? [['name' => 'Title', 'values' => ['Default Title']]] : $options,
            'variants' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => array_map(fn ($v, $i) => [
                'id' => 'gid://shopify/ProductVariant/'.$id.$i,
                'sku' => $v['sku'],
                'barcode' => null,
                'price' => $v['price'],
                'selectedOptions' => array_map(fn ($name, $value) => ['name' => $name, 'value' => $value], array_keys($v['options'] ?? ['Title' => 'Default Title']), $v['options'] ?? ['Title' => 'Default Title']),
                'inventoryItem' => [
                    'id' => 'gid://shopify/InventoryItem/'.$id.$i,
                    'tracked' => true,
                    'unitCost' => ['amount' => '4.00'],
                    // One aliased inventoryLevel per mapped location (see ShopifyClient::products()).
                    'l0' => ['location' => ['id' => self::LOCATION], 'quantities' => [['name' => 'available', 'quantity' => $v['available']]]],
                ],
            ], $variants, array_keys($variants))],
        ];
    }
}
