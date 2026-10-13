<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\VariantStockException;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockOperation;
use App\Models\Inventory\VariantStockTransfer;
use App\Models\Inventory\VariantStockTransferLine;
use App\Models\Order\Order;
use App\Models\Order\OrderItemAllocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Immediate transfers of variant units between warehouses.
 *
 * One confirmation moves the units: on-hand leaves the origin and arrives at
 * the destination in the same database transaction, with the same owner.
 * The sales channel side is followed separately on the transfer
 * (channel_status), so a transfer is never shown as fully done while a
 * channel has not confirmed it.
 *
 * A consolidation transfer brings the units committed to an order into the
 * shipping warehouse: they stay committed (reserved for the order) all the
 * way, and the order's allocations follow them.
 */
class VariantTransferService
{
    public function __construct(private readonly VariantStockService $stock) {}

    /**
     * @param  list<array{product_variant_id: int, owner_id?: int|null, quantity: int}>  $lines
     */
    public function transfer(int $organizationId, int $fromWarehouseId, int $toWarehouseId, array $lines, ?User $user = null, ?string $notes = null): VariantStockTransfer
    {
        $this->assertWarehouses($organizationId, $fromWarehouseId, $toWarehouseId);

        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));
        if ($lines === []) {
            throw new VariantStockException('Add at least one line with a quantity.');
        }

        return DB::transaction(function () use ($organizationId, $fromWarehouseId, $toWarehouseId, $lines, $user, $notes) {
            $transfer = VariantStockTransfer::create([
                'organization_id' => $organizationId,
                'reference' => VariantStockTransfer::nextReference($organizationId),
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'user_id' => $user?->id ?? auth()->id(),
                'status' => 'completed',
                'notes' => $notes,
                'completed_at' => now(),
            ]);

            $changes = [];
            foreach ($lines as $line) {
                $variant = ProductVariant::findOrFail((int) $line['product_variant_id']);
                if ((int) $variant->organization_id !== $organizationId) {
                    throw new VariantStockException('The variant belongs to another organization.');
                }
                $owner = isset($line['owner_id']) ? (int) $line['owner_id'] : null;
                $qty = (int) $line['quantity'];

                VariantStockTransferLine::create([
                    'variant_stock_transfer_id' => $transfer->id,
                    'product_variant_id' => $variant->id,
                    'stock_owner_id' => $owner,
                    'quantity' => $qty,
                ]);

                $changes[] = ['variant' => $variant, 'warehouse_id' => $fromWarehouseId, 'owner_id' => $owner, 'deltas' => ['on_hand' => -$qty]];
                $changes[] = ['variant' => $variant, 'warehouse_id' => $toWarehouseId, 'owner_id' => $owner, 'deltas' => ['on_hand' => $qty]];
            }

            // Free units only: available at the origin must cover the move.
            $this->stock->apply($organizationId, VariantStockOperation::TYPE_TRANSFER, $changes, [
                'transfer_id' => $transfer->id,
                'user_id' => $user?->id ?? auth()->id(),
                'reason' => "Transfer {$transfer->reference}",
                'notes' => $notes,
                'legacy_ledger' => false,
                'idempotency_key' => "transfer:{$transfer->id}",
            ]);

            return $transfer->load('lines');
        });
    }

    /**
     * Bring the committed units of an order that are elsewhere into the
     * target warehouse. Units stay committed; allocations move with them.
     * Only the lines in $only (allocation ids) when given, so the operator
     * can move what has physically arrived.
     *
     * @param  list<int>|null  $only
     * @return list<VariantStockTransfer> one per origin warehouse
     */
    public function consolidate(Order $order, int $targetWarehouseId, ?User $user = null, ?array $only = null): array
    {
        $orgId = (int) $order->organization_id;
        $plan = $this->stock->consolidationPlan($order, $targetWarehouseId);

        if ($only !== null) {
            $plan = array_values(array_filter($plan, fn ($p) => in_array($p['allocation_id'], $only, true)));
        }

        if ($plan === []) {
            return [];
        }

        return DB::transaction(function () use ($order, $orgId, $targetWarehouseId, $plan, $user) {
            $transfers = [];

            foreach (collect($plan)->groupBy('warehouse_id') as $fromWarehouseId => $lines) {
                $this->assertWarehouses($orgId, (int) $fromWarehouseId, $targetWarehouseId);

                $transfer = VariantStockTransfer::create([
                    'organization_id' => $orgId,
                    'reference' => VariantStockTransfer::nextReference($orgId),
                    'from_warehouse_id' => (int) $fromWarehouseId,
                    'to_warehouse_id' => $targetWarehouseId,
                    'order_id' => $order->id,
                    'user_id' => $user?->id ?? auth()->id(),
                    'status' => 'completed',
                    'notes' => "Consolidation for order {$order->order_number}",
                    'completed_at' => now(),
                ]);

                $changes = [];
                foreach ($lines as $line) {
                    $allocation = OrderItemAllocation::whereKey($line['allocation_id'])->lockForUpdate()->firstOrFail();
                    $qty = $allocation->open_quantity;
                    if ($qty <= 0 || (int) $allocation->warehouse_id === $targetWarehouseId) {
                        continue;
                    }

                    VariantStockTransferLine::create([
                        'variant_stock_transfer_id' => $transfer->id,
                        'product_variant_id' => $allocation->product_variant_id,
                        'stock_owner_id' => $allocation->stock_owner_id,
                        'quantity' => $qty,
                        'committed' => true,
                        'order_item_id' => $allocation->order_item_id,
                    ]);

                    $owner = $allocation->stock_owner_id;
                    $changes[] = ['variant' => (int) $allocation->product_variant_id, 'warehouse_id' => (int) $fromWarehouseId,
                        'owner_id' => $owner, 'deltas' => ['on_hand' => -$qty, 'committed' => -$qty]];
                    $changes[] = ['variant' => (int) $allocation->product_variant_id, 'warehouse_id' => $targetWarehouseId,
                        'owner_id' => $owner, 'deltas' => ['on_hand' => $qty, 'committed' => $qty]];

                    // Split the allocation: the open units move, what was
                    // already fulfilled or released stays where it was.
                    if ($qty < (int) $allocation->quantity) {
                        $allocation->quantity -= $qty;
                        $allocation->save();
                        OrderItemAllocation::create([
                            'organization_id' => $orgId,
                            'order_id' => $allocation->order_id,
                            'order_item_id' => $allocation->order_item_id,
                            'product_variant_id' => $allocation->product_variant_id,
                            'warehouse_id' => $targetWarehouseId,
                            'stock_owner_id' => $owner,
                            'quantity' => $qty,
                        ]);
                    } else {
                        $allocation->warehouse_id = $targetWarehouseId;
                        $allocation->save();
                    }
                }

                if ($changes === []) {
                    $transfer->delete();

                    continue;
                }

                // A shortfall commitment may have no units behind it at the
                // origin: allow it, the anomaly stays visible there.
                $this->stock->apply($orgId, VariantStockOperation::TYPE_TRANSFER, $changes, [
                    'transfer_id' => $transfer->id,
                    'order_id' => $order->id,
                    'user_id' => $user?->id ?? auth()->id(),
                    'reason' => "Consolidation {$transfer->reference} for order {$order->order_number}",
                    'legacy_ledger' => false,
                    'allow_negative' => true,
                    'idempotency_key' => "transfer:{$transfer->id}",
                    'meta' => ['consolidation' => true],
                ]);

                $transfers[] = $transfer->load('lines');
            }

            return $transfers;
        });
    }

    private function assertWarehouses(int $organizationId, int $from, int $to): void
    {
        if ($from === $to) {
            throw new VariantStockException('Origin and destination must be different warehouses.');
        }

        $count = Warehouse::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('id', [$from, $to])
            ->count();

        if ($count !== 2) {
            throw new VariantStockException('Unknown warehouse.');
        }
    }

    /**
     * Free units of a variant per warehouse and owner, for the transfer form.
     *
     * @return list<array{warehouse_id: int, owner_id: int|null, available: int}>
     */
    public function freeUnits(ProductVariant $variant): array
    {
        return VariantStockLevel::where('product_variant_id', $variant->id)->get()
            ->map(fn (VariantStockLevel $l) => [
                'warehouse_id' => (int) $l->warehouse_id,
                'owner_id' => $l->stock_owner_id,
                'available' => $l->available,
            ])->values()->all();
    }
}
