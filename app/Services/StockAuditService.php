<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidStateException;
use App\Exceptions\VariantStockException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockAudit;
use App\Models\Inventory\StockAuditItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStockService;
use App\Support\VariantWarehouseStockSettings;
use Illuminate\Support\Facades\DB;

/**
 * The single implementation of the stock-audit (cycle count) write path:
 * create, start, record counts and complete.
 *
 * Shared by the web StockAuditController and the REST API so the snapshot of
 * system quantities, count recording and recount adjustments cannot drift
 * between surfaces. State violations throw InvalidStateException.
 *
 * Two kinds of audit:
 * - by bin (warehouse_location_id, or none): products, as before;
 * - by warehouse (warehouse_id, stock per warehouse): one line per variant
 *   with its units in that warehouse, all owners together. Each line keeps
 *   the figure it was counted against, and completion books it as an
 *   absolute count (the same path as a count on the Stock by warehouse
 *   screen, so a sales channel gets the same compare-and-set). A line whose
 *   stock moved after it was counted is not booked: the audit asks for it
 *   to be counted again.
 */
final class StockAuditService
{
    public function __construct(
        private readonly WarehouseAccessService $warehouseAccess,
        private readonly ProductLocationStockService $locationStock,
        private readonly VariantStockService $variantStock,
    ) {}

    /**
     * A restricted user acts on the audits of their own warehouses only: the
     * audited warehouse, or the warehouse of the audited bin.
     *
     * @api
     */
    public function authorizeAudit(User $actor, StockAudit $audit): void
    {
        $audit->warehouse_id !== null
            ? $this->warehouseAccess->authorizeWarehouse($actor, (int) $audit->warehouse_id)
            : $this->warehouseAccess->authorizeLocation($actor, $audit->warehouse_location_id);
    }

    /**
     * What the system says is on hand for an audit line: the audited bin's
     * quantity for a location-scoped audit, the product's total otherwise.
     */
    private function systemQuantity(StockAudit $audit, Product $product, ?StockAuditItem $item = null): int
    {
        if ($item?->warehouse_id !== null && $item->product_variant_id !== null) {
            return $this->variantStock->warehouseOnHand($item->variant, (int) $item->warehouse_id);
        }

        return $audit->warehouse_location_id !== null
            ? $this->locationStock->onHandAt($product, (int) $audit->warehouse_location_id)
            : (int) $product->stock;
    }

    /**
     * Create a draft audit and seed one item per product in scope.
     *
     * @param  array{name: string, description?: string|null, audit_type: string, warehouse_location_id?: int|null, warehouse_id?: int|null, notes?: string|null, product_ids?: array<int, int>|null}  $data
     *
     * @api
     */
    public function create(int $organizationId, User $actor, array $data): StockAudit
    {
        if (! empty($data['warehouse_id'])) {
            return $this->createForWarehouse($organizationId, $actor, $data);
        }

        // Verify location belongs to organization if provided
        if (! empty($data['warehouse_location_id'])) {
            ProductLocation::where('id', $data['warehouse_location_id'])
                ->forOrganization($organizationId)
                ->firstOrFail();
        }

        // A restricted user audits their own warehouses only; an audit with no
        // location spans the whole organization.
        $this->warehouseAccess->authorizeLocation($actor, $data['warehouse_location_id'] ?? null);

        return DB::transaction(function () use ($data, $organizationId, $actor) {
            $audit = StockAudit::create([
                'organization_id' => $organizationId,
                'audit_number' => StockAudit::generateAuditNumber($organizationId),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'audit_type' => $data['audit_type'],
                'warehouse_location_id' => $data['warehouse_location_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            // Get products to include in the audit. With stock per
            // warehouse, products with variants are counted by warehouse.
            $productQuery = Product::forOrganization($organizationId)->active()
                ->when(VariantWarehouseStockSettings::enabledFor($organizationId), fn ($q) => $q->where('has_variants', false));

            if (! empty($data['product_ids'])) {
                // Specific products selected
                $productQuery->whereIn('id', $data['product_ids']);
            } elseif (! empty($data['warehouse_location_id'])) {
                // Products assigned to the location, and products that have
                // stock binned there although their primary location is
                // elsewhere.
                $locationId = (int) $data['warehouse_location_id'];
                $productQuery->where(fn ($q) => $q
                    ->where('location_id', $locationId)
                    ->orWhereIn('id', ProductLocationStock::query()
                        ->select('product_id')
                        ->where('location_id', $locationId)
                        ->where('quantity', '>', 0)));
            }

            foreach ($productQuery->get() as $product) {
                StockAuditItem::create([
                    'stock_audit_id' => $audit->id,
                    'product_id' => $product->id,
                    // A location-scoped audit counts that bin; otherwise the
                    // line shows the product's primary location.
                    'location_id' => $audit->warehouse_location_id ?? $product->location_id,
                    'system_quantity' => $this->systemQuantity($audit, $product),
                    'status' => 'pending',
                ]);
            }

            return $audit;
        });
    }

    /**
     * A count of variants in one warehouse: one line per variant of the
     * chosen products (all products with variants when none are chosen).
     *
     * @param  array{name: string, description?: string|null, audit_type: string, warehouse_id: int, notes?: string|null, product_ids?: array<int, int>|null}  $data
     */
    private function createForWarehouse(int $organizationId, User $actor, array $data): StockAudit
    {
        if (! VariantWarehouseStockSettings::enabledFor($organizationId)) {
            throw new InvalidStateException('Counting by warehouse needs stock per warehouse to be enabled.', 'warehouse_stock_disabled');
        }
        if (! empty($data['warehouse_location_id'])) {
            throw new InvalidStateException('A count by warehouse counts the whole warehouse, not a bin.', 'warehouse_and_location');
        }

        $warehouseId = (int) $data['warehouse_id'];
        Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->where('organization_id', $organizationId)->firstOrFail();
        $this->warehouseAccess->authorizeWarehouse($actor, $warehouseId);

        return DB::transaction(function () use ($data, $organizationId, $actor, $warehouseId) {
            $audit = StockAudit::create([
                'organization_id' => $organizationId,
                'audit_number' => StockAudit::generateAuditNumber($organizationId),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'audit_type' => $data['audit_type'],
                'warehouse_id' => $warehouseId,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $variants = ProductVariant::query()
                ->whereHas('product', fn ($q) => $q->forOrganization($organizationId)->active()->where('has_variants', true)
                    ->when(! empty($data['product_ids']), fn ($q) => $q->whereIn('id', $data['product_ids'])))
                ->where('is_active', true)
                ->orderBy('product_id')->orderBy('position')->orderBy('id')
                ->get();

            foreach ($variants as $variant) {
                $item = new StockAuditItem([
                    'stock_audit_id' => $audit->id,
                    'product_id' => $variant->product_id,
                    'product_variant_id' => $variant->id,
                    'warehouse_id' => $warehouseId,
                    'status' => 'pending',
                ]);
                $item->system_quantity = $this->variantStock->warehouseOnHand($variant, $warehouseId);
                $item->save();
            }

            return $audit;
        });
    }

    /**
     * Start a draft audit, snapshotting current system quantities.
     *
     * @api
     */
    public function start(StockAudit $stockAudit, User $actor): StockAudit
    {
        $this->authorizeAudit($actor, $stockAudit);

        return DB::transaction(function () use ($stockAudit, $actor) {
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeAudit($actor, $locked);

            if ($locked->status !== 'draft') {
                throw new InvalidStateException('Only draft audits can be started.', 'invalid_status');
            }

            $locked->load('items.product', 'items.variant');
            if ($locked->items->isEmpty()) {
                throw new InvalidStateException('Cannot start an audit with no items.', 'no_items');
            }

            // Snapshot only after claiming the draft under the same lock
            // completion and counting use. A stale start cannot reopen it.
            foreach ($locked->items as $item) {
                $item->update(['system_quantity' => $this->systemQuantity($locked, $item->product, $item)]);
            }

            $locked->update([
                'status' => 'in_progress',
                'started_at' => now(),
            ]);
            $stockAudit->setRawAttributes($locked->getAttributes(), true);
            $stockAudit->setRelation('items', $locked->items);

            return $stockAudit;
        });
    }

    /**
     * Record the physical count for one audit item.
     *
     * @api
     */
    public function recordCount(StockAudit $stockAudit, StockAuditItem $item, User $actor, int $countedQuantity, ?string $notes = null): StockAuditItem
    {
        $this->authorizeAudit($actor, $stockAudit);

        return DB::transaction(function () use ($stockAudit, $item, $actor, $countedQuantity, $notes) {
            // Parent before item: serialize counts with completion so no
            // count can overwrite the evidence after its stock was booked.
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();
            $this->authorizeAudit($actor, $locked);

            if ($locked->status !== 'in_progress') {
                throw new InvalidStateException('Audit is not in progress', 'invalid_status');
            }

            $lockedItem = StockAuditItem::whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedItem->stock_audit_id !== $locked->id) {
                throw new InvalidStateException('Item does not belong to this audit', 'item_mismatch');
            }

            // A line of a count by warehouse is compared with the stock at
            // the moment it is counted: completion books it only if the
            // stock has not moved since.
            if ($lockedItem->warehouse_id !== null && $lockedItem->product_variant_id !== null) {
                $lockedItem->system_quantity = $this->systemQuantity($locked, $lockedItem->product, $lockedItem);
            }

            $lockedItem->update([
                'counted_quantity' => $countedQuantity,
                'discrepancy' => $countedQuantity - $lockedItem->system_quantity,
                'status' => 'counted',
                'counted_by' => $actor->id,
                'counted_at' => now(),
                'notes' => $notes ?? $lockedItem->notes,
            ]);
            $item->setRawAttributes($lockedItem->getAttributes(), true);

            return $item;
        });
    }

    /**
     * Complete an in-progress audit, booking a recount adjustment for every
     * counted item whose count differs from the system quantity.
     *
     * The location bins move with the total: a location-scoped audit books
     * each variance into the audited bin; an organization-wide audit lets
     * StockAdjustment::adjust() drain a shortfall from the bins in
     * fulfilment order and put an overage in the primary bin.
     *
     * Lines nobody counted never adjust stock. Completing while any are left
     * is refused unless the caller confirms it with $allowUncounted, so an
     * audit cannot be closed at 0% by accident.
     *
     * @return int the number of stock adjustments created
     *
     * A count by warehouse books each counted variant as an absolute count.
     * If the stock of some lines moved after they were counted, nothing is
     * booked: those lines are marked "recount" and the audit stays open.
     *
     * @throws InvalidStateException uncounted_items when lines are uncounted and not allowed
     * @throws InvalidStateException completion_vetoed when a stock_audit_completing filter refuses
     * @throws InvalidStateException stale_counts when lines must be counted again
     *
     * @api
     */
    public function complete(StockAudit $stockAudit, User $actor, bool $allowUncounted = false): int
    {
        $this->authorizeAudit($actor, $stockAudit);

        if ($stockAudit->status !== 'in_progress') {
            throw new InvalidStateException('Only in-progress audits can be completed.', 'invalid_status');
        }

        $adjustmentsCreated = 0;
        $stale = [];

        DB::transaction(function () use ($stockAudit, $actor, $allowUncounted, &$adjustmentsCreated, &$stale) {
            // Lock and re-read the audit so two concurrent completions
            // serialize on this row; the second waits, then sees the
            // 'completed' status and is rejected below — otherwise both
            // would re-apply every recount adjustment (double write).
            $locked = StockAudit::whereKey($stockAudit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'in_progress') {
                throw new InvalidStateException('Only in-progress audits can be completed.', 'invalid_status');
            }

            $locked->load('items.product', 'items.variant');

            $uncounted = $locked->items->whereNull('counted_quantity')->count();
            if ($uncounted > 0 && ! $allowUncounted) {
                throw new InvalidStateException(
                    "{$uncounted} of {$locked->items->count()} item(s) have not been counted. Count them, or confirm completing without them (uncounted items are left unchanged).",
                    'uncounted_items',
                );
            }

            // Plugins may refuse (or hold) the completion: true allows it, a
            // string is the reason it is refused, false a generic refusal.
            // Runs under the audit lock, before anything is booked.
            $verdict = apply_filters('stock_audit_completing', true, $locked, $actor, $allowUncounted);

            if ($verdict !== true) {
                throw new InvalidStateException(
                    is_string($verdict) && trim($verdict) !== ''
                        ? $verdict
                        : 'An installed plugin is not allowing this audit to be completed yet.',
                    'completion_vetoed',
                );
            }

            if ($locked->countsVariantsByWarehouse()) {
                [$adjustmentsCreated, $stale] = $this->completeByWarehouse($locked, $actor);
                if ($stale !== []) {
                    return;
                }
            }

            foreach ($locked->countsVariantsByWarehouse() ? [] : $locked->items as $item) {
                // Skip items that haven't been counted
                if ($item->counted_quantity === null) {
                    continue;
                }

                $discrepancy = $item->counted_quantity - $item->system_quantity;

                $item->update([
                    'discrepancy' => $discrepancy,
                    'status' => 'adjusted',
                ]);

                // Create stock adjustment if there's a discrepancy
                if ($discrepancy !== 0) {
                    StockAdjustment::adjust(
                        product: $item->product,
                        quantity: $discrepancy,
                        type: 'recount',
                        reason: "Stock audit: {$locked->audit_number}",
                        notes: "Audit '{$locked->name}' - System: {$item->system_quantity}, Counted: {$item->counted_quantity}",
                        reference: $locked,
                        actor: $actor,
                        locationId: $locked->warehouse_location_id,
                    );

                    $adjustmentsCreated++;
                }
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
        });

        if ($stale !== []) {
            throw new InvalidStateException(
                'The stock of '.count($stale).' line(s) changed after they were counted ('.implode(', ', array_slice($stale, 0, 10)).(count($stale) > 10 ? ', ...' : '').'). Count them again, then complete the audit.',
                'stale_counts',
            );
        }

        return $adjustmentsCreated;
    }

    /**
     * Book the counted lines of a count by warehouse. First checks every
     * line against the stock now: if any moved after it was counted,
     * nothing is booked, those lines are marked "recount" and their SKUs
     * returned.
     *
     * @return array{0: int, 1: list<string>} adjustments booked, stale SKUs
     */
    private function completeByWarehouse(StockAudit $audit, User $actor): array
    {
        $counted = $audit->items->filter(fn (StockAuditItem $i) => $i->counted_quantity !== null && $i->variant !== null);

        $stale = [];
        foreach ($counted as $item) {
            if ($this->variantStock->warehouseOnHand($item->variant, (int) $item->warehouse_id) !== $item->system_quantity) {
                $item->update(['status' => 'recount']);
                $stale[] = (string) ($item->variant->sku ?: $item->product?->sku);
            }
        }
        if ($stale !== []) {
            return [0, $stale];
        }

        $booked = 0;
        foreach ($counted as $item) {
            try {
                $operation = $this->variantStock->countWarehouse($item->variant, (int) $item->warehouse_id, (int) $item->counted_quantity, [
                    'expected_on_hand' => $item->system_quantity,
                    'user_id' => $actor->id,
                    'reason' => "Stock audit: {$audit->audit_number}",
                    'notes' => "Audit '{$audit->name}' - System: {$item->system_quantity}, Counted: {$item->counted_quantity}",
                    'idempotency_key' => "stock-audit:{$audit->id}:item:{$item->id}",
                    'legacy_type' => 'recount',
                    'legacy_reference' => $audit,
                    'meta' => ['stock_audit_id' => $audit->id, 'stock_audit_item_id' => $item->id],
                ]);
            } catch (VariantStockException $e) {
                // Checked above under the same transaction; kept as a guard.
                throw new InvalidStateException($e->getMessage(), 'stale_counts');
            }

            $item->update([
                'discrepancy' => $item->counted_quantity - $item->system_quantity,
                'status' => 'adjusted',
            ]);
            if ($operation !== null) {
                $booked++;
            }
        }

        return [$booked, []];
    }
}
