<?php

declare(strict_types=1);

namespace Tests\Feature\VariantWarehouseStock;

use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockTransfer;
use App\Models\System\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\BuildsTwoWarehouses;
use Tests\TestCase;

/**
 * The Stock by warehouse screen and its actions.
 */
final class StockByWarehouseScreenTest extends TestCase
{
    use BuildsTwoWarehouses;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::set('installed', true, 'boolean');
        $this->setUpTwoWarehouses();
        $this->putStock($this->size42, $this->mc2, 2);
        $this->putStock($this->size42, $this->saxone, 3, $this->ownerSaxone);
    }

    public function test_the_screen_shows_each_warehouse_and_the_total_of_the_variants_only(): void
    {
        $this->get(route('stock-by-warehouse.index', ['search' => 'SUNDAY-BLACK-42']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('StockByWarehouse/Index')
                ->where('enabled', true)
                ->has('variants.data', 1)
                ->where('variants.data.0.sku', 'SUNDAY-BLACK-42')
                ->where("variants.data.0.warehouses.{$this->mc2->id}.on_hand", 2)
                ->where("variants.data.0.warehouses.{$this->saxone->id}.on_hand", 3)
                ->where('variants.data.0.total.on_hand', 5)
                ->where('variants.data.0.total.available', 5)
                ->where('variants.data.0.unassigned', 2));
    }

    public function test_filters_by_owner_including_to_be_assigned(): void
    {
        $this->get(route('stock-by-warehouse.index', ['owner' => (string) $this->ownerSaxone->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('variants.data', 1));
        $this->get(route('stock-by-warehouse.index', ['owner' => 'unassigned']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('variants.data', 1));
        $this->get(route('stock-by-warehouse.index', ['owner' => (string) $this->ownerMc2->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('variants.data', 0));
    }

    public function test_a_count_based_on_stale_figures_is_refused_with_a_message(): void
    {
        $this->post(route('stock-by-warehouse.adjust', $this->size42), [
            'warehouse_id' => $this->mc2->id, 'mode' => 'count', 'quantity' => 4, 'expected_on_hand' => 1, 'reason' => 'Count',
        ])->assertSessionHas('error');

        $this->post(route('stock-by-warehouse.adjust', $this->size42), [
            'warehouse_id' => $this->mc2->id, 'mode' => 'count', 'quantity' => 4, 'expected_on_hand' => 2, 'reason' => 'Count',
        ])->assertSessionHas('success');

        $this->assertSame(4, $this->levelAt($this->size42, $this->mc2)['on_hand']);
    }

    public function test_an_immediate_transfer_and_an_owner_assignment(): void
    {
        $this->post(route('stock-by-warehouse.transfer'), [
            'from_warehouse_id' => $this->saxone->id, 'to_warehouse_id' => $this->mc2->id,
            'lines' => [['product_variant_id' => $this->size42->id, 'owner_id' => $this->ownerSaxone->id, 'quantity' => 1]],
        ])->assertSessionHas('success');

        $this->assertSame(1, VariantStockTransfer::count());
        $this->assertSame(3, $this->levelAt($this->size42, $this->mc2)['on_hand']);

        $this->post(route('stock-by-warehouse.owners'), [
            'lines' => [['product_variant_id' => $this->size42->id, 'warehouse_id' => $this->mc2->id, 'from_owner_id' => null, 'to_owner_id' => $this->ownerMc2->id, 'quantity' => 2]],
        ])->assertSessionHas('success');

        $this->assertSame(2, (int) VariantStockLevel::where('warehouse_id', $this->mc2->id)->where('stock_owner_id', $this->ownerMc2->id)->sum('on_hand'));
        $this->assertSame(0, (int) VariantStockLevel::where('warehouse_id', $this->mc2->id)->whereNull('stock_owner_id')->sum('on_hand'));
    }

    public function test_the_order_page_shows_what_to_bring_and_consolidates(): void
    {
        $order = $this->placeOrder($this->size42, 4);

        $this->get(route('orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('variantStock.ready', false)
                ->has('variantStock.to_move', 1));

        $this->post(route('orders.consolidate', $order))->assertSessionHas('success');

        $this->get(route('orders.show', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('variantStock.ready', true));
    }

    public function test_the_variant_detail_lists_rows_commitments_and_history(): void
    {
        $this->placeOrder($this->size42, 1);

        $this->getJson(route('stock-by-warehouse.show', $this->size42))
            ->assertOk()
            ->assertJsonCount(2, 'levels')
            ->assertJsonCount(1, 'allocations')
            ->assertJsonPath('history.0.type', 'order_commit');
    }
}
