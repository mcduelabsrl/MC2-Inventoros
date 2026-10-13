<?php

declare(strict_types=1);

namespace Tests\Feature\VariantWarehouseStock;

use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockOperation;
use App\Models\System\SystemSetting;
use App\Services\StockAuditService;
use App\Support\VariantWarehouseStockSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\BuildsTwoWarehouses;
use Tests\TestCase;

/**
 * Stock audits of variants per warehouse: one line per variant, compared
 * with the stock when it is counted, booked as an absolute count.
 */
final class VariantStockAuditTest extends TestCase
{
    use BuildsTwoWarehouses;
    use RefreshDatabase;

    private StockAuditService $audits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTwoWarehouses();
        $this->audits = app(StockAuditService::class);

        // Saxone, Via Maragliano: 2 of Saxone's and 1 to be attributed.
        $this->putStock($this->size42, $this->saxone, 2, $this->ownerSaxone);
        $this->putStock($this->size42, $this->saxone, 1);
        $this->putStock($this->size43, $this->saxone, 1);
        // Elsewhere: not part of a count of Via Maragliano.
        $this->putStock($this->size42, $this->mc2, 5);
    }

    private function audit(array $data = []): StockAudit
    {
        $audit = $this->audits->create($this->org->id, $this->admin, $data + [
            'name' => 'Via Maragliano', 'audit_type' => 'full', 'warehouse_id' => $this->saxone->id,
        ]);

        return $this->audits->start($audit, $this->admin);
    }

    private function line(StockAudit $audit, int $variantId): StockAuditItem
    {
        return $audit->items()->where('product_variant_id', $variantId)->sole();
    }

    public function test_a_warehouse_count_has_one_line_per_variant_with_that_warehouse_stock(): void
    {
        $audit = $this->audit();

        $this->assertSame(2, $audit->items()->count());
        $this->assertSame(3, $this->line($audit, $this->size42->id)->system_quantity, 'All owners together, this warehouse only.');
        $this->assertSame(1, $this->line($audit, $this->size43->id)->system_quantity);
        $this->assertSame($this->saxone->id, $this->line($audit, $this->size42->id)->warehouse_id);
    }

    public function test_completing_books_absolute_counts_found_pairs_unassigned_missing_from_unassigned_first(): void
    {
        $audit = $this->audit();

        $this->audits->recordCount($audit, $this->line($audit, $this->size42->id), $this->admin, 1); // 2 missing
        $this->audits->recordCount($audit, $this->line($audit, $this->size43->id), $this->admin, 3); // 2 found

        $booked = $this->audits->complete($audit, $this->admin);

        $this->assertSame(2, $booked);
        $this->assertSame('completed', $audit->fresh()->status);
        $this->assertSame(1, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertSame(3, $this->levelAt($this->size43, $this->saxone)['on_hand']);
        // The missing pairs came off "to be attributed" first, then Saxone's.
        $rows = VariantStockLevel::where('product_variant_id', $this->size42->id)->where('warehouse_id', $this->saxone->id)->pluck('on_hand', 'stock_owner_id');
        $this->assertSame(1, (int) $rows[$this->ownerSaxone->id]);
        $this->assertSame(0, (int) $rows['']);
        // Found pairs are to be attributed.
        $this->assertSame(3, (int) VariantStockLevel::where('product_variant_id', $this->size43->id)->where('warehouse_id', $this->saxone->id)->whereNull('stock_owner_id')->sum('on_hand'));
        // MC2 untouched; totals follow.
        $this->assertSame(5, $this->levelAt($this->size42, $this->mc2)['on_hand']);
        $this->assertSame(6, $this->size42->fresh()->stock);
        // Absolute counts, as on the Stock by warehouse screen.
        $operations = VariantStockOperation::where('type', VariantStockOperation::TYPE_COUNT)->get();
        $this->assertCount(2, $operations);
        $this->assertTrue($operations->every(fn ($o) => $o->mode === 'absolute' && $o->meta['stock_audit_id'] === $audit->id));
        $this->assertSame('adjusted', $this->line($audit, $this->size42->id)->status);
    }

    public function test_a_line_whose_stock_moved_after_counting_must_be_counted_again_and_nothing_is_booked(): void
    {
        $audit = $this->audit();
        $this->audits->recordCount($audit, $this->line($audit, $this->size42->id), $this->admin, 3);
        $this->audits->recordCount($audit, $this->line($audit, $this->size43->id), $this->admin, 0);

        // A sale ships from Via Maragliano after the 42 was counted.
        $this->stockService()->adjust($this->size42, $this->saxone->id, null, -1, ['reason' => 'sold']);

        try {
            $this->audits->complete($audit, $this->admin);
            $this->fail('The audit completed on a stale count.');
        } catch (InvalidStateException $e) {
            $this->assertSame('stale_counts', $e->errorCode);
            $this->assertStringContainsString('SUNDAY-BLACK-42', $e->getMessage());
        }

        $this->assertSame('in_progress', $audit->fresh()->status);
        $this->assertSame('recount', $this->line($audit, $this->size42->id)->status);
        $this->assertSame(1, $this->levelAt($this->size43, $this->saxone)['on_hand'], 'Nothing booked, not even the lines still valid.');
        $this->assertSame(0, VariantStockOperation::where('type', VariantStockOperation::TYPE_COUNT)->count());

        // Counted again: compared with the stock now, and booked.
        $this->audits->recordCount($audit, $this->line($audit, $this->size42->id), $this->admin, 2);
        $this->assertSame(2, $this->line($audit, $this->size42->id)->system_quantity);
        $this->audits->complete($audit, $this->admin);

        $this->assertSame('completed', $audit->fresh()->status);
        $this->assertSame(2, $this->levelAt($this->size42, $this->saxone)['on_hand']);
        $this->assertSame(0, $this->levelAt($this->size43, $this->saxone)['on_hand']);
        $this->assertSame(1, VariantStockOperation::where('type', VariantStockOperation::TYPE_COUNT)->count(), 'The 42 matched: only the 43 changed.');
    }

    public function test_a_count_by_bin_leaves_out_products_with_variants(): void
    {
        $plain = Product::create([
            'organization_id' => $this->org->id, 'sku' => 'BOX', 'name' => 'Shoe box', 'price' => 1, 'currency' => 'EUR',
            'stock' => 4, 'min_stock' => 0, 'is_active' => true, 'has_variants' => false,
        ]);

        $audit = $this->audits->create($this->org->id, $this->admin, ['name' => 'Bins', 'audit_type' => 'full']);

        $this->assertSame([$plain->id], $audit->items()->pluck('product_id')->all());
    }

    public function test_the_screens_create_show_and_refuse_a_stale_completion(): void
    {
        SystemSetting::set('installed', true, 'boolean');

        $this->get(route('stock-audits.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('warehouses', 2));

        $this->post(route('stock-audits.store'), [
            'name' => 'Via Maragliano', 'audit_type' => 'spot', 'warehouse_id' => $this->saxone->id, 'product_ids' => [$this->shoe->id],
        ])->assertRedirect();
        $audit = StockAudit::sole();
        $this->assertSame($this->saxone->id, $audit->warehouse_id);

        $this->post(route('stock-audits.start', $audit))->assertSessionHas('success');
        $line = $this->line($audit, $this->size42->id);
        $this->postJson(route('stock-audits.items.count', [$audit, $line]), ['counted_quantity' => 4])
            ->assertOk()->assertJsonPath('item.system_quantity', 3);

        $this->get(route('stock-audits.show', $audit))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('audit.warehouse.name', $this->saxone->name)
                ->where('audit.items.0.warehouse.id', $this->saxone->id)
                ->where('audit.items.0.variant.sku', 'SUNDAY-BLACK-42'));

        $this->stockService()->adjust($this->size42, $this->saxone->id, null, 1, ['reason' => 'found']);
        $this->post(route('stock-audits.complete', $audit), ['allow_uncounted' => true])->assertSessionHas('error');
        $this->assertSame('recount', $line->fresh()->status);
    }

    public function test_counting_by_warehouse_needs_stock_per_warehouse(): void
    {
        VariantWarehouseStockSettings::save($this->org, new VariantWarehouseStockSettings(enabled: false, defaultWarehouseId: null, returnsWarehouseId: null));

        $this->expectException(InvalidStateException::class);
        $this->audits->create($this->org->id, $this->admin, ['name' => 'X', 'audit_type' => 'full', 'warehouse_id' => $this->saxone->id]);
    }
}
