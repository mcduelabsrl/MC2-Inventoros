<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Models\Inventory\VariantStockOperation;
use App\Models\Inventory\VariantStockTransfer;
use App\Models\Order\OrderItemAllocation;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\ShopifyClient;

/**
 * Turns an operation made in Inventoros into the Shopify mutations that
 * make Shopify show the same thing, queued in the outbox.
 *
 * What is never sent:
 *  - operations a channel reported (source external) or opening balances;
 *  - commitments, releases and fulfilments of Shopify orders: Shopify made
 *    those itself;
 *  - owner changes: Shopify does not know owners and the units do not move.
 *
 * How quantities map: on-hand changes and the unsellable states go to the
 * same Shopify names (available, quality_control, damaged, reserved); units
 * committed to orders taken outside Shopify are sent as reserved. A change
 * that only moves units between states is sent as a move, so on-hand never
 * jumps; a count is sent as an absolute on_hand that Shopify refuses if its
 * figure moved since (a conflict for the operator).
 *
 * A consolidation for a Shopify order is three steps, in an order that
 * never shows a pair for sale twice: take the units out of the origin
 * (available), move the order's fulfilment to the destination (Shopify
 * moves the commitment), add the units at the destination (available).
 */
class OutboundPlanner
{
    public const REFERENCE = 'gid://inventoros/VariantStockOperation/';

    private ChannelMap $map;

    public function __construct(private readonly ShopifyConnection $connection, ?ChannelMap $map = null)
    {
        $this->map = $map ?? new ChannelMap($connection, new ShopifyClient($connection));
    }

    /**
     * @return list<ShopifyStockOperation>
     */
    public function plan(VariantStockOperation $operation): array
    {
        if (! $this->connection->isTwoWay() || $operation->source !== VariantStockOperation::SOURCE_LOCAL) {
            return [];
        }

        // Already planned (a hook delivered twice).
        if (ShopifyStockOperation::query()->where('variant_stock_operation_id', $operation->id)->exists()) {
            return [];
        }

        $operation->loadMissing('movements');
        $shopifyOrder = ChannelMap::isShopifyOrder($operation->order_id);

        if ($shopifyOrder && in_array($operation->type, [
            VariantStockOperation::TYPE_ORDER_COMMIT,
            VariantStockOperation::TYPE_ORDER_RELEASE,
            VariantStockOperation::TYPE_ORDER_FULFIL,
        ], true)) {
            return [];
        }

        if ($operation->type === VariantStockOperation::TYPE_OWNER_CHANGE) {
            return [];
        }

        if ($operation->type === VariantStockOperation::TYPE_TRANSFER && $shopifyOrder && ($operation->meta['consolidation'] ?? false)) {
            return $this->planConsolidation($operation);
        }

        // A transfer between warehouses is a Shopify transfer too, step by
        // step: prepared (ready to ship), shipped (shipment in transit),
        // received (accepted / rejected), or cancelled.
        if (in_array($operation->type, [
            VariantStockOperation::TYPE_TRANSFER_PREPARE,
            VariantStockOperation::TYPE_TRANSFER_SHIP,
            VariantStockOperation::TYPE_TRANSFER_RECEIVE,
            VariantStockOperation::TYPE_TRANSFER_CANCEL,
        ], true)) {
            return $this->planTransferStep($operation);
        }

        $pairs = $this->pairs($operation);
        if ($pairs === []) {
            return [];
        }

        if ($operation->mode === 'absolute' && $operation->type === VariantStockOperation::TYPE_COUNT) {
            return $this->planCount($operation, $pairs);
        }

        // Shopify already put returned units back on sale where the refund
        // restocked them: send only the difference to "in quality control
        // at the returns warehouse".
        foreach ((array) ($operation->meta['channel_restocked'] ?? []) as $restock) {
            $pairs = $this->subtractChannelRestock($pairs, $restock);
        }

        return $this->planDeltas($operation, $pairs, $shopifyOrder);
    }

    /**
     * Net change per variant and warehouse, by bucket, with the Shopify
     * item and location (pairs Shopify cannot see are left out).
     *
     * @return array<string, array{item: string, location: string, variant: int, warehouse: int, deltas: array<string, int>}>
     */
    private function pairs(VariantStockOperation $operation): array
    {
        $pairs = [];
        foreach ($operation->movements as $movement) {
            $item = $this->map->itemForVariant((int) $movement->product_variant_id);
            $location = $this->map->locationForWarehouse((int) $movement->warehouse_id);
            if ($item === null || $location === null) {
                continue;
            }
            $key = $item.'|'.$location;
            $pairs[$key] ??= ['item' => $item, 'location' => $location, 'variant' => (int) $movement->product_variant_id,
                'warehouse' => (int) $movement->warehouse_id, 'deltas' => []];
            $pairs[$key]['deltas'][$movement->bucket] = ($pairs[$key]['deltas'][$movement->bucket] ?? 0) + (int) $movement->delta;
        }

        return $pairs;
    }

    /**
     * @param  array<string, array{item: string, location: string, variant: int, warehouse: int, deltas: array<string, int>}>  $pairs
     * @return list<ShopifyStockOperation>
     */
    private function planDeltas(VariantStockOperation $operation, array $pairs, bool $shopifyOrder): array
    {
        $moves = [];
        $adjusts = [];

        foreach ($pairs as $pair) {
            $d = $pair['deltas'];
            $committed = (int) ($d['committed'] ?? 0);
            $names = [
                'quality_control' => (int) ($d['quality_control'] ?? 0),
                'damaged' => (int) ($d['damaged'] ?? 0),
                // Units committed to orders taken outside Shopify are held
                // there as reserved.
                'reserved' => (int) ($d['reserved'] ?? 0) + ($shopifyOrder ? 0 : $committed),
            ];
            $names['available'] = (int) ($d['on_hand'] ?? 0) - array_sum($names) - ($shopifyOrder ? $committed : 0);
            $names = array_filter($names);

            // Pair each decrease with increases: those are moves between
            // states, which leave on-hand alone. What is left is adjusted.
            $minus = array_filter($names, fn ($v) => $v < 0);
            $plus = array_filter($names, fn ($v) => $v > 0);
            foreach ($minus as $from => $qtyFrom) {
                foreach ($plus as $to => $qtyTo) {
                    $qty = min(-$qtyFrom, $qtyTo);
                    if ($qty <= 0) {
                        continue;
                    }
                    $moves[] = ['inventory_item_id' => $pair['item'], 'location_id' => $pair['location'], 'quantity' => $qty, 'from' => $from, 'to' => $to];
                    $minus[$from] += $qty;
                    $qtyFrom += $qty;
                    $plus[$to] -= $qty;
                }
            }
            foreach (array_filter($minus + $plus) as $name => $delta) {
                $adjusts[$name][] = ['inventory_item_id' => $pair['item'], 'location_id' => $pair['location'], 'delta' => $delta];
            }
        }

        $rows = [];
        $reason = $this->reason($operation->type);

        if ($moves !== []) {
            $effect = [];
            foreach ($moves as $m) {
                self::addEffect($effect, $m['inventory_item_id'], $m['location_id'], $m['from'], -$m['quantity']);
                self::addEffect($effect, $m['inventory_item_id'], $m['location_id'], $m['to'], $m['quantity']);
            }
            $rows[] = ['kind' => 'move', 'payload' => ['reason' => $reason, 'changes' => $moves], 'effect' => $effect];
        }

        // Unsellable states first, available last: a decrease of what is for
        // sale goes out before any increase.
        uksort($adjusts, fn ($a, $b) => ($a === 'available') <=> ($b === 'available'));
        foreach ($adjusts as $name => $changes) {
            $effect = [];
            foreach ($changes as $c) {
                self::addEffect($effect, $c['inventory_item_id'], $c['location_id'], $name, $c['delta']);
                self::addEffect($effect, $c['inventory_item_id'], $c['location_id'], 'on_hand', $c['delta']);
            }
            $rows[] = ['kind' => 'adjust', 'payload' => ['name' => $name, 'reason' => $reason, 'changes' => $changes], 'effect' => $effect];
        }

        return $this->store($operation, $rows, 'delta');
    }

    /**
     * @param  array<string, array{item: string, location: string, variant: int, warehouse: int, deltas: array<string, int>}>  $pairs
     * @return list<ShopifyStockOperation>
     */
    private function planCount(VariantStockOperation $operation, array $pairs): array
    {
        $quantities = [];
        foreach ($pairs as $pair) {
            $expected = $this->map->expected($pair['variant'], $pair['warehouse']);
            $quantities[] = [
                'inventory_item_id' => $pair['item'],
                'location_id' => $pair['location'],
                'quantity' => $expected['on_hand'],
                'local_delta' => (int) ($pair['deltas']['on_hand'] ?? 0),
            ];
        }

        $effect = [];
        foreach ($quantities as $q) {
            self::addEffect($effect, $q['inventory_item_id'], $q['location_id'], 'on_hand', $q['local_delta']);
            self::addEffect($effect, $q['inventory_item_id'], $q['location_id'], 'available', $q['local_delta']);
        }

        return $this->store($operation, [[
            'kind' => 'set',
            'payload' => ['name' => 'on_hand', 'reason' => 'cycle_count_available', 'quantities' => $quantities],
            'effect' => $effect,
        ]], 'absolute');
    }

    /**
     * @param  array<string, array{item: string, location: string, variant: int, warehouse: int, deltas: array<string, int>}>  $pairs
     * @param  array{location_id?: string, quantity?: int, product_variant_id?: int}  $restock
     * @return array<string, array{item: string, location: string, variant: int, warehouse: int, deltas: array<string, int>}>
     */
    private function subtractChannelRestock(array $pairs, array $restock): array
    {
        $item = $this->map->itemForVariant((int) ($restock['product_variant_id'] ?? 0));
        $location = isset($restock['location_id']) ? ShopifyClient::gid('Location', $restock['location_id']) : null;
        $qty = (int) ($restock['quantity'] ?? 0);

        if ($item === null || $location === null || $qty <= 0) {
            return $pairs;
        }

        $key = $item.'|'.$location;
        $warehouse = $this->map->warehouseForLocation($location);
        $pairs[$key] ??= ['item' => $item, 'location' => $location, 'variant' => (int) $restock['product_variant_id'],
            'warehouse' => (int) $warehouse, 'deltas' => []];
        // Shopify added these to available there: take them off what we
        // still have to send.
        $pairs[$key]['deltas']['on_hand'] = ($pairs[$key]['deltas']['on_hand'] ?? 0) - $qty;

        return $pairs;
    }

    /**
     * @return list<ShopifyStockOperation>
     */
    private function planConsolidation(VariantStockOperation $operation): array
    {
        $transfer = VariantStockTransfer::with('lines')->find($operation->transfer_id);
        if ($transfer === null) {
            return [];
        }

        $from = $this->map->locationForWarehouse((int) $transfer->from_warehouse_id);
        $to = $this->map->locationForWarehouse((int) $transfer->to_warehouse_id);
        if ($from === null || $to === null) {
            return [];
        }

        $out = [];
        $in = [];
        $foLines = [];
        foreach ($transfer->lines as $line) {
            $item = $this->map->itemForVariant((int) $line->product_variant_id);
            if ($item === null) {
                continue;
            }
            $out[] = ['inventory_item_id' => $item, 'location_id' => $from, 'delta' => -$line->quantity];
            $in[] = ['inventory_item_id' => $item, 'location_id' => $to, 'delta' => $line->quantity];
            $foLines[] = ['inventory_item_id' => $item, 'quantity' => (int) $line->quantity];
        }

        if ($out === []) {
            return [];
        }

        $effectOut = [];
        foreach ($out as $c) {
            self::addEffect($effectOut, $c['inventory_item_id'], $c['location_id'], 'available', $c['delta']);
            self::addEffect($effectOut, $c['inventory_item_id'], $c['location_id'], 'on_hand', $c['delta']);
        }
        $effectMove = [];
        foreach ($foLines as $l) {
            // Shopify moves the commitment with the fulfilment.
            self::addEffect($effectMove, $l['inventory_item_id'], $from, 'committed', -$l['quantity']);
            self::addEffect($effectMove, $l['inventory_item_id'], $from, 'available', $l['quantity']);
            self::addEffect($effectMove, $l['inventory_item_id'], $to, 'committed', $l['quantity']);
            self::addEffect($effectMove, $l['inventory_item_id'], $to, 'available', -$l['quantity']);
        }
        $effectIn = [];
        foreach ($in as $c) {
            self::addEffect($effectIn, $c['inventory_item_id'], $c['location_id'], 'available', $c['delta']);
            self::addEffect($effectIn, $c['inventory_item_id'], $c['location_id'], 'on_hand', $c['delta']);
        }

        $reference = 'gid://inventoros/VariantStockTransfer/'.$transfer->id;

        return $this->store($operation, [
            ['kind' => 'adjust', 'payload' => ['name' => 'available', 'reason' => 'movement_created', 'changes' => $out, 'reference' => $reference], 'effect' => $effectOut],
            ['kind' => 'fulfillment_order_move', 'payload' => ['order_id' => $operation->order_id, 'from' => $from, 'to' => $to, 'lines' => $foLines], 'effect' => $effectMove],
            ['kind' => 'adjust', 'payload' => ['name' => 'available', 'reason' => 'movement_received', 'changes' => $in, 'reference' => $reference], 'effect' => $effectIn],
        ], 'delta');
    }

    /**
     * @return list<ShopifyStockOperation>
     */
    private function planTransferStep(VariantStockOperation $operation): array
    {
        $transfer = VariantStockTransfer::with('lines')->find($operation->transfer_id);
        if ($transfer === null) {
            return [];
        }

        $from = $this->map->locationForWarehouse((int) $transfer->from_warehouse_id);
        $to = $this->map->locationForWarehouse((int) $transfer->to_warehouse_id);
        if ($from === null || $to === null) {
            return [];
        }

        // Quantities per Shopify item for this step.
        $items = [];
        foreach ($transfer->lines as $line) {
            $item = $this->map->itemForVariant((int) $line->product_variant_id);
            if ($item === null) {
                continue;
            }
            $items[$line->id] = ['inventory_item_id' => $item, 'line' => $line];
        }
        if ($items === []) {
            return [];
        }

        $base = ['transfer_id' => $transfer->id, 'reference' => 'gid://inventoros/VariantStockTransfer/'.$transfer->id];
        $effect = [];

        switch ($operation->type) {
            case VariantStockOperation::TYPE_TRANSFER_PREPARE:
                $lines = [];
                foreach ($items as $i) {
                    $lines[] = ['inventory_item_id' => $i['inventory_item_id'], 'quantity' => (int) $i['line']->quantity];
                    self::addEffect($effect, $i['inventory_item_id'], $from, 'committed', (int) $i['line']->quantity);
                    self::addEffect($effect, $i['inventory_item_id'], $from, 'available', -(int) $i['line']->quantity);
                }
                $row = ['kind' => 'transfer_create', 'payload' => $base + ['origin' => $from, 'destination' => $to, 'name' => $transfer->reference, 'note' => $transfer->notes, 'lines' => $lines]];
                break;

            case VariantStockOperation::TYPE_TRANSFER_SHIP:
                $lines = [];
                foreach ($items as $i) {
                    $qty = (int) $i['line']->quantity;
                    $lines[] = ['inventory_item_id' => $i['inventory_item_id'], 'quantity' => $qty];
                    self::addEffect($effect, $i['inventory_item_id'], $from, 'committed', -$qty);
                    self::addEffect($effect, $i['inventory_item_id'], $from, 'on_hand', -$qty);
                    self::addEffect($effect, $i['inventory_item_id'], $to, 'incoming', $qty);
                }
                $row = ['kind' => 'transfer_ship', 'payload' => $base + ['lines' => $lines, 'carrier' => $transfer->carrier, 'tracking_number' => $transfer->tracking_number]];
                break;

            case VariantStockOperation::TYPE_TRANSFER_RECEIVE:
                $lines = [];
                foreach ((array) ($operation->meta['lines'] ?? []) as $received) {
                    $i = $items[$received['line_id']] ?? null;
                    if ($i === null) {
                        continue;
                    }
                    $accepted = (int) $received['accepted'];
                    $rejected = (int) $received['rejected'];
                    $lines[] = ['inventory_item_id' => $i['inventory_item_id'], 'accepted' => $accepted, 'rejected' => $rejected];
                    self::addEffect($effect, $i['inventory_item_id'], $to, 'incoming', -($accepted + $rejected));
                    self::addEffect($effect, $i['inventory_item_id'], $to, 'available', $accepted);
                    self::addEffect($effect, $i['inventory_item_id'], $to, 'on_hand', $accepted);
                }
                if ($lines === []) {
                    return [];
                }
                $row = ['kind' => 'transfer_receive', 'payload' => $base + ['lines' => $lines]];
                break;

            default: // cancel
                foreach ($items as $i) {
                    self::addEffect($effect, $i['inventory_item_id'], $from, 'committed', -(int) $i['line']->quantity);
                    self::addEffect($effect, $i['inventory_item_id'], $from, 'available', (int) $i['line']->quantity);
                }
                $row = ['kind' => 'transfer_cancel', 'payload' => $base];
        }

        return $this->store($operation, [$row + ['effect' => $effect]], 'delta');
    }

    /**
     * @param  list<array{kind: string, payload: array<string, mixed>, effect: array<string, int>}>  $rows
     * @return list<ShopifyStockOperation>
     */
    private function store(VariantStockOperation $operation, array $rows, string $mode): array
    {
        if ($rows === []) {
            return [];
        }

        return DB::transaction(function () use ($operation, $rows, $mode) {
            $stored = [];
            // The steps of one transfer go out in order: each waits for the
            // one before it, also across operations.
            $previous = $operation->transfer_id === null ? null : ShopifyStockOperation::query()
                ->where('transfer_id', $operation->transfer_id)
                ->whereNotIn('status', [ShopifyStockOperation::CANCELLED])
                ->latest('id')->first();

            foreach (array_values($rows) as $i => $row) {
                $row['payload']['reference'] ??= self::REFERENCE.$operation->id;

                $previous = ShopifyStockOperation::create([
                    'organization_id' => $operation->organization_id,
                    'kind' => $row['kind'],
                    'status' => ShopifyStockOperation::PENDING,
                    'variant_stock_operation_id' => $operation->id,
                    'transfer_id' => $operation->transfer_id,
                    'order_id' => $operation->order_id,
                    'depends_on_id' => $previous?->id,
                    'step' => $i + 1,
                    'mode' => $mode,
                    'payload' => $row['payload'],
                    'effect' => $row['effect'],
                ]);
                $stored[] = $previous;
            }

            if ($operation->transfer_id !== null) {
                VariantStockTransfer::whereKey($operation->transfer_id)->update(['channel_status' => VariantStockTransfer::CHANNEL_PENDING]);
            }

            return $stored;
        });
    }

    /**
     * @param  array<string, int>  $effect
     */
    public static function addEffect(array &$effect, string $item, string $location, string $name, int $delta): void
    {
        $key = $item.'|'.$location.'|'.$name;
        $effect[$key] = ($effect[$key] ?? 0) + $delta;
        if ($effect[$key] === 0) {
            unset($effect[$key]);
        }
    }

    private function reason(string $type): string
    {
        return match ($type) {
            VariantStockOperation::TYPE_TRANSFER => 'movement_created',
            VariantStockOperation::TYPE_RETURN_RECEIVED => 'restock',
            VariantStockOperation::TYPE_INSPECTION => 'quality_control',
            VariantStockOperation::TYPE_STATE_CHANGE => 'correction',
            VariantStockOperation::TYPE_COUNT => 'cycle_count_available',
            VariantStockOperation::TYPE_ORDER_COMMIT => 'reservation_created',
            VariantStockOperation::TYPE_ORDER_RELEASE => 'reservation_deleted',
            VariantStockOperation::TYPE_ORDER_FULFIL => 'other',
            default => 'correction',
        };
    }

    /**
     * Units of an order still committed at a warehouse (for checks).
     */
    public static function openAt(int $orderId, int $warehouseId): int
    {
        return (int) OrderItemAllocation::where('order_id', $orderId)->where('warehouse_id', $warehouseId)
            ->selectRaw('coalesce(sum(quantity - fulfilled_quantity - released_quantity), 0) as open')->value('open');
    }
}
