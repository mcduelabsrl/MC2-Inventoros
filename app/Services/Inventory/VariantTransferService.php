<?php

declare(strict_types=1);

namespace App\Services\Inventory;

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
     * Step 1, prepared: the units are set aside at the origin (committed to
     * the transfer, no longer for sale) and stay there until they leave.
     *
     * @param  list<array{product_variant_id: int, owner_id?: int|null, quantity: int}>  $lines
     */
    public function prepare(int $organizationId, int $fromWarehouseId, int $toWarehouseId, array $lines, ?User $user = null, ?string $notes = null): VariantStockTransfer
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
                'status' => VariantStockTransfer::READY,
                'notes' => $notes,
            ]);

            $changes = [];
            foreach ($lines as $line) {
                $variant = ProductVariant::findOrFail((int) $line['product_variant_id']);
                if ((int) $variant->organization_id !== $organizationId) {
                    throw new VariantStockException('The variant belongs to another organization.');
                }
                $owner = isset($line['owner_id']) && $line['owner_id'] !== '' ? (int) $line['owner_id'] : null;
                $qty = (int) $line['quantity'];

                VariantStockTransferLine::create([
                    'variant_stock_transfer_id' => $transfer->id,
                    'product_variant_id' => $variant->id,
                    'stock_owner_id' => $owner,
                    'quantity' => $qty,
                ]);

                $changes[] = ['variant' => $variant, 'warehouse_id' => $fromWarehouseId, 'owner_id' => $owner, 'deltas' => ['committed' => $qty]];
            }

            // Free units only: available at the origin must cover them.
            $this->stock->apply($organizationId, VariantStockOperation::TYPE_TRANSFER_PREPARE, $changes, [
                'transfer_id' => $transfer->id,
                'user_id' => $user?->id ?? auth()->id(),
                'reason' => "Transfer {$transfer->reference} prepared",
                'notes' => $notes,
                'legacy_ledger' => false,
                'idempotency_key' => "transfer:{$transfer->id}:prepare",
            ]);

            return $transfer->load('lines');
        });
    }

    /**
     * Step 2, shipped: the units leave the origin and are incoming at the
     * destination, with the same owner.
     */
    public function ship(VariantStockTransfer $transfer, ?User $user = null, ?string $carrier = null, ?string $trackingNumber = null): VariantStockTransfer
    {
        return DB::transaction(function () use ($transfer, $user, $carrier, $trackingNumber) {
            $locked = VariantStockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== VariantStockTransfer::READY) {
                throw new VariantStockException("Transfer {$locked->reference} is not waiting to leave.");
            }

            $changes = [];
            foreach ($locked->lines as $line) {
                $changes[] = ['variant' => (int) $line->product_variant_id, 'warehouse_id' => (int) $locked->from_warehouse_id,
                    'owner_id' => $line->stock_owner_id, 'deltas' => ['on_hand' => -$line->quantity, 'committed' => -$line->quantity]];
                $changes[] = ['variant' => (int) $line->product_variant_id, 'warehouse_id' => (int) $locked->to_warehouse_id,
                    'owner_id' => $line->stock_owner_id, 'deltas' => ['incoming' => $line->quantity]];
            }

            $locked->forceFill([
                'status' => VariantStockTransfer::IN_TRANSIT,
                'shipped_at' => now(),
                'shipped_by' => $user?->id ?? auth()->id(),
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
            ])->save();

            $this->stock->apply((int) $locked->organization_id, VariantStockOperation::TYPE_TRANSFER_SHIP, $changes, [
                'transfer_id' => $locked->id,
                'user_id' => $user?->id ?? auth()->id(),
                'reason' => "Transfer {$locked->reference} shipped",
                'legacy_ledger' => false,
                'allow_negative' => true,
                'idempotency_key' => "transfer:{$locked->id}:ship",
            ]);

            return $locked->load('lines');
        });
    }

    /**
     * Step 3, received: per line, units accepted (they enter the
     * destination's stock) and refused (missing or unusable: they never
     * enter). Partial receipts are allowed; the transfer completes when
     * nothing is outstanding.
     *
     * @param  array<int, array{accepted?: int, rejected?: int}>  $receipt  line id => quantities; omitted lines are received in full
     */
    public function receive(VariantStockTransfer $transfer, array $receipt = [], ?User $user = null): VariantStockTransfer
    {
        return DB::transaction(function () use ($transfer, $receipt, $user) {
            $locked = VariantStockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== VariantStockTransfer::IN_TRANSIT) {
                throw new VariantStockException("Transfer {$locked->reference} is not in transit.");
            }

            $changes = [];
            $received = [];
            foreach ($locked->lines as $line) {
                $outstanding = $line->outstanding();
                $accepted = (int) ($receipt === [] ? $outstanding : ($receipt[$line->id]['accepted'] ?? 0));
                $rejected = (int) ($receipt[$line->id]['rejected'] ?? 0);

                if ($accepted < 0 || $rejected < 0 || $accepted + $rejected > $outstanding) {
                    throw new VariantStockException("Line {$line->variant?->sku}: only {$outstanding} units are still to receive.");
                }
                if ($accepted + $rejected === 0) {
                    continue;
                }

                $line->received_quantity += $accepted;
                $line->rejected_quantity += $rejected;
                $line->save();
                $received[] = ['line_id' => $line->id, 'product_variant_id' => (int) $line->product_variant_id, 'accepted' => $accepted, 'rejected' => $rejected];

                $changes[] = ['variant' => (int) $line->product_variant_id, 'warehouse_id' => (int) $locked->to_warehouse_id,
                    'owner_id' => $line->stock_owner_id, 'deltas' => ['incoming' => -($accepted + $rejected), 'on_hand' => $accepted]];
            }

            if ($changes === []) {
                throw new VariantStockException('Enter at least one unit received or refused.');
            }

            $done = $locked->lines->every(fn ($l) => $l->outstanding() === 0);
            $locked->forceFill([
                'status' => $done ? VariantStockTransfer::COMPLETED : VariantStockTransfer::IN_TRANSIT,
                'received_at' => now(),
                'received_by' => $user?->id ?? auth()->id(),
                'completed_at' => $done ? now() : null,
            ])->save();

            $receipts = VariantStockOperation::where('transfer_id', $locked->id)
                ->where('type', VariantStockOperation::TYPE_TRANSFER_RECEIVE)->count();

            $this->stock->apply((int) $locked->organization_id, VariantStockOperation::TYPE_TRANSFER_RECEIVE, $changes, [
                'transfer_id' => $locked->id,
                'user_id' => $user?->id ?? auth()->id(),
                'reason' => "Transfer {$locked->reference} received",
                // Received units count as a change of the variant's stock.
                'legacy_type' => 'transfer',
                'legacy_reference' => $locked,
                'allow_negative' => true,
                'idempotency_key' => "transfer:{$locked->id}:receive:".($receipts + 1),
                'meta' => ['lines' => $received],
            ]);

            return $locked->load('lines');
        });
    }

    /**
     * Call off a transfer that has not left: the units set aside are free
     * again at the origin.
     */
    public function cancel(VariantStockTransfer $transfer, ?User $user = null): VariantStockTransfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $locked = VariantStockTransfer::whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== VariantStockTransfer::READY) {
                throw new VariantStockException("Transfer {$locked->reference} has already left and cannot be cancelled.");
            }

            $changes = $locked->lines->map(fn ($line) => ['variant' => (int) $line->product_variant_id, 'warehouse_id' => (int) $locked->from_warehouse_id,
                'owner_id' => $line->stock_owner_id, 'deltas' => ['committed' => -$line->quantity]])->all();

            $locked->forceFill(['status' => VariantStockTransfer::CANCELLED, 'cancelled_at' => now()])->save();

            $this->stock->apply((int) $locked->organization_id, VariantStockOperation::TYPE_TRANSFER_CANCEL, $changes, [
                'transfer_id' => $locked->id,
                'user_id' => $user?->id ?? auth()->id(),
                'reason' => "Transfer {$locked->reference} cancelled",
                'legacy_ledger' => false,
                'allow_negative' => true,
                'idempotency_key' => "transfer:{$locked->id}:cancel",
            ]);

            return $locked->load('lines');
        });
    }

    /**
     * All three steps at once, for units that have already physically moved
     * (e.g. a transfer received at the wrong site). Each step is still sent
     * to the sales channel as its own step.
     *
     * @param  list<array{product_variant_id: int, owner_id?: int|null, quantity: int}>  $lines
     */
    public function transfer(int $organizationId, int $fromWarehouseId, int $toWarehouseId, array $lines, ?User $user = null, ?string $notes = null): VariantStockTransfer
    {
        return DB::transaction(function () use ($organizationId, $fromWarehouseId, $toWarehouseId, $lines, $user, $notes) {
            $transfer = $this->prepare($organizationId, $fromWarehouseId, $toWarehouseId, $lines, $user, $notes);
            $this->ship($transfer, $user);

            return $this->receive($transfer, [], $user);
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
