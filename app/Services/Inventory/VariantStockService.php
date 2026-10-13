<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\VariantStockException;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Inventory\StockOwner;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockMovement;
use App\Models\Inventory\VariantStockOperation;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Order\OrderItemAllocation;
use App\Models\Warehouse;
use App\Support\VariantWarehouseStockSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of variant stock per warehouse and owner.
 *
 * Every change goes through apply(): it locks the touched rows, writes the
 * ledger, refreshes the variant's stock cache (on_hand - committed over all
 * its rows, which keeps the old single number meaningful for totals and
 * reports) and fires `variant_stock_changed` after commit, which sales
 * channel plugins use to send the change on.
 *
 * An operation carries a source: `local` changes started in Inventoros are
 * sent to channels; `external` changes were reported by a channel and are
 * never sent back; `import` is the opening balance. An idempotency key makes
 * an operation apply once however often it is retried.
 */
class VariantStockService
{
    /**
     * Apply one operation.
     *
     * @param  list<array{variant: ProductVariant|int, warehouse_id: int, owner_id?: int|null, deltas: array<string, int>}>  $changes
     * @param  array{source?: string, idempotency_key?: string|null, user_id?: int|null, order_id?: int|null, transfer_id?: int|null, return_order_id?: int|null, mode?: string, reason?: string|null, notes?: string|null, meta?: array|null, allow_negative?: bool, legacy_ledger?: bool, legacy_type?: string|null, legacy_reference?: Model|null}  $context
     *
     * @throws InsufficientStockException when a change would make a row's available negative and allow_negative is false
     */
    public function apply(int $organizationId, string $type, array $changes, array $context = []): VariantStockOperation
    {
        $key = $context['idempotency_key'] ?? null;

        if ($key !== null && ($existing = $this->existingOperation($organizationId, $key)) !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => $this->applyLocked($organizationId, $type, $changes, $context));
        } catch (QueryException $e) {
            // Two workers raced on the same idempotency key: the loser
            // returns the winner's operation.
            if ($key !== null && ($existing = $this->existingOperation($organizationId, $key)) !== null) {
                return $existing;
            }

            throw $e;
        }
    }

    private function existingOperation(int $organizationId, string $key): ?VariantStockOperation
    {
        return VariantStockOperation::where('organization_id', $organizationId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @param  list<array{variant: ProductVariant|int, warehouse_id: int, owner_id?: int|null, deltas: array<string, int>}>  $changes
     * @param  array<string, mixed>  $context
     */
    private function applyLocked(int $organizationId, string $type, array $changes, array $context): VariantStockOperation
    {
        $operation = VariantStockOperation::create([
            'organization_id' => $organizationId,
            'type' => $type,
            'source' => $context['source'] ?? VariantStockOperation::SOURCE_LOCAL,
            'idempotency_key' => $context['idempotency_key'] ?? null,
            'user_id' => array_key_exists('user_id', $context) ? $context['user_id'] : auth()->id(),
            'order_id' => $context['order_id'] ?? null,
            'transfer_id' => $context['transfer_id'] ?? null,
            'return_order_id' => $context['return_order_id'] ?? null,
            'mode' => $context['mode'] ?? 'delta',
            'reason' => $context['reason'] ?? null,
            'notes' => $context['notes'] ?? null,
            'meta' => $context['meta'] ?? null,
        ]);

        $allowNegative = (bool) ($context['allow_negative'] ?? false);

        // Normalise and lock rows in a stable order so concurrent operations
        // touching the same rows cannot deadlock.
        $normalised = [];
        foreach ($changes as $change) {
            $variant = $change['variant'] instanceof ProductVariant
                ? $change['variant']
                : ProductVariant::withTrashed()->findOrFail($change['variant']);

            if ((int) $variant->organization_id !== $organizationId) {
                throw new VariantStockException('The variant belongs to another organization.');
            }

            $deltas = array_filter(
                array_intersect_key($change['deltas'], array_flip(VariantStockLevel::BUCKETS)),
                fn ($d) => (int) $d !== 0
            );

            if ($deltas === []) {
                continue;
            }

            $normalised[] = [
                'variant' => $variant,
                'warehouse_id' => (int) $change['warehouse_id'],
                'owner_id' => isset($change['owner_id']) ? (int) $change['owner_id'] : null,
                'deltas' => array_map('intval', $deltas),
            ];
        }

        usort($normalised, fn ($a, $b) => [$a['variant']->id, $a['warehouse_id'], $a['owner_id'] ?? 0]
            <=> [$b['variant']->id, $b['warehouse_id'], $b['owner_id'] ?? 0]);

        $touchedVariants = [];
        $now = now();

        foreach ($normalised as $change) {
            $level = $this->lockLevel($organizationId, $change['variant'], $change['warehouse_id'], $change['owner_id']);
            $availableBefore = $level->available;

            foreach ($change['deltas'] as $bucket => $delta) {
                $before = (int) $level->{$bucket};
                $level->{$bucket} = $before + $delta;

                VariantStockMovement::create([
                    'organization_id' => $organizationId,
                    'variant_stock_operation_id' => $operation->id,
                    'variant_stock_level_id' => $level->id,
                    'product_variant_id' => $level->product_variant_id,
                    'warehouse_id' => $level->warehouse_id,
                    'stock_owner_id' => $level->stock_owner_id,
                    'bucket' => $bucket,
                    'delta' => $delta,
                    'before' => $before,
                    'after' => $before + $delta,
                    'created_at' => $now,
                ]);
            }

            if (! $allowNegative && $level->available < 0 && $level->available < $availableBefore) {
                throw new InsufficientStockException(sprintf(
                    'Not enough available stock of %s in %s: %d available, %d needed.',
                    $change['variant']->sku ?? ('#'.$change['variant']->id),
                    $this->warehouseName($change['warehouse_id']),
                    max(0, $availableBefore),
                    $availableBefore - $level->available,
                ));
            }

            $level->save();
            $touchedVariants[$change['variant']->id] = $change['variant'];
        }

        foreach ($touchedVariants as $variant) {
            $this->refreshVariantCache($variant, $operation, $context);
        }

        $operation->setRelation('movements', $operation->movements()->get());

        DB::afterCommit(fn () => do_action('variant_stock_changed', $operation));

        return $operation;
    }

    /**
     * Get (creating if needed) and lock one level row.
     */
    private function lockLevel(int $organizationId, ProductVariant $variant, int $warehouseId, ?int $ownerId): VariantStockLevel
    {
        $query = fn () => VariantStockLevel::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouseId)
            ->where('owner_key', $ownerId ?? 0)
            ->lockForUpdate()
            ->first();

        $level = $query();

        if ($level === null) {
            $this->assertWarehouse($organizationId, $warehouseId);
            if ($ownerId !== null) {
                $this->assertOwner($organizationId, $ownerId);
            }

            VariantStockLevel::insertOrIgnore([
                'organization_id' => $organizationId,
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouseId,
                'stock_owner_id' => $ownerId,
                'owner_key' => $ownerId ?? 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $level = $query();
        }

        return $level;
    }

    /**
     * Bring product_variants.stock in line with the rows (on_hand -
     * committed), writing a classic ledger row so stock history and every
     * report built on it stay consistent.
     *
     * @param  array<string, mixed>  $context
     */
    private function refreshVariantCache(ProductVariant $variant, VariantStockOperation $operation, array $context): void
    {
        $locked = ProductVariant::withTrashed()->whereKey($variant->id)->lockForUpdate()->firstOrFail();

        $sum = (int) VariantStockLevel::where('product_variant_id', $variant->id)
            ->selectRaw('coalesce(sum(on_hand - committed), 0) as total')
            ->value('total');

        $before = (int) $locked->stock;

        if ($sum === $before) {
            return;
        }

        if ($context['legacy_ledger'] ?? true) {
            $reference = $context['legacy_reference'] ?? null;

            $legacy = StockAdjustment::create([
                'organization_id' => $locked->organization_id,
                'product_id' => $locked->product_id,
                'product_variant_id' => $locked->id,
                'user_id' => $operation->user_id,
                'type' => $context['legacy_type'] ?? $this->legacyType($operation->type),
                'quantity_before' => $before,
                'quantity_after' => $sum,
                'adjustment_quantity' => $sum - $before,
                'reason' => $operation->reason,
                'notes' => $operation->notes,
                'reference_type' => $reference ? $reference::class : VariantStockOperation::class,
                'reference_id' => $reference?->getKey() ?? $operation->id,
            ]);

            $operation->setRelation('legacyAdjustment', $legacy);
        }

        // Through the model so the observer reports stock_changed.
        $locked->stock = $sum;
        $locked->save();

        $variant->setRawAttributes(array_merge($variant->getAttributes(), ['stock' => $sum]));
        $variant->syncOriginal();
    }

    private function legacyType(string $type): string
    {
        return match ($type) {
            VariantStockOperation::TYPE_OPENING => 'opening_stock',
            VariantStockOperation::TYPE_COUNT => 'recount',
            VariantStockOperation::TYPE_ORDER_COMMIT => 'order_fulfillment',
            VariantStockOperation::TYPE_ORDER_RELEASE => 'order_cancellation',
            VariantStockOperation::TYPE_RETURN_RECEIVED => 'return',
            VariantStockOperation::TYPE_TRANSFER => 'transfer',
            VariantStockOperation::TYPE_CHANNEL_SYNC => 'channel_sync',
            default => 'manual',
        };
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /**
     * Rows of one variant, grouped by warehouse.
     *
     * @return Collection<int, VariantStockLevel>
     */
    public function levels(ProductVariant $variant): Collection
    {
        return VariantStockLevel::where('product_variant_id', $variant->id)
            ->orderBy('warehouse_id')->orderBy('owner_key')->get();
    }

    /**
     * Per-warehouse totals of one variant.
     *
     * @return array<int, array{on_hand: int, committed: int, quality_control: int, damaged: int, reserved: int, incoming: int, available: int, unavailable: int}>
     */
    public function totalsByWarehouse(ProductVariant $variant): array
    {
        $out = [];
        foreach ($this->levels($variant) as $level) {
            $row = $out[$level->warehouse_id] ?? array_fill_keys([...VariantStockLevel::BUCKETS, 'available', 'unavailable'], 0);
            foreach (VariantStockLevel::BUCKETS as $bucket) {
                $row[$bucket] += (int) $level->{$bucket};
            }
            $row['available'] += $level->available;
            $row['unavailable'] += $level->unavailable;
            $out[$level->warehouse_id] = $row;
        }

        return $out;
    }

    /**
     * Warehouses in the order units are taken from: the default warehouse
     * first, then by priority (higher first), then by id.
     *
     * @return Collection<int, Warehouse>
     */
    public function warehousesInPriority(int $organizationId): Collection
    {
        $settings = VariantWarehouseStockSettings::forOrganization($organizationId);
        $default = $settings->defaultWarehouseId;

        return Warehouse::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Warehouse $w) => [
                $default !== null ? ($w->id === $default ? 0 : 1) : ($w->is_default ? 0 : 1),
                -(int) $w->priority,
                $w->id,
            ])
            ->values();
    }

    public function defaultWarehouseId(int $organizationId): ?int
    {
        return $this->warehousesInPriority($organizationId)->first()?->id;
    }

    public function returnsWarehouseId(int $organizationId): ?int
    {
        return VariantWarehouseStockSettings::forOrganization($organizationId)->returnsWarehouseId
            ?? $this->defaultWarehouseId($organizationId);
    }

    // ------------------------------------------------------------------
    // Adjustments and counts
    // ------------------------------------------------------------------

    /**
     * Change on-hand by a delta (a movement: received, found, broken...).
     *
     * @param  array<string, mixed>  $context
     */
    public function adjust(ProductVariant $variant, int $warehouseId, ?int $ownerId, int $delta, array $context = []): VariantStockOperation
    {
        $type = $context['type'] ?? VariantStockOperation::TYPE_ADJUSTMENT;
        unset($context['type']);

        // A removal that names no owner takes free units wherever they are
        // in the warehouse: units still to be attributed first, then the
        // owners with the most free units. What is left (when negative stock
        // is allowed) comes off the "to be attributed" row.
        if ($delta < 0 && $ownerId === null && ($context['spread_owners'] ?? true)) {
            return DB::transaction(function () use ($variant, $warehouseId, $delta, $context, $type) {
                $rows = VariantStockLevel::where('product_variant_id', $variant->id)
                    ->where('warehouse_id', $warehouseId)->lockForUpdate()->get()
                    ->sortBy(fn (VariantStockLevel $l) => [$l->owner_key === 0 ? 0 : 1, -$l->available, $l->owner_key]);

                $changes = [];
                $left = -$delta;
                foreach ($rows as $row) {
                    $take = min(max(0, $row->available), $left);
                    if ($take > 0) {
                        $changes[] = ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => $row->stock_owner_id, 'deltas' => ['on_hand' => -$take]];
                        $left -= $take;
                    }
                }
                if ($left > 0) {
                    $changes[] = ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => null, 'deltas' => ['on_hand' => -$left]];
                }

                return $this->apply((int) $variant->organization_id, $type, $changes, $context + ['mode' => 'delta']);
            });
        }

        return $this->apply((int) $variant->organization_id, $type, [[
            'variant' => $variant,
            'warehouse_id' => $warehouseId,
            'owner_id' => $ownerId,
            'deltas' => ['on_hand' => $delta],
        ]], $context + ['mode' => 'delta']);
    }

    /**
     * Set on-hand to a counted figure (an absolute correction). The figure
     * the operator saw is kept so a channel can refuse it if the stock moved
     * meanwhile.
     *
     * @param  array<string, mixed>  $context
     */
    public function setCount(ProductVariant $variant, int $warehouseId, ?int $ownerId, int $counted, array $context = []): VariantStockOperation
    {
        return DB::transaction(function () use ($variant, $warehouseId, $ownerId, $counted, $context) {
            $level = $this->lockLevel((int) $variant->organization_id, $variant, $warehouseId, $ownerId);

            // The figure the operator was looking at, when given, must still
            // be the current one: otherwise a sale or another change slipped
            // in and the count needs a second look.
            if (array_key_exists('expected_on_hand', $context) && $context['expected_on_hand'] !== null
                && (int) $context['expected_on_hand'] !== (int) $level->on_hand) {
                throw new VariantStockException(sprintf(
                    'The stock changed while you were counting: it was %d, it is now %d. Check the count again.',
                    (int) $context['expected_on_hand'],
                    (int) $level->on_hand,
                ));
            }

            $warehouseTotal = (int) VariantStockLevel::where('product_variant_id', $variant->id)
                ->where('warehouse_id', $warehouseId)->sum('on_hand');

            $meta = ($context['meta'] ?? []) + [
                'counted' => $counted,
                'on_hand_before' => (int) $level->on_hand,
                'warehouse_on_hand_before' => $warehouseTotal,
            ];

            return $this->apply((int) $variant->organization_id, VariantStockOperation::TYPE_COUNT, [[
                'variant' => $variant,
                'warehouse_id' => $warehouseId,
                'owner_id' => $ownerId,
                'deltas' => ['on_hand' => $counted - (int) $level->on_hand],
            ]], ['mode' => 'absolute', 'meta' => $meta, 'allow_negative' => true] + $context);
        });
    }

    /**
     * Physical units of a variant in a warehouse, all owners together.
     */
    public function warehouseOnHand(ProductVariant $variant, int $warehouseId): int
    {
        return (int) VariantStockLevel::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouseId)->sum('on_hand');
    }

    /**
     * Set a variant's on-hand in a warehouse, all owners together, to a
     * counted figure (an absolute correction, as a stock audit counts the
     * shelf without knowing whose each pair is). Units found go to "to be
     * attributed"; units missing come off "to be attributed" first, then
     * the owners with the most free units.
     *
     * $context['expected_on_hand'], when given, is the warehouse figure the
     * count was taken against: if the stock moved since, the count is
     * refused. Returns null when the count matches the stock.
     *
     * @param  array<string, mixed>  $context
     */
    public function countWarehouse(ProductVariant $variant, int $warehouseId, int $counted, array $context = []): ?VariantStockOperation
    {
        return DB::transaction(function () use ($variant, $warehouseId, $counted, $context) {
            $rows = VariantStockLevel::where('product_variant_id', $variant->id)
                ->where('warehouse_id', $warehouseId)->orderBy('id')->lockForUpdate()->get();
            $before = (int) $rows->sum('on_hand');

            if (array_key_exists('expected_on_hand', $context) && $context['expected_on_hand'] !== null
                && (int) $context['expected_on_hand'] !== $before) {
                throw new VariantStockException(sprintf(
                    'The stock changed after it was counted: it was %d, it is now %d. Count it again.',
                    (int) $context['expected_on_hand'],
                    $before,
                ));
            }

            $delta = $counted - $before;
            if ($delta === 0) {
                return null;
            }

            $changes = [];
            if ($delta > 0) {
                $changes[] = ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => null, 'deltas' => ['on_hand' => $delta]];
            } else {
                $left = -$delta;
                $order = $rows->sortBy(fn (VariantStockLevel $l) => [$l->owner_key === 0 ? 0 : 1, -$l->available, $l->owner_key]);
                foreach ($order as $row) {
                    $take = min(max(0, $row->available), $left);
                    if ($take > 0) {
                        $changes[] = ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => $row->stock_owner_id, 'deltas' => ['on_hand' => -$take]];
                        $left -= $take;
                    }
                }
                // Fewer units than are committed or set aside: the rest
                // comes off "to be attributed", which may go negative.
                if ($left > 0) {
                    $changes[] = ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => null, 'deltas' => ['on_hand' => -$left]];
                }
            }

            unset($context['expected_on_hand']);
            $meta = ($context['meta'] ?? []) + [
                'counted' => $counted,
                'warehouse_on_hand_before' => $before,
            ];

            return $this->apply((int) $variant->organization_id, VariantStockOperation::TYPE_COUNT, $changes,
                ['mode' => 'absolute', 'meta' => $meta, 'allow_negative' => true] + $context);
        });
    }

    /**
     * Move units between states inside one row (e.g. available -> damaged).
     * Use 'available' as a pseudo-state: it is what is left of on_hand.
     *
     * @param  array<string, mixed>  $context
     */
    public function changeState(ProductVariant $variant, int $warehouseId, ?int $ownerId, string $from, string $to, int $quantity, array $context = []): VariantStockOperation
    {
        $states = ['available', ...VariantStockLevel::UNAVAILABLE_BUCKETS];
        if (! in_array($from, $states, true) || ! in_array($to, $states, true) || $from === $to || $quantity <= 0) {
            throw new VariantStockException('Invalid state change.');
        }

        $deltas = [];
        if ($from !== 'available') {
            $deltas[$from] = -$quantity;
        }
        if ($to !== 'available') {
            $deltas[$to] = $quantity;
        }

        return DB::transaction(function () use ($variant, $warehouseId, $ownerId, $from, $quantity, $deltas, $context) {
            if ($from !== 'available') {
                $level = $this->lockLevel((int) $variant->organization_id, $variant, $warehouseId, $ownerId);
                if ((int) $level->{$from} < $quantity) {
                    throw new VariantStockException("Only {$level->{$from}} units are in that state.");
                }
            }

            return $this->apply((int) $variant->organization_id, $context['type'] ?? VariantStockOperation::TYPE_STATE_CHANGE, [[
                'variant' => $variant,
                'warehouse_id' => $warehouseId,
                'owner_id' => $ownerId,
                'deltas' => $deltas,
            ]], $context + ['meta' => ['from' => $from, 'to' => $context['to_state'] ?? null]]);
        });
    }

    /**
     * Finish the inspection of returned units: back on sale, or kept
     * unavailable (damaged / reserved) with a reason.
     *
     * @param  'available'|'damaged'|'reserved'  $outcome
     * @param  array<string, mixed>  $context
     */
    public function inspect(ProductVariant $variant, int $warehouseId, ?int $ownerId, int $quantity, string $outcome, ?string $reason, array $context = []): VariantStockOperation
    {
        if (! in_array($outcome, ['available', 'damaged', 'reserved'], true)) {
            throw new VariantStockException('Unknown inspection outcome.');
        }
        if ($outcome !== 'available' && trim((string) $reason) === '') {
            throw new VariantStockException('Say why the units stay unavailable.');
        }

        return $this->changeState($variant, $warehouseId, $ownerId, 'quality_control', $outcome, $quantity, [
            'type' => VariantStockOperation::TYPE_INSPECTION,
            'reason' => $reason,
            'to_state' => $outcome,
        ] + $context);
    }

    /**
     * Hand units from one owner to another in the same warehouse. On-hand
     * per warehouse does not change, so nothing is sent to sales channels.
     *
     * @param  array<string, mixed>  $context
     */
    public function changeOwner(ProductVariant $variant, int $warehouseId, ?int $fromOwnerId, ?int $toOwnerId, int $quantity, array $context = []): VariantStockOperation
    {
        if ($quantity <= 0 || $fromOwnerId === $toOwnerId) {
            throw new VariantStockException('Choose a quantity and two different owners.');
        }

        return DB::transaction(function () use ($variant, $warehouseId, $fromOwnerId, $toOwnerId, $quantity, $context) {
            $from = $this->lockLevel((int) $variant->organization_id, $variant, $warehouseId, $fromOwnerId);

            // Only free units change hands: committed or unsellable units
            // stay with their owner until they are released.
            if ($from->available < $quantity) {
                throw new VariantStockException(sprintf(
                    'Only %d free units of this owner are in the warehouse.',
                    max(0, $from->available),
                ));
            }

            return $this->apply((int) $variant->organization_id, VariantStockOperation::TYPE_OWNER_CHANGE, [
                ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => $fromOwnerId, 'deltas' => ['on_hand' => -$quantity]],
                ['variant' => $variant, 'warehouse_id' => $warehouseId, 'owner_id' => $toOwnerId, 'deltas' => ['on_hand' => $quantity]],
            ], $context + ['legacy_ledger' => false]);
        });
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    /**
     * Commit units of a variant order line. With a plan (warehouse =>
     * quantity) the units are taken where the plan says, e.g. where the
     * sales channel assigned them; otherwise from the warehouses in
     * priority order. Inside a warehouse, from the owner with the most free
     * units.
     *
     * With $allowShortfall the line is committed even when there is not
     * enough available (the channel has already sold it); the missing units
     * are recorded as a shortfall and show as negative available.
     *
     * @param  array<int, int>|null  $plan  warehouse id => quantity; when given, exactly these units are committed
     * @param  array<string, mixed>  $context
     * @return Collection<int, OrderItemAllocation>
     */
    public function commitOrderItem(OrderItem $item, int $quantity, ?array $plan = null, bool $allowShortfall = false, array $context = []): Collection
    {
        if ($item->product_variant_id === null || $quantity <= 0) {
            return collect();
        }

        $variant = ProductVariant::withTrashed()->findOrFail($item->product_variant_id);
        $orgId = (int) $variant->organization_id;

        if (isset($context['idempotency_key']) && $this->existingOperation($orgId, $context['idempotency_key']) !== null) {
            return OrderItemAllocation::where('order_item_id', $item->id)->get();
        }

        return DB::transaction(function () use ($item, $quantity, $plan, $allowShortfall, $context, $variant, $orgId) {
            $warehouses = $this->warehousesInPriority($orgId)->pluck('id')->all();

            if ($warehouses === []) {
                throw new VariantStockException('No active warehouse to take the stock from.');
            }

            // Lock every row of the variant first.
            $rows = VariantStockLevel::where('product_variant_id', $variant->id)->lockForUpdate()->get();

            $planned = $plan ?? null;
            $picks = []; // [warehouse, owner, qty, shortfall]
            $remaining = $quantity;

            $takeFrom = function (int $warehouseId, int $wanted) use ($rows, &$picks): int {
                $taken = 0;
                $candidates = $rows->where('warehouse_id', $warehouseId)
                    ->sortBy(fn (VariantStockLevel $l) => [-$l->available, $l->owner_key]);

                foreach ($candidates as $level) {
                    $free = $level->available - $this->pickedFrom($picks, $warehouseId, $level->stock_owner_id);
                    if ($free <= 0 || $taken >= $wanted) {
                        continue;
                    }
                    $qty = min($free, $wanted - $taken);
                    $picks[] = ['warehouse_id' => $warehouseId, 'owner_id' => $level->stock_owner_id, 'quantity' => $qty, 'shortfall' => 0];
                    $taken += $qty;
                }

                return $taken;
            };

            if ($planned !== null) {
                foreach ($planned as $warehouseId => $wanted) {
                    $wanted = (int) $wanted;
                    if ($wanted <= 0) {
                        continue;
                    }
                    $taken = $takeFrom((int) $warehouseId, $wanted);
                    if ($taken < $wanted) {
                        if (! $allowShortfall) {
                            throw new InsufficientStockException(sprintf(
                                'Not enough available stock of %s in %s: %d available, %d needed.',
                                $variant->sku, $this->warehouseName((int) $warehouseId), $taken, $wanted,
                            ));
                        }
                        $picks[] = ['warehouse_id' => (int) $warehouseId, 'owner_id' => null, 'quantity' => $wanted - $taken, 'shortfall' => $wanted - $taken];
                    }
                    $remaining -= $wanted;
                }

                // A plan is the whole truth: units it does not place (e.g.
                // already fulfilled before the order reached Inventoros)
                // are not committed anywhere.
                $remaining = 0;
            }

            foreach ($remaining > 0 ? $warehouses : [] as $warehouseId) {
                $remaining -= $takeFrom($warehouseId, $remaining);
                if ($remaining <= 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                if (! $allowShortfall) {
                    throw new InsufficientStockException(sprintf(
                        'Insufficient stock for %s. Available: %d, Requested: %d',
                        $variant->sku, $quantity - $remaining, $quantity,
                    ));
                }
                $picks[] = ['warehouse_id' => $warehouses[0], 'owner_id' => null, 'quantity' => $remaining, 'shortfall' => $remaining];
            }

            // Merge picks per (warehouse, owner).
            $merged = [];
            foreach ($picks as $pick) {
                $k = $pick['warehouse_id'].'|'.($pick['owner_id'] ?? 0);
                $merged[$k] ??= ['warehouse_id' => $pick['warehouse_id'], 'owner_id' => $pick['owner_id'], 'quantity' => 0, 'shortfall' => 0];
                $merged[$k]['quantity'] += $pick['quantity'];
                $merged[$k]['shortfall'] += $pick['shortfall'];
            }

            $this->apply($orgId, VariantStockOperation::TYPE_ORDER_COMMIT, array_map(fn ($m) => [
                'variant' => $variant,
                'warehouse_id' => $m['warehouse_id'],
                'owner_id' => $m['owner_id'],
                'deltas' => ['committed' => $m['quantity']],
            ], array_values($merged)), [
                'order_id' => $item->order_id,
                'allow_negative' => true,
                'idempotency_key' => isset($context['idempotency_key']) ? $context['idempotency_key'] : null,
            ] + $context);

            $allocations = collect();
            foreach ($merged as $m) {
                $allocations->push(OrderItemAllocation::create([
                    'organization_id' => $orgId,
                    'order_id' => $item->order_id,
                    'order_item_id' => $item->id,
                    'product_variant_id' => $variant->id,
                    'warehouse_id' => $m['warehouse_id'],
                    'stock_owner_id' => $m['owner_id'],
                    'quantity' => $m['quantity'],
                    'shortfall_quantity' => $m['shortfall'],
                ]));
            }

            return $allocations;
        });
    }

    /**
     * @param  list<array{warehouse_id: int, owner_id: int|null, quantity: int, shortfall: int}>  $picks
     */
    private function pickedFrom(array $picks, int $warehouseId, ?int $ownerId): int
    {
        $sum = 0;
        foreach ($picks as $pick) {
            if ($pick['warehouse_id'] === $warehouseId && $pick['owner_id'] === $ownerId) {
                $sum += $pick['quantity'];
            }
        }

        return $sum;
    }

    /**
     * Give back committed units of a line that will not ship (cancelled or
     * reduced). The most recent allocations, i.e. the furthest warehouses,
     * are released first.
     *
     * @param  array<string, mixed>  $context
     * @return int units released
     */
    public function releaseOrderItem(OrderItem $item, int $quantity, array $context = []): int
    {
        if ($item->product_variant_id === null || $quantity <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($item, $quantity, $context) {
            $allocations = OrderItemAllocation::where('order_item_id', $item->id)
                ->lockForUpdate()->orderByDesc('id')->get();

            $changes = [];
            $left = $quantity;
            foreach ($allocations as $allocation) {
                $open = $allocation->open_quantity;
                if ($open <= 0 || $left <= 0) {
                    continue;
                }
                $qty = min($open, $left);
                $allocation->released_quantity += $qty;
                $allocation->save();
                $changes[] = [
                    'variant' => (int) $allocation->product_variant_id,
                    'warehouse_id' => (int) $allocation->warehouse_id,
                    'owner_id' => $allocation->stock_owner_id,
                    'deltas' => ['committed' => -$qty],
                ];
                $left -= $qty;
            }

            if ($changes !== []) {
                $this->apply((int) $item->order->organization_id, VariantStockOperation::TYPE_ORDER_RELEASE, $changes, [
                    'order_id' => $item->order_id,
                    'allow_negative' => true,
                ] + $context);
            }

            return $quantity - $left;
        });
    }

    /**
     * Make an order's open commitments match where a sales channel has
     * assigned its units (plan: variant id => warehouse id => units still
     * to fulfil). Lines already matching are left alone; others are
     * released and committed again where the plan says. Returns whether
     * anything changed.
     *
     * @param  array<int, array<int, int>>  $planByVariant
     * @param  array<string, mixed>  $context
     */
    public function realignOrder(Order $order, array $planByVariant, array $context = []): bool
    {
        $items = OrderItem::where('order_id', $order->id)->whereNotNull('product_variant_id')->orderBy('id')->get();
        $changed = false;

        DB::transaction(function () use ($items, $planByVariant, $context, &$changed) {
            foreach ($items->groupBy('product_variant_id') as $variantId => $variantItems) {
                $current = [];
                foreach ($variantItems as $item) {
                    foreach ($this->openByWarehouse($item) as $warehouseId => $qty) {
                        $current[$warehouseId] = ($current[$warehouseId] ?? 0) + $qty;
                    }
                }

                $desired = array_filter(array_map('intval', $planByVariant[(int) $variantId] ?? []));
                ksort($current);
                ksort($desired);

                if ($current === $desired) {
                    continue;
                }

                $changed = true;

                foreach ($variantItems as $item) {
                    $open = array_sum($this->openByWarehouse($item));
                    if ($open > 0) {
                        $this->releaseOrderItem($item, $open, $context);
                    }
                }

                // Hand the planned units out over the lines, each up to what
                // it still has to ship.
                foreach ($variantItems as $item) {
                    $fulfilled = (int) OrderItemAllocation::where('order_item_id', $item->id)->sum('fulfilled_quantity');
                    $room = max(0, (int) $item->quantity - $fulfilled);
                    $portion = [];
                    foreach ($desired as $warehouseId => $qty) {
                        if ($room <= 0) {
                            break;
                        }
                        $take = min($qty, $room);
                        if ($take > 0) {
                            $portion[$warehouseId] = $take;
                            $desired[$warehouseId] -= $take;
                            $room -= $take;
                        }
                    }
                    $desired = array_filter($desired);

                    if ($portion !== []) {
                        $this->commitOrderItem($item, array_sum($portion), $portion, true, $context);
                    }
                }
            }
        });

        return $changed;
    }

    /**
     * Units of a line still committed, per warehouse.
     *
     * @return array<int, int>
     */
    public function openByWarehouse(OrderItem $item): array
    {
        $out = [];
        foreach (OrderItemAllocation::where('order_item_id', $item->id)->get() as $allocation) {
            $out[$allocation->warehouse_id] = ($out[$allocation->warehouse_id] ?? 0) + $allocation->open_quantity;
        }

        return array_filter($out);
    }

    /**
     * Units of each variant line of an order that are not yet in the given
     * warehouse: what has to be brought there before one shipment can
     * leave.
     *
     * @return list<array{order_item_id: int, product_variant_id: int, sku: string|null, warehouse_id: int, owner_id: int|null, quantity: int}>
     */
    public function consolidationPlan(Order $order, int $targetWarehouseId): array
    {
        $plan = [];
        $allocations = OrderItemAllocation::where('order_id', $order->id)->orderBy('id')->get();

        foreach ($allocations as $allocation) {
            if ($allocation->warehouse_id === $targetWarehouseId || $allocation->open_quantity <= 0) {
                continue;
            }
            $plan[] = [
                'order_item_id' => (int) $allocation->order_item_id,
                'product_variant_id' => (int) $allocation->product_variant_id,
                'sku' => $allocation->variant?->sku,
                'warehouse_id' => (int) $allocation->warehouse_id,
                'owner_id' => $allocation->stock_owner_id,
                'quantity' => $allocation->open_quantity,
                'allocation_id' => (int) $allocation->id,
            ];
        }

        return $plan;
    }

    /**
     * Whether every committed unit of the order is in the warehouse.
     */
    public function isConsolidatedAt(Order $order, int $warehouseId): bool
    {
        return $this->consolidationPlan($order, $warehouseId) === [];
    }

    /**
     * Fulfil (ship) units of a line from a warehouse: on-hand and committed
     * both drop there.
     *
     * Strict (a shipment made in Inventoros): every unit must already be
     * committed in that warehouse; units still elsewhere must be brought
     * over first with a consolidation transfer.
     *
     * Not strict (a sales channel reports it already shipped): units
     * committed elsewhere are taken where they are recorded and the gap is
     * written as a correction, so the books follow what really happened.
     *
     * @param  array<string, mixed>  $context
     */
    public function fulfilOrderItem(OrderItem $item, int $quantity, int $warehouseId, bool $strict = true, array $context = []): void
    {
        if ($item->product_variant_id === null || $quantity <= 0) {
            return;
        }

        if (isset($context['idempotency_key'])
            && $this->existingOperation((int) $item->order->organization_id, $context['idempotency_key']) !== null) {
            return;
        }

        DB::transaction(function () use ($item, $quantity, $warehouseId, $strict, $context) {
            $allocations = OrderItemAllocation::where('order_item_id', $item->id)
                ->lockForUpdate()->orderBy('id')->get();

            $here = $allocations->where('warehouse_id', $warehouseId);
            $openHere = $here->sum(fn ($a) => $a->open_quantity);

            if ($strict && $openHere < $quantity) {
                throw new VariantStockException(sprintf(
                    '%s: %d of %d units are not yet in %s. Bring them over (consolidation transfer) before shipping.',
                    $item->sku ?? $item->product_name,
                    $quantity - $openHere,
                    $quantity,
                    $this->warehouseName($warehouseId),
                ));
            }

            $changes = [];
            $left = $quantity;

            // Units committed in the shipping warehouse first, then (not
            // strict) wherever else they were committed.
            $ordered = $here->concat($allocations->where('warehouse_id', '!=', $warehouseId));

            foreach ($ordered as $allocation) {
                $open = $allocation->open_quantity;
                if ($open <= 0 || $left <= 0) {
                    continue;
                }
                $qty = min($open, $left);
                $allocation->fulfilled_quantity += $qty;
                $allocation->save();

                if ((int) $allocation->warehouse_id === $warehouseId) {
                    $changes[] = ['variant' => (int) $allocation->product_variant_id, 'warehouse_id' => $warehouseId,
                        'owner_id' => $allocation->stock_owner_id, 'deltas' => ['on_hand' => -$qty, 'committed' => -$qty]];
                } else {
                    // Committed in A, shipped from B: free A's commitment and
                    // take the units out of B.
                    $changes[] = ['variant' => (int) $allocation->product_variant_id, 'warehouse_id' => (int) $allocation->warehouse_id,
                        'owner_id' => $allocation->stock_owner_id, 'deltas' => ['committed' => -$qty]];
                    $changes[] = ['variant' => (int) $allocation->product_variant_id, 'warehouse_id' => $warehouseId,
                        'owner_id' => $allocation->stock_owner_id, 'deltas' => ['on_hand' => -$qty]];
                    $allocation->warehouse_id = $warehouseId;
                    $allocation->save();
                }
                $left -= $qty;
            }

            if ($left > 0) {
                // Shipped more than was ever committed (e.g. an order taken
                // without stock): the units still leave the warehouse.
                $changes[] = ['variant' => (int) $item->product_variant_id, 'warehouse_id' => $warehouseId,
                    'owner_id' => null, 'deltas' => ['on_hand' => -$left]];
            }

            $this->apply((int) $item->order->organization_id, VariantStockOperation::TYPE_ORDER_FULFIL, $changes, [
                'order_id' => $item->order_id,
                'allow_negative' => true,
                'meta' => ['order_item_id' => $item->id, 'quantity' => $quantity, 'strict' => $strict],
            ] + $context);
        });
    }

    /**
     * Book returned units in the returns warehouse, in quality control,
     * with the owner they had when they were sold (unknown stays unknown).
     *
     * @param  array<string, mixed>  $context
     */
    public function receiveReturn(OrderItem $item, int $quantity, ?int $warehouseId = null, array $context = []): ?VariantStockOperation
    {
        if ($item->product_variant_id === null || $quantity <= 0) {
            return null;
        }

        $orgId = (int) $item->order->organization_id;
        $warehouseId ??= $this->returnsWarehouseId($orgId);

        if ($warehouseId === null) {
            throw new VariantStockException('No returns warehouse is set.');
        }

        if (isset($context['idempotency_key']) && ($done = $this->existingOperation($orgId, $context['idempotency_key'])) !== null) {
            return $done;
        }

        return DB::transaction(function () use ($item, $quantity, $warehouseId, $context, $orgId) {
            $allocations = OrderItemAllocation::where('order_item_id', $item->id)
                ->lockForUpdate()->orderBy('id')->get();

            $byOwner = [];
            $left = $quantity;
            foreach ($allocations as $allocation) {
                $returnable = (int) $allocation->fulfilled_quantity - (int) $allocation->returned_quantity;
                if ($returnable <= 0 || $left <= 0) {
                    continue;
                }
                $qty = min($returnable, $left);
                $allocation->returned_quantity += $qty;
                $allocation->save();
                $owner = $allocation->stock_owner_id;
                $byOwner[$owner ?? 0] = ['owner' => $owner, 'qty' => ($byOwner[$owner ?? 0]['qty'] ?? 0) + $qty];
                $left -= $qty;
            }

            if ($left > 0) {
                // Units sold before allocations existed: owner unknown.
                $byOwner[0] = ['owner' => null, 'qty' => ($byOwner[0]['qty'] ?? 0) + $left];
            }

            return $this->apply($orgId, VariantStockOperation::TYPE_RETURN_RECEIVED, array_values(array_map(fn ($o) => [
                'variant' => (int) $item->product_variant_id,
                'warehouse_id' => $warehouseId,
                'owner_id' => $o['owner'],
                'deltas' => ['on_hand' => $o['qty'], 'quality_control' => $o['qty']],
            ], $byOwner)), ['order_id' => $item->order_id, 'allow_negative' => true] + $context);
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function warehouseName(int $warehouseId): string
    {
        return (string) (Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->value('name') ?? "#{$warehouseId}");
    }

    private function assertWarehouse(int $organizationId, int $warehouseId): void
    {
        $ok = Warehouse::withoutGlobalScopes()->whereKey($warehouseId)
            ->where('organization_id', $organizationId)->exists();

        if (! $ok) {
            throw new VariantStockException('Unknown warehouse.');
        }
    }

    private function assertOwner(int $organizationId, int $ownerId): void
    {
        if (! StockOwner::whereKey($ownerId)->where('organization_id', $organizationId)->exists()) {
            throw new VariantStockException('Unknown stock owner.');
        }
    }
}
