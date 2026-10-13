<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Models\Inventory\VariantStockTransfer;
use App\Models\Order\OrderItem;
use App\Models\Shipping\Shipment;
use App\Models\Shipping\ShipmentItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\Models\ShopifyLink;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Inventoros\Plugins\Shopify\Models\ShopifyStockIssue;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Sends the outbox to Shopify, one operation at a time, in order.
 *
 * - Each operation carries the figure it expects to change from
 *   (changeFromQuantity: the figure both sides last agreed on). If Shopify's
 *   figure moved (a sale, a change in the Shopify admin), Shopify refuses:
 *   a movement is re-based on the current figure and sent again, since a
 *   delta stays right whatever else happened; a count becomes a conflict
 *   for the operator, because the count may no longer be right.
 * - Each attempt carries the operation's idempotency key. A retry after a
 *   timeout or a crash reuses it, so Shopify applies the change once; only
 *   a re-based request (different figures, the earlier one refused) gets a
 *   new key.
 * - Only a confirmed operation moves the agreed figures, so the webhook
 *   Shopify sends back for our own change is recognised and not applied
 *   again.
 */
class OutboxProcessor
{
    private const MAX_REBASES = 3;

    private const MAX_ATTEMPTS = 8;

    /** Minutes to wait before attempt n (1-based). */
    private const BACKOFF = [1, 2, 5, 15, 30, 60, 120, 240];

    private LevelBook $book;

    public function __construct(
        private readonly ShopifyConnection $connection,
        private ?ShopifyClient $client = null,
    ) {
        $this->client ??= new ShopifyClient($connection);
        $this->book = new LevelBook($connection);
    }

    /**
     * @return array{confirmed: int, failed: int, conflicts: int, waiting: int}
     */
    public function run(int $max = 200): array
    {
        $summary = ['confirmed' => 0, 'failed' => 0, 'conflicts' => 0, 'waiting' => 0];

        // Without two-way stock only fulfilments are sent (shipments made in
        // Inventoros fulfil the Shopify order whatever the stock mode).
        $onlyFulfillments = ! $this->connection->isTwoWay();
        if ($onlyFulfillments && ! $this->connection->push_fulfillments) {
            return $summary;
        }

        $lock = Cache::lock('shopify-outbox-'.$this->connection->organization_id, 600);
        if (! $lock->get()) {
            return $summary;
        }

        try {
            for ($i = 0; $i < $max; $i++) {
                $operation = $this->next($onlyFulfillments);
                if ($operation === null) {
                    break;
                }

                $result = $this->send($operation);
                $summary[$result]++;

                if ($result === 'waiting') {
                    break;
                }
            }
        } finally {
            $lock->release();
        }

        return $summary;
    }

    /**
     * The oldest operation that may go now: pending (or stuck sending for
     * more than five minutes, i.e. its worker died), whose previous step is
     * confirmed. Strict order per store, so figures stay consistent.
     */
    private function next(bool $onlyFulfillments = false): ?ShopifyStockOperation
    {
        $candidate = ShopifyStockOperation::query()
            ->where('organization_id', $this->connection->organization_id)
            ->when($onlyFulfillments, fn ($q) => $q->where('kind', 'fulfillment_create'))
            ->where(fn ($q) => $q->where('status', ShopifyStockOperation::PENDING)
                ->orWhere(fn ($q) => $q->where('status', ShopifyStockOperation::SENDING)->where('sent_at', '<', now()->subMinutes(5))))
            ->orderBy('id')
            ->first();

        if ($candidate === null) {
            return null;
        }

        if ($candidate->next_attempt_at !== null && $candidate->next_attempt_at->isFuture()) {
            return null;
        }

        if ($candidate->depends_on_id !== null) {
            $previous = ShopifyStockOperation::find($candidate->depends_on_id);
            if ($previous !== null && $previous->status !== ShopifyStockOperation::CONFIRMED) {
                return null;
            }
        }

        return $candidate;
    }

    /**
     * @return 'confirmed'|'failed'|'conflicts'|'waiting'
     */
    public function send(ShopifyStockOperation $operation): string
    {
        $operation->forceFill([
            'status' => ShopifyStockOperation::SENDING,
            'sent_at' => now(),
            'attempts' => $operation->attempts + 1,
        ])->save();
        $this->book->refreshFor($operation);

        try {
            for ($rebase = 0; $rebase <= self::MAX_REBASES; $rebase++) {
                $result = $this->call($operation);

                if ($result['errors'] === []) {
                    return $this->confirm($operation, $result);
                }

                $codes = array_map(fn ($e) => (string) ($e['code'] ?? ''), $result['errors']);
                $messages = implode('; ', array_map(fn ($e) => (string) $e['message'], $result['errors']));

                if (in_array('ITEM_NOT_STOCKED_AT_LOCATION', $codes, true) && $rebase === 0) {
                    $this->activateItems($operation);

                    continue;
                }

                if ($this->isStale($result['errors'])) {
                    if ($operation->mode === 'absolute') {
                        return $this->conflict($operation, 'count_conflict',
                            'Shopify\'s stock moved after this count was taken: check the count and decide which figure is right. '.$messages);
                    }

                    // A movement: base it on Shopify's figure now and send it
                    // again, as a new request.
                    $this->rebase($operation);

                    continue;
                }

                return $this->fail($operation, $messages, permanent: true);
            }

            return $this->fail($operation, 'Shopify kept refusing the figures this change was based on.', permanent: true);
        } catch (ShopifyException $e) {
            return $this->fail($operation, $e->getMessage(), permanent: ! $e->transient);
        }
    }

    /**
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private function call(ShopifyStockOperation $operation): array
    {
        $payload = $operation->payload;
        $reference = (string) ($payload['reference'] ?? OutboundPlanner::REFERENCE.$operation->variant_stock_operation_id);
        $override = (array) ($payload['change_from_override'] ?? []);

        $expected = function (string $item, string $location, string $name) use ($override): ?int {
            $key = $item.'|'.$location.'|'.$name;

            return array_key_exists($key, $override)
                ? (int) $override[$key]
                : ($this->book->agreedShopify($item, $location, $name) ?? $this->current($item, $location, $name));
        };

        return match ($operation->kind) {
            'adjust' => $this->client->adjustQuantities(
                (string) $payload['name'],
                (string) $payload['reason'],
                array_map(fn ($c) => $c + ['change_from' => $expected($c['inventory_item_id'], $c['location_id'], (string) $payload['name'])], $payload['changes']),
                $operation->idempotency_key,
                $reference,
            ),
            'move' => $this->client->moveQuantities(
                (string) $payload['reason'],
                array_map(fn ($c) => $c + ['change_from' => $expected($c['inventory_item_id'], $c['location_id'], $c['from'])], $payload['changes']),
                $operation->idempotency_key,
                $reference,
            ),
            'set' => $this->client->setQuantities(
                (string) $payload['name'],
                (string) $payload['reason'],
                array_map(fn ($q) => [
                    'inventory_item_id' => $q['inventory_item_id'],
                    'location_id' => $q['location_id'],
                    'quantity' => (int) $q['quantity'],
                    'change_from' => $expected($q['inventory_item_id'], $q['location_id'], (string) $payload['name']),
                ], $payload['quantities']),
                $operation->idempotency_key,
                $reference,
            ),
            'fulfillment_order_move' => $this->moveFulfillment($operation),
            'transfer_edit' => $this->editTransfer($operation),
            'shipment_receive' => $this->receiveShipment($operation),
            'fulfillment_create' => $this->createFulfillment($operation),
            'transfer_create', 'transfer_ship', 'transfer_receive', 'transfer_cancel' => $this->transferStep($operation),
            default => ['group_id' => null, 'changes' => [], 'errors' => [['code' => null, 'message' => "Unknown operation {$operation->kind}", 'field' => null]]],
        };
    }

    /**
     * Shopify's current figure, read now (when the two sides never agreed).
     */
    private function current(string $item, string $location, string $name): int
    {
        $levels = $this->client->inventoryLevels([$item], [$location]);

        return (int) ($levels[ShopifyClient::gid('InventoryItem', $item)][ShopifyClient::gid('Location', $location)]['quantities'][$name] ?? 0);
    }

    /**
     * @param  array<int, array{code: string|null, message: string, field: mixed}>  $errors
     */
    private function isStale(array $errors): bool
    {
        foreach ($errors as $error) {
            $code = (string) ($error['code'] ?? '');
            if (str_contains($code, 'STALE') || str_contains($code, 'COMPARE') || str_contains(strtolower($error['message']), 'changefromquantity')) {
                return true;
            }
        }

        return false;
    }

    private function rebase(ShopifyStockOperation $operation): void
    {
        $payload = $operation->payload;
        $pairs = $operation->pairs();
        $levels = $this->client->inventoryLevels(array_column($pairs, 'item'), array_values(array_unique(array_column($pairs, 'location'))));

        $override = [];
        foreach ($levels as $item => $byLocation) {
            foreach ($byLocation as $location => $level) {
                foreach ($level['quantities'] as $name => $qty) {
                    $override[$item.'|'.$location.'|'.$name] = (int) $qty;
                }
            }
        }

        $payload['change_from_override'] = $override;
        $payload['previous_keys'][] = $operation->idempotency_key;

        $operation->forceFill([
            'payload' => $payload,
            // A different request: the refused one changed nothing.
            'idempotency_key' => (string) Str::uuid(),
        ])->save();
    }

    private function activateItems(ShopifyStockOperation $operation): void
    {
        foreach ($operation->pairs() as $pair) {
            $this->client->activateAtLocation($pair['item'], $pair['location']);
        }
    }

    /**
     * @param  array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, mixed>}  $result
     * @return 'confirmed'
     */
    private function confirm(ShopifyStockOperation $operation, array $result): string
    {
        $operation->forceFill([
            'status' => ShopifyStockOperation::CONFIRMED,
            'confirmed_at' => now(),
            'adjustment_group_id' => $result['group_id'],
            'response' => ['changes' => $result['changes']],
            'last_error' => null,
            'next_attempt_at' => null,
        ])->save();

        $this->book->applyEffect($operation->effect ?? []);
        $this->book->refreshFor($operation);
        $this->updateTransfer($operation);

        // Issues this operation raised are settled.
        ShopifyStockIssue::query()->where('stock_operation_id', $operation->id)->where('status', 'open')
            ->update(['status' => 'resolved', 'resolution' => 'sent', 'resolved_at' => now()]);

        return 'confirmed';
    }

    /**
     * @return 'conflicts'
     */
    private function conflict(ShopifyStockOperation $operation, string $kind, string $message): string
    {
        $operation->forceFill(['status' => ShopifyStockOperation::CONFLICT, 'last_error' => $message])->save();

        foreach ($operation->pairs() as $pair) {
            $level = $this->book->level($pair['item'], $pair['location']);
            ShopifyStockIssue::raise([
                'organization_id' => $this->connection->organization_id,
                'kind' => $kind,
                'inventory_item_id' => $level->inventory_item_id,
                'location_id' => $level->location_id,
                'product_variant_id' => $level->product_variant_id,
                'warehouse_id' => $level->warehouse_id,
                'stock_operation_id' => $operation->id,
                'order_id' => $operation->order_id,
                'message' => $message,
                'details' => ['payload' => $operation->payload, 'agreed' => $level->agreed_s],
            ]);
        }

        $this->book->refreshFor($operation);
        $this->updateTransfer($operation);

        return 'conflicts';
    }

    /**
     * @return 'failed'|'waiting'
     */
    private function fail(ShopifyStockOperation $operation, string $message, bool $permanent): string
    {
        if (! $permanent && $operation->attempts < self::MAX_ATTEMPTS) {
            $operation->forceFill([
                'status' => ShopifyStockOperation::PENDING,
                'last_error' => $message,
                'next_attempt_at' => now()->addMinutes(self::BACKOFF[$operation->attempts - 1] ?? 240),
            ])->save();
            $this->book->refreshFor($operation);

            return 'waiting';
        }

        $operation->forceFill(['status' => ShopifyStockOperation::FAILED, 'last_error' => $message])->save();

        ShopifyStockIssue::raise([
            'organization_id' => $this->connection->organization_id,
            'kind' => 'operation_failed',
            'stock_operation_id' => $operation->id,
            'order_id' => $operation->order_id,
            'message' => 'Shopify refused a stock change: '.$message,
            'details' => ['kind' => $operation->kind, 'payload' => $operation->payload],
        ]);

        $this->book->refreshFor($operation);
        $this->updateTransfer($operation);

        return 'failed';
    }

    private function updateTransfer(ShopifyStockOperation $operation): void
    {
        if ($operation->transfer_id === null) {
            return;
        }

        $statuses = ShopifyStockOperation::query()->where('transfer_id', $operation->transfer_id)->pluck('status');

        [$status, $error] = match (true) {
            $statuses->contains(ShopifyStockOperation::CONFLICT) => [VariantStockTransfer::CHANNEL_CONFLICT, $operation->last_error],
            $statuses->contains(ShopifyStockOperation::FAILED) => [VariantStockTransfer::CHANNEL_ERROR, $operation->last_error],
            $statuses->every(fn ($s) => $s === ShopifyStockOperation::CONFIRMED) => [VariantStockTransfer::CHANNEL_SYNCED, null],
            default => [VariantStockTransfer::CHANNEL_PENDING, $operation->last_error],
        };

        VariantStockTransfer::whereKey($operation->transfer_id)->update(['channel_status' => $status, 'channel_error' => $error]);
    }

    /**
     * Move an order's open fulfilment for the given items from one location
     * to the other. Already moved (a retry after a lost answer) is success.
     *
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private function moveFulfillment(ShopifyStockOperation $operation): array
    {
        $payload = $operation->payload;
        $shopifyOrderId = ShopifyOrder::query()->where('organization_id', $this->connection->organization_id)
            ->where('order_id', $payload['order_id'] ?? 0)->value('shopify_order_id');

        if ($shopifyOrderId === null) {
            return ['group_id' => null, 'changes' => [], 'errors' => [['code' => null, 'message' => 'The Shopify order of this consolidation is unknown.', 'field' => null]]];
        }

        $from = ShopifyClient::gid('Location', (string) $payload['from']);
        $to = ShopifyClient::gid('Location', (string) $payload['to']);
        $wanted = [];
        foreach ((array) $payload['lines'] as $line) {
            $wanted[ShopifyClient::gid('InventoryItem', $line['inventory_item_id'])] = ($wanted[ShopifyClient::gid('InventoryItem', $line['inventory_item_id'])] ?? 0) + (int) $line['quantity'];
        }

        $errors = [];
        foreach ($this->client->fulfillmentOrders((string) $shopifyOrderId) as $fo) {
            if ($fo['location_id'] !== $from || $fo['status'] !== 'OPEN') {
                continue;
            }

            $lines = [];
            foreach ($fo['lines'] as $line) {
                $item = $line['inventory_item_id'] !== null ? ShopifyClient::gid('InventoryItem', $line['inventory_item_id']) : null;
                if ($item === null || ($wanted[$item] ?? 0) <= 0 || $line['remaining'] <= 0) {
                    continue;
                }
                $qty = min($wanted[$item], $line['remaining']);
                $lines[] = ['id' => $line['id'], 'quantity' => $qty];
                $wanted[$item] -= $qty;
            }

            if ($lines !== []) {
                $result = $this->client->moveFulfillmentOrder($fo['id'], $to, $lines);
                foreach ($result['errors'] as $message) {
                    $errors[] = ['code' => null, 'message' => $message, 'field' => null];
                }
            }
        }

        // Nothing left at the origin for these items: the move is done
        // (now, or by an earlier attempt whose answer was lost).
        return ['group_id' => null, 'changes' => [], 'errors' => $errors];
    }

    /**
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private function editTransfer(ShopifyStockOperation $operation): array
    {
        $payload = $operation->payload;
        $errors = $this->client->editTransferDestination((string) $payload['transfer_id'], (string) $payload['destination']);

        return ['group_id' => null, 'changes' => [], 'errors' => array_map(fn ($m) => ['code' => null, 'message' => $m, 'field' => null], $errors)];
    }

    /**
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private function receiveShipment(ShopifyStockOperation $operation): array
    {
        $payload = $operation->payload;
        $errors = $this->client->receiveShipment((string) $payload['shipment_id'], (array) $payload['lines'], $operation->idempotency_key);

        return ['group_id' => null, 'changes' => [], 'errors' => array_map(fn ($m) => ['code' => null, 'message' => $m, 'field' => null], $errors)];
    }

    /**
     * Fulfil in Shopify a shipment made in Inventoros: its units, from the
     * location of the warehouse it left from, with its tracking.
     *
     * Shopify has no idempotency key for fulfilments, so a retry first reads
     * the order again: units already fulfilled there (an earlier attempt
     * whose answer was lost) are not fulfilled twice. The fulfilment is
     * linked to the shipment, so when Shopify reports it back the order
     * import recognises it and moves no stock.
     *
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private function createFulfillment(ShopifyStockOperation $operation): array
    {
        $fail = fn (string $message) => ['group_id' => null, 'changes' => [], 'errors' => [['code' => 'FULFILLMENT', 'message' => $message, 'field' => null]]];
        $payload = $operation->payload;

        $shipment = Shipment::withoutGlobalScopes()->find($payload['shipment_id'] ?? 0);
        $shopifyOrderId = ShopifyOrder::query()->where('organization_id', $this->connection->organization_id)
            ->where('order_id', $operation->order_id)->value('shopify_order_id');

        if ($shipment === null || $shopifyOrderId === null) {
            return $fail('The shipment or its Shopify order no longer exists.');
        }

        // What the shipment carries, by Shopify inventory item.
        $map = new ChannelMap($this->connection, $this->client);
        $wanted = [];
        foreach (ShipmentItem::where('shipment_id', $shipment->id)->get() as $item) {
            $orderItem = OrderItem::find($item->order_item_id);
            $inventoryItem = $orderItem === null ? null : ($orderItem->product_variant_id !== null
                ? $map->itemForVariant((int) $orderItem->product_variant_id)
                : $this->itemForSku((string) $orderItem->sku));
            if ($inventoryItem !== null) {
                $wanted[$inventoryItem] = ($wanted[$inventoryItem] ?? 0) + (int) $item->quantity;
            }
        }

        if ($wanted === []) {
            return ['group_id' => null, 'changes' => [], 'errors' => []]; // nothing Shopify stocks
        }

        $location = $shipment->warehouse_id !== null ? $this->connection->locationForWarehouse((int) $shipment->warehouse_id) : null;
        $fulfillmentOrders = $this->client->fulfillmentOrders((string) $shopifyOrderId);

        $byFo = [];
        $remainingAnywhere = [];
        foreach ($fulfillmentOrders as $fo) {
            foreach ($fo['lines'] as $line) {
                $item = $line['inventory_item_id'] !== null ? ShopifyClient::gid('InventoryItem', $line['inventory_item_id']) : null;
                if ($item !== null) {
                    $remainingAnywhere[$item] = ($remainingAnywhere[$item] ?? 0) + $line['remaining'];
                }
            }
            if ($fo['status'] !== 'OPEN' && $fo['status'] !== 'IN_PROGRESS') {
                continue;
            }
            if ($location !== null && $fo['location_id'] !== $location) {
                continue;
            }
            foreach ($fo['lines'] as $line) {
                $item = $line['inventory_item_id'] !== null ? ShopifyClient::gid('InventoryItem', $line['inventory_item_id']) : null;
                if ($item === null || ($wanted[$item] ?? 0) <= 0 || $line['remaining'] <= 0) {
                    continue;
                }
                $qty = min($wanted[$item], $line['remaining']);
                $byFo[$fo['id']][] = ['id' => $line['id'], 'quantity' => $qty];
                $wanted[$item] -= $qty;
            }
        }

        $missing = array_filter($wanted);
        if ($missing !== []) {
            // Already fulfilled in Shopify (nothing left anywhere): an earlier
            // attempt went through, or someone fulfilled it there.
            $alreadyDone = array_filter($missing, fn ($qty, $item) => ($remainingAnywhere[$item] ?? 0) === 0, ARRAY_FILTER_USE_BOTH) === $missing;
            if ($alreadyDone && $byFo === []) {
                return ['group_id' => null, 'changes' => [], 'errors' => []];
            }

            return $fail('Shopify does not have these units to fulfil from the shipment\'s location: the order may still be assigned to another location (consolidation not confirmed in Shopify yet) or changed there. Check the order in Shopify, then retry.');
        }

        $result = $this->client->createFulfillment($byFo, [
            'company' => $shipment->carrier_name,
            'number' => $shipment->tracking_number,
            'url' => $shipment->tracking_url,
        ], (bool) ($payload['notify_customer'] ?? false));

        if ($result['errors'] !== []) {
            return $fail(implode('; ', $result['errors']));
        }

        if ($result['legacy_id'] !== null) {
            ShopifyLink::query()->updateOrCreate(
                ['organization_id' => $this->connection->organization_id, 'kind' => ShopifyLink::FULFILLMENT, 'shopify_id' => $result['legacy_id']],
                ['shopify_order_id' => (string) $shopifyOrderId, 'local_id' => $shipment->id, 'state' => 'shipped', 'meta' => ['created_by' => 'inventoros']],
            );
        }

        return ['group_id' => $result['id'], 'changes' => [], 'errors' => []];
    }

    private function itemForSku(string $sku): ?string
    {
        $row = ShopifyInventoryItem::query()
            ->where('organization_id', $this->connection->organization_id)->where('sku', $sku)->first();

        return $row !== null && $row->tracked ? ShopifyClient::gid('InventoryItem', $row->inventory_item_id) : null;
    }

    /**
     * One step of a transfer, as a Shopify transfer: created ready to ship,
     * shipped (shipment in transit), received (accepted / rejected) or
     * cancelled. Each later step uses the Shopify ids the earlier one got;
     * create, ship and receive carry the operation's idempotency key.
     *
     * @return array{group_id: string|null, changes: array<int, array<string, mixed>>, errors: array<int, array{code: string|null, message: string, field: mixed}>}
     */
    private function transferStep(ShopifyStockOperation $operation): array
    {
        $payload = $operation->payload;
        $fail = fn (array $messages) => ['group_id' => null, 'changes' => [], 'errors' => array_map(fn ($m) => ['code' => 'TRANSFER', 'message' => $m, 'field' => null], $messages)];
        $earlier = fn (string $kind) => ShopifyStockOperation::query()->where('transfer_id', $operation->transfer_id)
            ->where('kind', $kind)->where('status', ShopifyStockOperation::CONFIRMED)->latest('id')->first();

        switch ($operation->kind) {
            case 'transfer_create':
                $result = $this->client->createTransferReadyToShip($payload['origin'], $payload['destination'], $payload['lines'],
                    (string) $payload['name'], $payload['note'] ?? null, $operation->idempotency_key);
                if ($result['errors'] !== [] || $result['id'] === null) {
                    return $fail($result['errors'] ?: ['Shopify did not create the transfer.']);
                }
                $payload['shopify_transfer_id'] = $result['id'];
                $payload['shopify_transfer_name'] = $result['name'];
                break;

            case 'transfer_ship':
                $transferId = $earlier('transfer_create')?->payload['shopify_transfer_id'] ?? null;
                if ($transferId === null) {
                    return $fail(['The transfer was not created in Shopify yet.']);
                }
                $result = $this->client->createShipmentInTransit($transferId, $payload['lines'],
                    ['company' => $payload['carrier'] ?? null, 'number' => $payload['tracking_number'] ?? null], $operation->idempotency_key);
                if ($result['errors'] !== [] || $result['id'] === null) {
                    return $fail($result['errors'] ?: ['Shopify did not create the shipment.']);
                }
                $payload['shopify_shipment_id'] = $result['id'];
                $payload['shopify_shipment_lines'] = $result['lines'];
                break;

            case 'transfer_receive':
                $ship = $earlier('transfer_ship');
                $shipmentId = $ship?->payload['shopify_shipment_id'] ?? null;
                $lineIds = (array) ($ship?->payload['shopify_shipment_lines'] ?? []);
                if ($shipmentId === null) {
                    return $fail(['The transfer has not left in Shopify yet.']);
                }
                $lines = [];
                foreach ($payload['lines'] as $line) {
                    $id = $lineIds[ShopifyClient::gid('InventoryItem', $line['inventory_item_id'])] ?? null;
                    if ($id === null) {
                        return $fail(['A received item is not on the Shopify shipment.']);
                    }
                    if ($line['accepted'] > 0) {
                        $lines[] = ['id' => $id, 'quantity' => (int) $line['accepted'], 'reason' => 'ACCEPTED'];
                    }
                    if ($line['rejected'] > 0) {
                        $lines[] = ['id' => $id, 'quantity' => (int) $line['rejected'], 'reason' => 'REJECTED'];
                    }
                }
                $errors = $this->client->receiveShipment($shipmentId, $lines, $operation->idempotency_key);
                if ($errors !== []) {
                    return $fail($errors);
                }
                break;

            case 'transfer_cancel':
                // No idempotency key on cancel: a retry after a lost answer
                // finds the transfer already cancelled, which is success.
                $created = $earlier('transfer_create')?->payload ?? [];
                if (isset($created['shopify_transfer_id'])) {
                    $errors = $this->client->cancelTransfer($created['shopify_transfer_id']);
                    $current = $errors === [] ? null : $this->client->inventoryTransfer((string) ($created['shopify_transfer_name'] ?? ''));
                    if ($errors !== [] && ($current['status'] ?? null) !== 'CANCELED') {
                        return $fail($errors);
                    }
                }
                break;
        }

        $operation->forceFill(['payload' => $payload])->save();

        return ['group_id' => null, 'changes' => [], 'errors' => []];
    }
}
