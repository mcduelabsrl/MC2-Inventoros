<?php

declare(strict_types=1);

namespace Tests\Feature\VariantWarehouseStock;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ShippingException;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\VariantStockOperation;
use App\Models\Order\OrderItemAllocation;
use App\Services\Inventory\VariantTransferService;
use App\Services\OrderService;
use App\Services\ReturnOrderService;
use App\Services\Shipping\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsTwoWarehouses;
use Tests\TestCase;

/**
 * Stock of each variant in each warehouse, by owner and state, through every
 * path that moves it: adjustments, orders, consolidation, shipping,
 * transfers, returns and inspection.
 */
final class VariantWarehouseStockTest extends TestCase
{
    use BuildsTwoWarehouses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoWarehouses();
    }

    public function test_same_variant_has_separate_balances_and_a_total_of_both(): void
    {
        $this->putStock($this->size42, $this->mc2, 2);
        $this->putStock($this->size42, $this->saxone, 3);

        $this->assertSame(2, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(3, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertSame(5, $this->size42->fresh()->stock);
        // The product total is the sum of its variants, never the parent's own column.
        $this->assertSame(5, $this->shoe->fresh()->total_stock);
        // The classic ledger still explains the variant's total.
        $this->assertSame(5, (int) StockAdjustment::where('product_variant_id', $this->size42->id)->sum('adjustment_quantity'));
    }

    public function test_an_order_takes_mc2_first_and_saxone_for_the_rest(): void
    {
        $this->putStock($this->size42, $this->mc2, 2);
        $this->putStock($this->size42, $this->saxone, 3);

        $order = $this->placeOrder($this->size42, 3);

        $this->assertSame(['on_hand' => 2, 'committed' => 2, 'available' => 0],
            array_intersect_key($this->levelAt($this->size42, $this->mc2), array_flip(['on_hand', 'committed', 'available'])));
        $this->assertSame(['on_hand' => 3, 'committed' => 1, 'available' => 2],
            array_intersect_key($this->levelAt($this->size42, $this->saxone), array_flip(['on_hand', 'committed', 'available'])));
        // Physical stock did not move; what is left to sell went down.
        $this->assertSame(2, $this->size42->fresh()->stock);
        $this->assertSame(2, OrderItemAllocation::where('order_id', $order->id)->count());
    }

    public function test_an_order_cannot_take_more_than_is_available_in_both(): void
    {
        $this->putStock($this->size42, $this->mc2, 1);
        $this->putStock($this->size42, $this->saxone, 1);

        try {
            $this->placeOrder($this->size42, 3);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException) {
        }

        $this->assertSame(0, $this->levelAt($this->size42, $this->mc2)['committed']);
        $this->assertSame(2, $this->size42->fresh()->stock);
    }

    public function test_cancelling_an_order_releases_its_commitments_where_they_were(): void
    {
        $this->putStock($this->size42, $this->mc2, 1);
        $this->putStock($this->size42, $this->saxone, 2);
        $order = $this->placeOrder($this->size42, 3);

        app(OrderService::class)->cancel($order);

        $this->assertSame(0, $this->levelAt($this->size42, $this->mc2)['committed']);
        $this->assertSame(0, $this->levelAt($this->size42, $this->saxone)['committed']);
        $this->assertSame(3, $this->size42->fresh()->stock);
    }

    public function test_an_order_with_pairs_in_both_sites_ships_once_after_consolidation_at_mc2(): void
    {
        $this->putStock($this->size42, $this->mc2, 1);
        $this->putStock($this->size42, $this->saxone, 1, $this->ownerSaxone);
        $this->putStock($this->size43, $this->saxone, 1);
        $order = $this->placeOrder($this->size42, 2);

        $plan = $this->stockService()->consolidationPlan($order, $this->mc2->id);
        $this->assertCount(1, $plan);
        $this->assertSame($this->saxone->id, $plan[0]['warehouse_id']);
        $this->assertSame($this->ownerSaxone->id, $plan[0]['owner_id']);

        $shipments = app(ShipmentService::class);
        $shipment = $shipments->create($order, ['warehouse_id' => $this->mc2->id]);

        // Not ready: one pair is still at Saxone.
        try {
            $shipments->markShipped($shipment);
            $this->fail('Expected ShippingException');
        } catch (ShippingException $e) {
            $this->assertStringContainsString('not yet in', $e->getMessage());
        }
        $this->assertSame(1, $this->levelAt($this->size42, $this->saxone)['on_hand']);

        [$transfer] = app(VariantTransferService::class)->consolidate($order, $this->mc2->id, $this->admin);
        $this->assertSame($order->id, $transfer->order_id);

        // Moved and still reserved for the order: never on sale on the way.
        $this->assertSame(['on_hand' => 2, 'committed' => 2, 'available' => 0],
            array_intersect_key($this->levelAt($this->size42, $this->mc2), array_flip(['on_hand', 'committed', 'available'])));
        $this->assertSame(0, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertTrue($this->stockService()->isConsolidatedAt($order, $this->mc2->id));
        // Ownership travels with the pair.
        $this->assertSame(1, OrderItemAllocation::where('order_id', $order->id)
            ->where('warehouse_id', $this->mc2->id)->where('stock_owner_id', $this->ownerSaxone->id)->sum('quantity'));

        $shipments->markShipped($shipment->fresh());

        $this->assertSame(['on_hand' => 0, 'committed' => 0],
            array_intersect_key($this->levelAt($this->size42, $this->mc2), array_flip(['on_hand', 'committed'])));
        $this->assertSame(0, $this->size42->fresh()->stock);
        $this->assertSame(2, (int) OrderItemAllocation::where('order_id', $order->id)->sum('fulfilled_quantity'));
    }

    public function test_a_transfer_of_saxone_pairs_to_mc2_changes_the_site_not_the_owner_or_the_total(): void
    {
        $this->putStock($this->size42, $this->saxone, 3, $this->ownerSaxone);

        $transfer = app(VariantTransferService::class)->transfer($this->org->id, $this->saxone->id, $this->mc2->id, [
            ['product_variant_id' => $this->size42->id, 'owner_id' => $this->ownerSaxone->id, 'quantity' => 2],
        ], $this->admin);

        $this->assertSame('completed', $transfer->status);
        $this->assertSame(1, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertSame(2, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(3, $this->size42->fresh()->stock);
        $levels = $this->stockService()->levels($this->size42);
        $this->assertTrue($levels->every(fn ($l) => $l->stock_owner_id === $this->ownerSaxone->id));
    }

    public function test_a_transfer_goes_through_prepared_shipped_and_received_with_refused_units(): void
    {
        $this->putStock($this->size42, $this->saxone, 3, $this->ownerSaxone);
        $transfers = app(VariantTransferService::class);

        // 1. Prepared: set aside at the origin, no longer for sale.
        $transfer = $transfers->prepare($this->org->id, $this->saxone->id, $this->mc2->id, [
            ['product_variant_id' => $this->size42->id, 'owner_id' => $this->ownerSaxone->id, 'quantity' => 2],
        ], $this->admin);
        $this->assertSame('ready', $transfer->status);
        $this->assertSame(['on_hand' => 3, 'committed' => 2, 'available' => 1],
            array_intersect_key($this->levelAt($this->size42, $this->saxone), array_flip(['on_hand', 'committed', 'available'])));
        $this->assertSame(1, $this->size42->fresh()->stock);

        // 2. Shipped: they leave the origin and are incoming at MC2.
        $transfers->ship($transfer, $this->admin, 'BRT', '123');
        $this->assertSame('in_transit', $transfer->fresh()->status);
        $this->assertSame(1, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertSame(0, $this->levelAt($this->size42, $this->saxone)['committed']);
        $this->assertSame(2, $this->levelAt($this->size42, $this->mc2)['incoming']);
        $this->assertSame(0, $this->levelAt($this->size42, $this->mc2)['on_hand']);

        // 3. Received in two goes: one accepted now, one refused later.
        $line = $transfer->lines()->sole();
        $transfers->receive($transfer, [$line->id => ['accepted' => 1, 'rejected' => 0]], $this->admin);
        $this->assertSame('in_transit', $transfer->fresh()->status);
        $this->assertSame(1, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(1, $this->levelAt($this->size42, $this->mc2)['incoming']);

        try {
            $transfers->receive($transfer, [$line->id => ['accepted' => 2, 'rejected' => 0]], $this->admin);
            $this->fail('More than is outstanding was received.');
        } catch (BusinessRuleException) {
        }

        $transfers->receive($transfer, [$line->id => ['accepted' => 0, 'rejected' => 1]], $this->admin);
        $this->assertSame('completed', $transfer->fresh()->status);
        $this->assertSame(0, $this->levelAt($this->size42, $this->mc2)['incoming']);
        $this->assertSame(1, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        // The refused pair never entered: the total is what is really there.
        $this->assertSame(2, $this->size42->fresh()->stock);
        $this->assertSame(['transfer_prepare', 'transfer_ship', 'transfer_receive', 'transfer_receive'],
            VariantStockOperation::where('transfer_id', $transfer->id)->orderBy('id')->pluck('type')->all());
    }

    public function test_a_prepared_transfer_can_be_cancelled_but_not_once_shipped(): void
    {
        $this->putStock($this->size42, $this->saxone, 2);
        $transfers = app(VariantTransferService::class);

        $first = $transfers->prepare($this->org->id, $this->saxone->id, $this->mc2->id, [['product_variant_id' => $this->size42->id, 'quantity' => 2]], $this->admin);
        $transfers->cancel($first, $this->admin);
        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame(2, $this->levelAt($this->size42, $this->saxone)['available']);

        $second = $transfers->prepare($this->org->id, $this->saxone->id, $this->mc2->id, [['product_variant_id' => $this->size42->id, 'quantity' => 1]], $this->admin);
        $transfers->ship($second, $this->admin);
        $this->expectException(BusinessRuleException::class);
        $transfers->cancel($second, $this->admin);
    }

    public function test_a_transfer_cannot_move_units_that_are_not_free(): void
    {
        $this->putStock($this->size42, $this->saxone, 1);
        $this->placeOrder($this->size42, 1);

        $this->expectException(InsufficientStockException::class);
        app(VariantTransferService::class)->transfer($this->org->id, $this->saxone->id, $this->mc2->id, [
            ['product_variant_id' => $this->size42->id, 'quantity' => 1],
        ], $this->admin);
    }

    public function test_a_return_of_saxone_pairs_lands_at_mc2_in_quality_control_and_keeps_its_owner(): void
    {
        $this->putStock($this->size42, $this->saxone, 1, $this->ownerSaxone);
        $order = $this->placeOrder($this->size42, 1);
        $shipments = app(ShipmentService::class);
        $shipments->markShipped($shipments->create($order, ['warehouse_id' => $this->saxone->id]));

        $item = $order->items()->first();
        $returns = app(ReturnOrderService::class);
        $return = $returns->create($this->org->id, $this->admin, [
            'order_id' => $order->id, 'type' => 'return', 'reason' => 'size',
            'items' => [['order_item_id' => $item->id, 'quantity' => 1, 'condition' => 'used', 'restock' => true]],
        ]);
        $returns->approve($return, $this->admin);
        $returns->receive($return->fresh(), $this->admin);

        $atMc2 = $this->levelAt($this->size42, $this->mc2);
        $this->assertSame(1, $atMc2['on_hand']);
        $this->assertSame(1, $atMc2['quality_control']);
        $this->assertSame(0, $atMc2['available']);
        $this->assertSame(0, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $level = $this->stockService()->levels($this->size42)->firstWhere('warehouse_id', $this->mc2->id);
        $this->assertSame($this->ownerSaxone->id, $level->stock_owner_id);

        // On sale only after inspection.
        $this->stockService()->inspect($this->size42, $this->mc2->id, $this->ownerSaxone->id, 1, 'available', null);
        $this->assertSame(1, $this->levelAt($this->size42, $this->mc2)['available']);
        $this->assertSame($this->ownerSaxone->id, $this->stockService()->levels($this->size42)->firstWhere('warehouse_id', $this->mc2->id)->stock_owner_id);
    }

    public function test_an_inspection_can_keep_units_unavailable_with_a_reason(): void
    {
        $this->stockService()->apply($this->org->id, VariantStockOperation::TYPE_RETURN_RECEIVED, [[
            'variant' => $this->size42, 'warehouse_id' => $this->mc2->id, 'owner_id' => null,
            'deltas' => ['on_hand' => 2, 'quality_control' => 2],
        ]]);

        try {
            $this->stockService()->inspect($this->size42, $this->mc2->id, null, 1, 'damaged', '');
            $this->fail('A reason is required');
        } catch (BusinessRuleException) {
        }

        $this->stockService()->inspect($this->size42, $this->mc2->id, null, 1, 'damaged', 'Sole unstitched');
        $row = $this->levelAt($this->size42, $this->mc2);
        $this->assertSame([2, 1, 1, 0], [$row['on_hand'], $row['quality_control'], $row['damaged'], $row['available']]);
    }

    public function test_unknown_ownership_stays_to_be_assigned_until_an_explicit_change(): void
    {
        $this->putStock($this->size42, $this->mc2, 4);

        $level = $this->stockService()->levels($this->size42)->first();
        $this->assertNull($level->stock_owner_id);

        $this->stockService()->changeOwner($this->size42, $this->mc2->id, null, $this->ownerMc2->id, 3);

        $levels = $this->stockService()->levels($this->size42)->keyBy(fn ($l) => $l->stock_owner_id ?? 0);
        $this->assertSame(1, $levels[0]->on_hand);
        $this->assertSame(3, $levels[$this->ownerMc2->id]->on_hand);
        // Same pairs, same place: the total does not move.
        $this->assertSame(4, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(4, $this->size42->fresh()->stock);
    }

    public function test_a_channel_sale_without_stock_is_committed_and_shows_negative(): void
    {
        $this->putStock($this->size42, $this->saxone, 1);

        $order = $this->placeOrder($this->size42, 2, [
            'source' => 'shopify',
            'order' => ['allow_stock_shortfall' => true],
            'item' => ['allocation_plan' => [$this->saxone->id => 2]],
        ]);

        $row = $this->levelAt($this->size42, $this->saxone);
        $this->assertSame(2, $row['committed']);
        $this->assertSame(-1, $row['available']);
        $this->assertSame(1, (int) OrderItemAllocation::where('order_id', $order->id)->sum('shortfall_quantity'));
    }

    public function test_an_operation_with_an_idempotency_key_applies_once(): void
    {
        foreach ([1, 2] as $attempt) {
            $this->stockService()->adjust($this->size42, $this->mc2->id, null, 5, ['idempotency_key' => 'receipt-1']);
        }

        $this->assertSame(5, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(1, VariantStockOperation::where('idempotency_key', 'receipt-1')->count());
    }

    public function test_a_count_made_on_stale_figures_is_refused(): void
    {
        $this->putStock($this->size42, $this->mc2, 3);
        $this->placeOrder($this->size42, 1);
        $this->putStock($this->size42, $this->mc2, -1);

        $this->expectException(BusinessRuleException::class);
        $this->stockService()->setCount($this->size42, $this->mc2->id, null, 5, ['expected_on_hand' => 3]);
    }

    public function test_classic_variant_adjustments_land_in_a_warehouse(): void
    {
        StockAdjustment::adjustVariant($this->size42, 4, 'received', 'Delivery', warehouseId: $this->saxone->id);
        StockAdjustment::adjustVariant($this->size42, 2, 'received', 'Delivery');

        $this->assertSame(4, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertSame(2, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(6, $this->size42->fresh()->stock);
    }

    public function test_negative_quantities_are_kept_not_clamped(): void
    {
        $this->stockService()->apply($this->org->id, VariantStockOperation::TYPE_CHANNEL_SYNC, [[
            'variant' => $this->size42, 'warehouse_id' => $this->mc2->id, 'owner_id' => null,
            'deltas' => ['on_hand' => -2],
        ]], ['source' => VariantStockOperation::SOURCE_EXTERNAL, 'allow_negative' => true]);

        $this->assertSame(-2, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(-2, $this->size42->fresh()->stock);
    }
}
