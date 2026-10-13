<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockOwner;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockOperation;
use App\Models\Inventory\VariantStockTransfer;
use App\Models\Order\Order;
use App\Models\Order\OrderItemAllocation;
use App\Models\Shipping\Shipment;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStockService;
use App\Services\Inventory\VariantTransferService;
use App\Services\OrderService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryLevel;
use Inventoros\Plugins\Shopify\Models\ShopifyLink;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Inventoros\Plugins\Shopify\Models\ShopifyStockIssue;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\Models\ShopifyWebhookEvent;
use Inventoros\Plugins\Shopify\ShopifyException;
use Inventoros\Plugins\Shopify\Stock\OutboxProcessor;
use Inventoros\Plugins\Shopify\Stock\RoutingCheck;
use Inventoros\Plugins\Shopify\Stock\StockBootstrap;
use Inventoros\Plugins\Shopify\Stock\TransferPlanner;
use Tests\Feature\Api\Concerns\BuildsApiFixtures;
use Tests\Feature\Plugins\Concerns\FakesShopifyInventory;
use Tests\Feature\Plugins\Concerns\InteractsWithShopify;
use Tests\TestCase;

/**
 * Two warehouses kept per variant and synced both ways with two Shopify
 * locations: set-up from Shopify, changes from either side, conflicts,
 * consolidation, returns, failures and repeated deliveries.
 */
final class ShopifyTwoWarehouseSyncTest extends TestCase
{
    use BuildsApiFixtures;
    use FakesShopifyInventory;
    use InteractsWithShopify;
    use RefreshDatabase;

    private const MC2 = 'gid://shopify/Location/117678637382';

    private const SAX = 'gid://shopify/Location/14558855261';

    private const I42 = 'gid://shopify/InventoryItem/4200';

    private const I43 = 'gid://shopify/InventoryItem/4300';

    private ShopifyConnection $connection;

    private Product $shoe;

    private ProductVariant $size42;

    private ProductVariant $size43;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        $this->fakeShopify(locations: [
            ['id' => self::MC2, 'name' => 'MC2 Lab Srl', 'isActive' => true],
            ['id' => self::SAX, 'name' => 'VIA MARAGLIANO 7/1', 'isActive' => true],
        ]);
        $this->fakeInventory();

        $this->shoe = $this->makeProduct($this->org, ['sku' => 'SUNDAY', 'stock' => 0, 'has_variants' => true]);
        $this->size42 = $this->variant('SUN-42', 9);
        $this->size43 = $this->variant('SUN-43', 0);

        $this->connection = $this->connect([
            'locations' => [['id' => self::MC2, 'name' => 'MC2 Lab Srl'], ['id' => self::SAX, 'name' => 'VIA MARAGLIANO 7/1']],
            'location_map' => [['location_id' => self::SAX, 'warehouse_id' => null]],
        ]);

        $this->stockItem(self::I42, 'SUN-42', [self::MC2 => ['available' => 2], self::SAX => ['available' => 3]]);
        $this->stockItem(self::I43, 'SUN-43', [self::MC2 => ['available' => 0], self::SAX => ['available' => 1]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownShopify();
        parent::tearDown();
    }

    private function variant(string $sku, int $stock): ProductVariant
    {
        return ProductVariant::query()->create([
            'organization_id' => $this->org->id, 'product_id' => $this->shoe->id, 'sku' => $sku, 'title' => $sku,
            'option_values' => ['Size' => substr($sku, -2)], 'price' => 159.00, 'stock' => $stock, 'is_active' => true,
        ]);
    }

    private function setUpAndActivate(): void
    {
        (new StockBootstrap($this->connection->fresh()))->run(true);
        (new StockBootstrap($this->connection->fresh()))->activate();
        $this->connection = $this->connection->fresh();
        $this->actingAs($this->admin);
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', $code)->firstOrFail();
    }

    /**
     * @return array<string, int>
     */
    private function local(ProductVariant $variant, string $code): array
    {
        return app(VariantStockService::class)->totalsByWarehouse($variant)[$this->warehouse($code)->id]
            ?? ['on_hand' => 0, 'committed' => 0, 'available' => 0, 'quality_control' => 0];
    }

    private function inventoryWebhook(string $item, string $location, string $id): void
    {
        $this->postWebhook('inventory_levels/update', [
            'inventory_item_id' => (int) substr($item, strrpos($item, '/') + 1),
            'location_id' => (int) substr($location, strrpos($location, '/') + 1),
            'available' => $this->qty($item, $location, 'available'),
            'updated_at' => now()->toIso8601String(),
        ], $this->connection->webhook_token, $id)->assertOk();
    }

    // ------------------------------------------------------------------
    // Set-up
    // ------------------------------------------------------------------

    public function test_a_dry_run_reads_and_compares_without_writing_anything(): void
    {
        $summary = (new StockBootstrap($this->connection))->run(false);

        $this->assertSame(0, Warehouse::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
        $this->assertSame(0, VariantStockLevel::count());
        $this->assertSame([], $this->applied);
        $this->assertFileExists($summary['comparison']['file']);
    }

    public function test_set_up_takes_each_location_from_shopify_and_does_not_take_open_orders_again(): void
    {
        // An order Shopify has open at Via Maragliano, already in Inventoros
        // from before (the old single-number stock took it then).
        $order = app(OrderService::class)->create([
            'customer_name' => 'Cliente', 'external_id' => '5001', 'external_reference' => '#5001',
            'items' => [['product_id' => $this->shoe->id, 'product_variant_id' => $this->size43->id, 'quantity' => 1, 'unit_price' => 159]],
        ], $this->admin, 'shopify', adjustStock: false);
        ShopifyOrder::query()->create([
            'organization_id' => $this->org->id, 'shopify_order_id' => '5001', 'order_id' => $order->id, 'status' => 'imported', 'payload' => '{}',
        ]);
        $this->shopifySale(self::I43, self::SAX, 1);
        $this->stockItem(self::I43, 'SUN-43', [self::MC2 => ['available' => 0], self::SAX => ['available' => 0, 'committed' => 1]]);
        $this->fulfilmentOrders('5001', [['location' => self::SAX, 'lines' => [['item' => self::I43, 'qty' => 1, 'line_item_id' => '1']]]]);

        $summary = (new StockBootstrap($this->connection))->run(true);

        // Two distinct balances for the same variant, total 5; the old
        // aggregate (9) is not used.
        $this->assertSame(2, $this->local($this->size42, 'MC2')['on_hand']);
        $this->assertSame(3, $this->local($this->size42, 'SAX')['on_hand']);
        $this->assertSame(5, $this->size42->fresh()->stock);

        // The open order: on hand as Shopify has it (1), committed once.
        $sax43 = $this->local($this->size43, 'SAX');
        $this->assertSame([1, 1, 0], [$sax43['on_hand'], $sax43['committed'], $sax43['available']]);
        $this->assertSame(1, (int) OrderItemAllocation::where('order_id', $order->id)->where('warehouse_id', $this->warehouse('SAX')->id)->sum('quantity'));

        // Owners unknown: nothing invented.
        $this->assertSame(0, VariantStockLevel::whereNotNull('stock_owner_id')->count());
        $this->assertSame(2, StockOwner::where('organization_id', $this->org->id)->count());

        // Nothing written to Shopify; sync waits for approval.
        $this->assertSame([], $this->applied);
        $connection = $this->connection->fresh();
        $this->assertSame(ShopifyConnection::STOCK_PAUSED, $connection->stock_sync_mode);
        $this->assertSame([self::MC2, self::SAX], array_column($connection->locationMap(), 'location_id'));
        $this->assertSame(4, $summary['comparison']['match']);
        $this->assertSame(0, $summary['comparison']['differ']);

        // Repeatable: a second run changes nothing.
        (new StockBootstrap($connection))->run(true);
        $this->assertSame(5, $this->size42->fresh()->stock);
        $this->assertSame(1, (int) OrderItemAllocation::where('order_id', $order->id)->selectRaw('sum(quantity - released_quantity) as q')->value('q'));
    }

    // ------------------------------------------------------------------
    // Shopify -> Inventoros
    // ------------------------------------------------------------------

    public function test_a_change_in_shopify_updates_inventoros_without_sending_it_back(): void
    {
        $this->setUpAndActivate();

        $this->shopifyAdjust(self::I42, self::MC2, 'available', 2);
        $this->inventoryWebhook(self::I42, self::MC2, 'w-1');

        $this->assertSame(4, $this->local($this->size42, 'MC2')['on_hand']);
        $this->assertSame([], $this->appliedTo('inventoryAdjustQuantities'));
        $operation = VariantStockOperation::where('type', VariantStockOperation::TYPE_CHANNEL_SYNC)->sole();
        $this->assertSame(VariantStockOperation::SOURCE_EXTERNAL, $operation->source);
        // Units from Shopify have no owner yet.
        $this->assertNull($operation->movements->first()->stock_owner_id);
        $this->assertSame(ShopifyInventoryLevel::SYNCED, ShopifyInventoryLevel::where('inventory_item_id', self::I42)->where('location_id', self::MC2)->value('sync_status'));
    }

    public function test_a_repeated_delivery_is_dropped_and_events_are_kept(): void
    {
        $this->setUpAndActivate();

        $this->shopifyAdjust(self::I42, self::MC2, 'available', 1);
        $this->inventoryWebhook(self::I42, self::MC2, 'same-id');
        $this->postWebhook('inventory_levels/update', ['inventory_item_id' => 4200, 'location_id' => 117678637382], $this->connection->webhook_token, 'same-id')
            ->assertOk()->assertSeeText('Duplicate.');

        $this->assertSame(3, $this->local($this->size42, 'MC2')['on_hand']);
        $this->assertSame(1, ShopifyWebhookEvent::where('webhook_id', 'same-id')->count());
    }

    public function test_a_webhook_from_another_store_or_badly_signed_is_refused(): void
    {
        $this->setUpAndActivate();

        $body = json_encode(['inventory_item_id' => 4200, 'location_id' => 117678637382]);
        $this->call('POST', route('plg.shopify.webhooks.receive', ['token' => $this->connection->webhook_token]), [], [], [], [
            'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, self::SECRET, true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'someone-else.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->call('POST', route('plg.shopify.webhooks.receive', ['token' => $this->connection->webhook_token]), [], [], [], [
            'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'forged',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertSame(0, ShopifyWebhookEvent::count());
    }

    // ------------------------------------------------------------------
    // Inventoros -> Shopify
    // ------------------------------------------------------------------

    public function test_a_change_in_inventoros_reaches_shopify_once_even_with_retries_and_echoes(): void
    {
        $this->setUpAndActivate();

        // Shopify applies the change but its answer is lost (a timeout).
        $this->loseNextAnswer = true;
        app(VariantStockService::class)->adjust($this->size42, $this->warehouse('MC2')->id, null, 3, ['reason' => 'Delivery']);

        $operation = ShopifyStockOperation::sole();
        $this->assertSame(ShopifyStockOperation::PENDING, $operation->status, 'Not shown as confirmed without an answer.');
        $this->assertSame(5, $this->qty(self::I42, self::MC2, 'available'));

        // The retry sends the same request with the same key: Shopify
        // recognises it and applies nothing new.
        $operation->forceFill(['next_attempt_at' => null])->save();
        (new OutboxProcessor($this->connection->fresh()))->run();
        $this->assertSame(ShopifyStockOperation::CONFIRMED, $operation->fresh()->status);
        $this->assertSame(5, $this->qty(self::I42, self::MC2, 'available'));
        $this->assertCount(1, $this->appliedTo('inventoryAdjustQuantities'));

        // Shopify's webhook for our own change, twice: recognised, not applied.
        $this->inventoryWebhook(self::I42, self::MC2, 'echo-1');
        $this->inventoryWebhook(self::I42, self::MC2, 'echo-2');
        $this->assertSame(5, $this->local($this->size42, 'MC2')['on_hand']);
        $this->assertSame(0, VariantStockOperation::where('type', VariantStockOperation::TYPE_CHANNEL_SYNC)->count());
        $this->assertSame(ShopifyInventoryLevel::SYNCED, ShopifyInventoryLevel::where('inventory_item_id', self::I42)->where('location_id', self::MC2)->value('sync_status'));
    }

    public function test_the_old_push_of_totals_never_runs_once_per_warehouse(): void
    {
        $this->setUpAndActivate();

        app(VariantStockService::class)->adjust($this->size42, $this->warehouse('SAX')->id, null, 1);

        // No inventorySetQuantities of the total to the first location.
        $this->assertSame([], $this->callsTo('inventorySetQuantities'));
        $this->assertSame(4, $this->qty(self::I42, self::SAX, 'available'));
        $this->assertSame(2, $this->qty(self::I42, self::MC2, 'available'));
    }

    // ------------------------------------------------------------------
    // Concurrency
    // ------------------------------------------------------------------

    public function test_a_count_does_not_overwrite_a_sale_shipped_meanwhile_it_becomes_a_conflict(): void
    {
        $this->setUpAndActivate();

        // The count is taken, but Shopify is briefly unavailable...
        $this->failNext = 1;
        app(VariantStockService::class)->setCount($this->size42, $this->warehouse('MC2')->id, null, 1, ['reason' => 'Count']);
        $operation = ShopifyStockOperation::sole();
        $this->assertSame(ShopifyStockOperation::PENDING, $operation->status);
        $this->assertSame(ShopifyInventoryLevel::PENDING, ShopifyInventoryLevel::where('inventory_item_id', self::I42)->where('location_id', self::MC2)->value('sync_status'));

        // ...and meanwhile a pair is sold and shipped in Shopify.
        $this->shopifySale(self::I42, self::MC2, 1);
        $this->shopifyFulfil(self::I42, self::MC2, 1);

        $operation->forceFill(['next_attempt_at' => null])->save();
        (new OutboxProcessor($this->connection->fresh()))->run();

        $this->assertSame(ShopifyStockOperation::CONFLICT, $operation->fresh()->status);
        // Shopify keeps its figure: the sale is not overwritten.
        $this->assertSame(1, $this->qty(self::I42, self::MC2, 'on_hand'));
        $this->assertSame(1, ShopifyStockIssue::where('kind', 'count_conflict')->count());
        $this->assertSame(ShopifyInventoryLevel::CONFLICT, ShopifyInventoryLevel::where('inventory_item_id', self::I42)->where('location_id', self::MC2)->value('sync_status'));
    }

    public function test_a_movement_is_rebased_when_a_sale_happened_meanwhile(): void
    {
        $this->setUpAndActivate();

        $this->failNext = 1;
        app(VariantStockService::class)->adjust($this->size42, $this->warehouse('MC2')->id, null, 2, ['reason' => 'Found']);
        $this->shopifySale(self::I42, self::MC2, 1);

        ShopifyStockOperation::query()->update(['next_attempt_at' => null]);
        (new OutboxProcessor($this->connection->fresh()))->run();

        // Both the sale and our +2 count: 2 - 1 + 2.
        $this->assertSame(3, $this->qty(self::I42, self::MC2, 'available'));
        $this->assertSame(ShopifyStockOperation::CONFIRMED, ShopifyStockOperation::sole()->status);
    }

    // ------------------------------------------------------------------
    // Transfers, consolidation, returns
    // ------------------------------------------------------------------

    public function test_a_transfer_of_saxone_pairs_moves_them_in_shopify_and_keeps_owner_and_total(): void
    {
        $this->setUpAndActivate();
        $saxone = StockOwner::where('code', 'SAXONE')->sole();
        $stock = app(VariantStockService::class);

        $stock->changeOwner($this->size42, $this->warehouse('SAX')->id, null, $saxone->id, 3);
        $this->assertSame([], $this->applied, 'An owner change sends nothing to Shopify.');

        $transfer = app(VariantTransferService::class)->transfer($this->org->id, $this->warehouse('SAX')->id, $this->warehouse('MC2')->id, [
            ['product_variant_id' => $this->size42->id, 'owner_id' => $saxone->id, 'quantity' => 2],
        ], $this->admin);

        $this->assertSame(1, $this->qty(self::I42, self::SAX, 'available'));
        $this->assertSame(4, $this->qty(self::I42, self::MC2, 'available'));
        $this->assertCount(1, $this->appliedTo('inventoryAdjustQuantities'), 'Both sides in one Shopify call.');
        $this->assertSame(VariantStockTransfer::CHANNEL_SYNCED, $transfer->fresh()->channel_status);
        $this->assertSame(5, $this->size42->fresh()->stock);
        $this->assertSame(2, (int) VariantStockLevel::where('warehouse_id', $this->warehouse('MC2')->id)->where('stock_owner_id', $saxone->id)->sum('on_hand'));
    }

    public function test_a_transfer_shows_pending_until_shopify_confirms_and_retries_only_the_failed_step(): void
    {
        $this->setUpAndActivate();
        $this->failNext = 1;

        $transfer = app(VariantTransferService::class)->transfer($this->org->id, $this->warehouse('SAX')->id, $this->warehouse('MC2')->id, [
            ['product_variant_id' => $this->size42->id, 'quantity' => 1],
        ], $this->admin);

        // Done here, not yet in Shopify: never shown as fully done.
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame(VariantStockTransfer::CHANNEL_PENDING, $transfer->fresh()->channel_status);
        $this->assertSame(3, $this->qty(self::I42, self::SAX, 'available'));

        ShopifyStockOperation::query()->update(['next_attempt_at' => null]);
        (new OutboxProcessor($this->connection->fresh()))->run();

        $this->assertSame(VariantStockTransfer::CHANNEL_SYNCED, $transfer->fresh()->channel_status);
        $this->assertSame(2, $this->qty(self::I42, self::SAX, 'available'));
        $this->assertSame(3, $this->qty(self::I42, self::MC2, 'available'));
        // The local move was not repeated.
        $this->assertSame(1, VariantStockOperation::where('type', VariantStockOperation::TYPE_TRANSFER)->count());
    }

    public function test_an_order_split_across_sites_is_consolidated_at_mc2_and_ships_once(): void
    {
        $this->setUpAndActivate();

        // Shopify sold 2 × 42 and split them: one at each location.
        $this->shopifySale(self::I42, self::MC2, 1);
        $this->shopifySale(self::I42, self::SAX, 1);
        $this->fulfilmentOrders('7001', [
            ['location' => self::MC2, 'lines' => [['item' => self::I42, 'qty' => 1, 'line_item_id' => '71']]],
            ['location' => self::SAX, 'lines' => [['item' => self::I42, 'qty' => 1, 'line_item_id' => '71']]],
        ]);
        $this->postWebhook('orders/create', $this->orderPayload([['id' => 71, 'sku' => 'SUN-42', 'title' => 'Sunday 42', 'quantity' => 2, 'current_quantity' => 2, 'price' => '159.00']],
            ['id' => 7001, 'name' => '#7001', 'financial_status' => 'paid']), $this->connection->webhook_token, 'o-7001')->assertOk();

        $order = Order::where('external_id', '7001')->sole();
        $this->assertSame(1, $this->local($this->size42, 'MC2')['committed']);
        $this->assertSame(1, $this->local($this->size42, 'SAX')['committed']);
        $this->assertSame([], $this->appliedTo('inventoryAdjustQuantities'), 'Shopify committed its own order.');

        $stock = app(VariantStockService::class);
        $this->assertCount(1, $stock->consolidationPlan($order, $this->warehouse('MC2')->id));

        app(VariantTransferService::class)->consolidate($order, $this->warehouse('MC2')->id, $this->admin);

        // Shopify: the pair left Via Maragliano, the fulfilment moved to MC2.
        $this->assertSame(['on_hand' => 2, 'committed' => 0], ['on_hand' => $this->qty(self::I42, self::SAX, 'on_hand'), 'committed' => $this->qty(self::I42, self::SAX, 'committed')]);
        $this->assertSame(['on_hand' => 3, 'committed' => 2, 'available' => 1], [
            'on_hand' => $this->qty(self::I42, self::MC2, 'on_hand'), 'committed' => $this->qty(self::I42, self::MC2, 'committed'), 'available' => $this->qty(self::I42, self::MC2, 'available')]);
        $this->assertCount(1, $this->appliedTo('fulfillmentOrderMove'));
        // Inventoros the same, and still reserved for the order.
        $this->assertSame([3, 2, 1], [$this->local($this->size42, 'MC2')['on_hand'], $this->local($this->size42, 'MC2')['committed'], $this->local($this->size42, 'MC2')['available']]);
        $this->assertSame(2, $this->local($this->size42, 'SAX')['available']);

        // One shipment, from MC2: it fulfils the order in Shopify too.
        $shipments = app(ShipmentService::class);
        $shipment = $shipments->markShipped($shipments->create($order, [
            'warehouse_id' => $this->warehouse('MC2')->id, 'carrier_name' => 'BRT', 'tracking_number' => 'TRK1',
        ]));
        $this->assertSame([1, 0], [$this->local($this->size42, 'MC2')['on_hand'], $this->local($this->size42, 'MC2')['committed']]);

        $this->assertCount(1, $this->fulfillments);
        $this->assertSame(self::MC2, $this->fulfillments[0]['location']);
        $this->assertSame('TRK1', $this->fulfillments[0]['trackingInfo']['number']);
        $this->assertFalse($this->fulfillments[0]['notifyCustomer']);
        $this->assertSame(['on_hand' => 1, 'committed' => 0], ['on_hand' => $this->qty(self::I42, self::MC2, 'on_hand'), 'committed' => $this->qty(self::I42, self::MC2, 'committed')]);

        // Shopify reports the fulfilment back: recognised, nothing moves twice.
        $line = ['id' => 71, 'sku' => 'SUN-42', 'title' => 'Sunday 42', 'quantity' => 2, 'current_quantity' => 2, 'price' => '159.00'];
        $this->postWebhook('orders/updated', $this->orderPayload([$line], ['id' => 7001, 'name' => '#7001', 'financial_status' => 'paid', 'updated_at' => now()->addMinute()->toIso8601String(),
            'fulfillments' => [['id' => 500, 'status' => 'success', 'created_at' => now()->toIso8601String(), 'location_id' => 117678637382, 'line_items' => [['id' => 71, 'sku' => 'SUN-42', 'quantity' => 2]]]]]),
            $this->connection->webhook_token, 'o-7001-f')->assertOk();

        $this->assertSame(1, Shipment::where('order_id', $order->id)->count());
        $this->assertSame($shipment->id, ShopifyLink::where('shopify_id', '500')->value('local_id'));
        $this->assertSame(1, $this->local($this->size42, 'MC2')['on_hand']);
    }

    public function test_a_return_of_saxone_pairs_goes_to_quality_control_at_mc2_in_both_systems(): void
    {
        $this->setUpAndActivate();
        $saxone = StockOwner::where('code', 'SAXONE')->sole();
        app(VariantStockService::class)->changeOwner($this->size43, $this->warehouse('SAX')->id, null, $saxone->id, 1);

        // Sold and shipped from Via Maragliano.
        $this->shopifySale(self::I43, self::SAX, 1);
        $this->fulfilmentOrders('8001', [['location' => self::SAX, 'lines' => [['item' => self::I43, 'qty' => 1, 'line_item_id' => '81']]]]);
        $line = ['id' => 81, 'sku' => 'SUN-43', 'title' => 'Sunday 43', 'quantity' => 1, 'current_quantity' => 1, 'price' => '159.00'];
        $this->postWebhook('orders/create', $this->orderPayload([$line], ['id' => 8001, 'name' => '#8001', 'financial_status' => 'paid']), $this->connection->webhook_token, 'o-8001');
        $this->shopifyFulfil(self::I43, self::SAX, 1);
        $this->fos['8001'] = [];
        $this->postWebhook('orders/updated', $this->orderPayload([$line], ['id' => 8001, 'name' => '#8001', 'financial_status' => 'paid', 'updated_at' => now()->addMinute()->toIso8601String(),
            'fulfillments' => [['id' => 881, 'status' => 'success', 'created_at' => now()->toIso8601String(), 'location_id' => 14558855261, 'line_items' => [['id' => 81, 'sku' => 'SUN-43', 'quantity' => 1]]]]]),
            $this->connection->webhook_token, 'o-8001-f');
        $this->assertSame(0, $this->local($this->size43, 'SAX')['on_hand']);

        // Refunded with restock: Shopify puts it back on sale at Via Maragliano.
        $this->shopifyAdjust(self::I43, self::SAX, 'available', 1);
        $this->postWebhook('orders/updated', $this->orderPayload([$line], ['id' => 8001, 'name' => '#8001', 'financial_status' => 'refunded', 'updated_at' => now()->addMinutes(2)->toIso8601String(),
            'fulfillments' => [['id' => 881, 'status' => 'success', 'created_at' => now()->toIso8601String(), 'location_id' => 14558855261, 'line_items' => [['id' => 81, 'sku' => 'SUN-43', 'quantity' => 1]]]],
            'refunds' => [['id' => 991, 'created_at' => now()->addMinutes(2)->toIso8601String(), 'refund_line_items' => [['line_item_id' => 81, 'quantity' => 1, 'restock_type' => 'return', 'location_id' => 14558855261]]]]]),
            $this->connection->webhook_token, 'o-8001-r');

        // Inventoros: at MC2, to be checked, still Saxone's.
        $mc2 = $this->local($this->size43, 'MC2');
        $this->assertSame([1, 1, 0], [$mc2['on_hand'], $mc2['quality_control'], $mc2['available']]);
        $this->assertSame($saxone->id, VariantStockLevel::where('warehouse_id', $this->warehouse('MC2')->id)->where('product_variant_id', $this->size43->id)->where('on_hand', '>', 0)->value('stock_owner_id'));
        // Shopify: not on sale anywhere, in quality control at MC2.
        $this->assertSame(0, $this->qty(self::I43, self::SAX, 'available'));
        $this->assertSame(1, $this->qty(self::I43, self::MC2, 'quality_control'));
        $this->assertSame(0, $this->qty(self::I43, self::MC2, 'available'));

        // After inspection, on sale in both.
        app(VariantStockService::class)->inspect($this->size43, $this->warehouse('MC2')->id, $saxone->id, 1, 'available', null);
        $this->assertSame(1, $this->qty(self::I43, self::MC2, 'available'));
        $this->assertSame(0, $this->qty(self::I43, self::MC2, 'quality_control'));
    }

    public function test_a_failed_operation_is_visible_and_its_retry_does_not_duplicate(): void
    {
        $this->setUpAndActivate();
        $this->failNext = 100; // Shopify keeps throttling

        app(VariantStockService::class)->adjust($this->size42, $this->warehouse('MC2')->id, null, 1);
        $operation = ShopifyStockOperation::sole();
        for ($i = 0; $i < 10; $i++) {
            $operation->refresh()->forceFill(['next_attempt_at' => null])->save();
            (new OutboxProcessor($this->connection->fresh()))->run();
        }

        $this->assertSame(ShopifyStockOperation::FAILED, $operation->fresh()->status);
        $this->assertSame(ShopifyInventoryLevel::ERROR, ShopifyInventoryLevel::where('inventory_item_id', self::I42)->where('location_id', self::MC2)->value('sync_status'));
        $this->assertSame(1, ShopifyStockIssue::where('kind', 'operation_failed')->where('status', 'open')->count());
        $this->assertSame(2, $this->qty(self::I42, self::MC2, 'available'));

        // Shopify is back: the operator retries.
        $this->failNext = 0;
        $this->post(route('plg.shopify.stock.operations.retry', $operation->id))->assertRedirect();

        $this->assertSame(ShopifyStockOperation::CONFIRMED, $operation->fresh()->status);
        $this->assertSame(3, $this->qty(self::I42, self::MC2, 'available'));
        $this->assertSame(0, ShopifyStockIssue::where('status', 'open')->count());
    }

    // ------------------------------------------------------------------
    // Transfer #T0001, routing
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function transferNode(string $status, int $unreceived): array
    {
        return [
            'id' => 'gid://shopify/InventoryTransfer/1', 'name' => '#T0001', 'status' => $status, 'referenceName' => null,
            'dateCreated' => now()->toIso8601String(), 'totalQuantity' => 2, 'receivedQuantity' => 2 - $unreceived,
            'origin' => null,
            'destination' => ['name' => 'VIA MARAGLIANO 7/1', 'location' => ['id' => self::SAX]],
            'lineItems' => ['nodes' => [['id' => 'gid://shopify/InventoryTransferLineItem/1', 'title' => 'Sunday 42', 'totalQuantity' => 2, 'shippedQuantity' => 2, 'processableQuantity' => 0, 'inventoryItem' => ['id' => self::I42, 'sku' => 'SUN-42']]]],
            'shipments' => ['nodes' => $status === 'IN_PROGRESS' ? [[
                'id' => 'gid://shopify/InventoryShipment/1', 'name' => 'S1', 'status' => 'IN_TRANSIT',
                'lineItems' => ['nodes' => [['id' => 'gid://shopify/InventoryShipmentLineItem/1', 'quantity' => 2, 'unreceivedQuantity' => $unreceived, 'acceptedQuantity' => 0, 'rejectedQuantity' => 0, 'inventoryItem' => ['id' => self::I42, 'sku' => 'SUN-42']]]],
            ]] : []],
        ];
    }

    public function test_a_draft_transfer_is_redirected_to_mc2_only_after_approval(): void
    {
        $this->setUpAndActivate();
        $this->transfers['#T0001'] = $this->transferNode('DRAFT', 2);
        $planner = new TransferPlanner($this->connection);

        $plan = $planner->plan('T0001', $this->warehouse('MC2')->id);
        $this->assertSame('DRAFT', $plan['status']);
        $this->assertSame([], $this->appliedTo('inventoryTransferEdit'), 'Nothing changes before approval.');

        $planner->approve(ShopifyStockOperation::findOrFail($plan['operations'][0]), $this->admin->id);
        $this->assertSame(self::MC2, $this->transfers['#T0001']['destination']['location']['id']);
    }

    public function test_a_transfer_in_transit_is_received_at_mc2_without_counting_it_twice(): void
    {
        $this->setUpAndActivate();
        // Shopify shows 2 pairs incoming at Via Maragliano.
        $this->shopifyAdjust(self::I42, self::SAX, 'incoming', 2);
        $this->transfers['#T0001'] = $this->transferNode('IN_PROGRESS', 2);
        $planner = new TransferPlanner($this->connection);

        $plan = $planner->plan('T0001', $this->warehouse('MC2')->id);
        $this->assertCount(1, $plan['operations']);
        $this->assertStringContainsString('REJECTED', implode(' ', $plan['plan']));

        $planner->approve(ShopifyStockOperation::findOrFail($plan['operations'][0]), $this->admin->id);

        // Not in Via Maragliano's stock, no longer incoming there; at MC2 once.
        $this->assertSame(3, $this->qty(self::I42, self::SAX, 'available'));
        $this->assertSame(0, $this->qty(self::I42, self::SAX, 'incoming'));
        $this->assertSame(4, $this->qty(self::I42, self::MC2, 'available'));
        $this->assertSame(4, $this->local($this->size42, 'MC2')['on_hand']);
        $this->assertSame(3, $this->local($this->size42, 'SAX')['on_hand']);

        // Approving again does nothing.
        $this->expectException(ShopifyException::class);
        $planner->approve(ShopifyStockOperation::findOrFail($plan['operations'][0]), $this->admin->id);
    }

    public function test_the_routing_check_flags_orders_assigned_to_saxone_while_mc2_has_the_pairs(): void
    {
        $this->setUpAndActivate();
        $this->shopifySale(self::I42, self::SAX, 1);
        $this->fulfilmentOrders('9001', [['location' => self::SAX, 'lines' => [['item' => self::I42, 'qty' => 1, 'line_item_id' => '91']]]]);
        $this->postWebhook('orders/create', $this->orderPayload([['id' => 91, 'sku' => 'SUN-42', 'title' => 'Sunday 42', 'quantity' => 1, 'current_quantity' => 1, 'price' => '159.00']],
            ['id' => 9001, 'name' => '#9001', 'financial_status' => 'paid']), $this->connection->webhook_token, 'o-9001');

        $rows = (new RoutingCheck($this->connection))->run();

        $this->assertSame('check_routing', $rows[0]['verdict']);
        $this->assertSame([], $this->appliedTo('fulfillmentOrderMove'), 'The check changes nothing.');
    }

    // ------------------------------------------------------------------
    // Fulfilments from Inventoros, also without per-warehouse stock
    // ------------------------------------------------------------------

    public function test_a_shipment_made_in_inventoros_fulfils_the_shopify_order_once_even_after_a_lost_answer(): void
    {
        // The store as it is today: no stock set-up, one location.
        $this->connection->forceFill(['location_map' => [['location_id' => self::SAX, 'warehouse_id' => null]]])->save();
        $this->size43->forceFill(['stock' => 5])->save();
        $this->stockItem(self::I43, 'SUN-43', [self::SAX => ['available' => 4, 'committed' => 1]]);
        ShopifyInventoryItem::query()->create(['organization_id' => $this->org->id, 'sku' => 'SUN-43', 'inventory_item_id' => self::I43, 'tracked' => true]);
        $this->fulfilmentOrders('6001', [['location' => self::SAX, 'lines' => [['item' => self::I43, 'qty' => 1, 'line_item_id' => '61']]]]);
        $line = ['id' => 61, 'sku' => 'SUN-43', 'title' => 'Sunday 43', 'quantity' => 1, 'current_quantity' => 1, 'price' => '159.00'];
        $this->postWebhook('orders/create', $this->orderPayload([$line], ['id' => 6001, 'name' => '#6001', 'financial_status' => 'paid']), $this->connection->webhook_token, 'o-6001')->assertOk();
        $order = Order::where('external_id', '6001')->sole();
        $this->actingAs($this->admin);

        // Shopify fulfils, but the answer is lost.
        $this->loseNextAnswer = true;
        $shipments = app(ShipmentService::class);
        $shipments->markShipped($shipments->create($order, ['tracking_number' => 'TRK6']));

        $operation = ShopifyStockOperation::where('kind', 'fulfillment_create')->sole();
        $this->assertSame(ShopifyStockOperation::PENDING, $operation->status, 'Not shown as done without an answer.');
        $this->assertCount(1, $this->fulfillments);

        // The retry reads the order first: already fulfilled, nothing twice.
        $operation->forceFill(['next_attempt_at' => null])->save();
        (new OutboxProcessor($this->connection->fresh()))->run();
        $this->assertSame(ShopifyStockOperation::CONFIRMED, $operation->fresh()->status);
        $this->assertCount(1, $this->fulfillments);
        $this->assertSame(0, $this->qty(self::I43, self::SAX, 'committed'));

        // No stock push of totals in this mode either.
        $this->assertSame([], $this->appliedTo('inventoryAdjustQuantities'));
    }

    public function test_fulfilments_can_be_turned_off(): void
    {
        $this->connection->forceFill(['push_fulfillments' => false, 'location_map' => [['location_id' => self::SAX, 'warehouse_id' => null]]])->save();
        $this->size43->forceFill(['stock' => 5])->save();
        $this->fulfilmentOrders('6002', [['location' => self::SAX, 'lines' => [['item' => self::I43, 'qty' => 1, 'line_item_id' => '62']]]]);
        $line = ['id' => 62, 'sku' => 'SUN-43', 'title' => 'Sunday 43', 'quantity' => 1, 'current_quantity' => 1, 'price' => '159.00'];
        $this->postWebhook('orders/create', $this->orderPayload([$line], ['id' => 6002, 'name' => '#6002', 'financial_status' => 'paid']), $this->connection->webhook_token, 'o-6002');
        $this->actingAs($this->admin);

        $shipments = app(ShipmentService::class);
        $shipments->markShipped($shipments->create(Order::where('external_id', '6002')->sole(), []));

        $this->assertSame([], $this->fulfillments);
        $this->assertSame(0, ShopifyStockOperation::count());
    }
}
