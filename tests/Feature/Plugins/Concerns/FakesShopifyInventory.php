<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins\Concerns;

/**
 * A small, stateful Shopify inventory for tests: quantities by name per
 * item and location, compare-and-swap (changeFromQuantity), idempotency
 * keys (a repeated key returns the first answer and applies nothing), and
 * fulfilment orders that carry committed units between locations.
 *
 * Used with InteractsWithShopify::$shopifyExtra.
 */
trait FakesShopifyInventory
{
    /** @var array<string, array<string, array<string, int>>> item => location => name => qty */
    protected array $inv = [];

    /** @var array<string, array{params: string, body: array<string, mixed>}> */
    protected array $idempotent = [];

    /** @var array<string, list<array<string, mixed>>> order id => fulfilment orders */
    protected array $fos = [];

    /** @var list<array{query: string, variables: array<string, mixed>}> mutations that changed something */
    protected array $applied = [];

    /** @var array<string, array<string, mixed>> transfer name => GraphQL node */
    protected array $transfers = [];

    /** @var list<array<string, mixed>> fulfilments created through the API */
    protected array $fulfillments = [];

    /** Answer the next stock mutation with a transient HTTP 503. */
    protected int $failNext = 0;

    /** Answer the next reads of every level as throttled (rate limit). */
    protected int $throttleReads = 0;

    /** Apply the next stock mutation but lose the answer (a timeout). */
    protected bool $loseNextAnswer = false;

    protected function fakeInventory(): void
    {
        $this->shopifyExtra = function (string $query, array $variables): ?array {
            if ($this->failNext > 0 && preg_match('/inventoryAdjustQuantities|inventoryMoveQuantities|inventorySetQuantities|inventoryTransferCreateAsReadyToShip|inventoryShipmentCreateInTransit/', $query)) {
                $this->failNext--;

                return ['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]];
            }

            if ($this->throttleReads > 0 && str_contains($query, 'query InventoryItems(')) {
                $this->throttleReads--;

                return ['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
                    'extensions' => ['cost' => ['requestedQueryCost' => 400, 'throttleStatus' => ['maximumAvailable' => 1000, 'currentlyAvailable' => 100, 'restoreRate' => 50]]]];
            }

            if ($this->loseNextAnswer && preg_match('/inventoryAdjustQuantities|inventoryMoveQuantities|inventorySetQuantities|fulfillmentCreate/', $query)) {
                $this->loseNextAnswer = false;
                $this->shopifyExtra = null;
                try {
                    // Shopify applies it...
                    $this->fakeInventory();
                    ($this->shopifyExtra)($query, $variables);
                } finally {
                    $this->fakeInventory();
                }

                // ...but the answer never arrives.
                return ['errors' => [['message' => 'Timeout', 'extensions' => ['code' => 'THROTTLED']]]];
            }

            return match (true) {
                str_contains($query, 'query Levels(') => ['data' => ['nodes' => array_map(fn ($id) => $this->itemNode($id, $query), $variables['ids'] ?? [])]],
                str_contains($query, 'query InventoryItems(') => ['data' => ['inventoryItems' => [
                    'nodes' => array_map(fn ($id) => $this->itemNode($id, $query) + ['sku' => $this->skus[$id] ?? null, 'tracked' => true, 'variants' => ['nodes' => [['id' => 'gid://shopify/ProductVariant/'.md5($id), 'legacyResourceId' => md5($id)]]]], array_keys($this->inv)),
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ]]],
                str_contains($query, 'inventoryAdjustQuantities(') => $this->idempotentOnce('inventoryAdjustQuantities', $variables, fn () => $this->adjustQ($variables['input'])),
                str_contains($query, 'inventoryMoveQuantities(') => $this->idempotentOnce('inventoryMoveQuantities', $variables, fn () => $this->moveQ($variables['input'])),
                str_contains($query, 'inventorySetQuantities(') && str_contains($query, '$key') => $this->idempotentOnce('inventorySetQuantities', $variables, fn () => $this->setQ($variables['input'])),
                str_contains($query, 'query FulfillmentOrders(') => ['data' => ['order' => ['id' => $variables['id'], 'fulfillmentOrders' => ['nodes' => $this->fos[$this->legacy($variables['id'])] ?? []]]]],
                str_contains($query, 'fulfillmentCreate(') => ['data' => ['fulfillmentCreate' => $this->createFulfillmentQ($variables['fulfillment'])]],
                str_contains($query, 'fulfillmentOrderMove(') => ['data' => ['fulfillmentOrderMove' => $this->moveFo($variables)]],
                str_contains($query, 'inventoryActivate(') => ['data' => ['inventoryActivate' => $this->activateQ($variables)]],
                str_contains($query, 'query Transfers(') => ['data' => ['inventoryTransfers' => ['nodes' => array_values(array_filter($this->transfers, fn ($t) => ltrim($t['name'], '#') === ltrim((string) $variables['query'], '#')))]]],
                str_contains($query, 'inventoryTransferEdit(') => ['data' => ['inventoryTransferEdit' => $this->editTransfer($variables)]],
                str_contains($query, 'inventoryShipmentReceive(') => ['data' => ['inventoryShipmentReceive' => $this->receiveShipment($variables)]],
                str_contains($query, 'inventoryTransferCreateAsReadyToShip(') => $this->idempotentOnce('inventoryTransferCreateAsReadyToShip', $variables, fn () => $this->createTransferQ($variables['input'])),
                str_contains($query, 'inventoryShipmentCreateInTransit(') => $this->idempotentOnce('inventoryShipmentCreateInTransit', $variables, fn () => $this->shipTransferQ($variables['input'])),
                str_contains($query, 'inventoryTransferCancel(') => ['data' => ['inventoryTransferCancel' => $this->cancelTransferQ($variables['id'])]],
                default => null,
            };
        };
    }

    /** @var array<string, string> item gid => sku */
    protected array $skus = [];

    /**
     * @param  array<string, array<string, int>>  $byLocation  location => [name => qty]
     */
    protected function stockItem(string $item, string $sku, array $byLocation): void
    {
        $this->skus[$item] = $sku;
        foreach ($byLocation as $location => $quantities) {
            $this->inv[$item][$location] = array_merge(array_fill_keys(['available', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'], 0), $quantities);
        }
    }

    protected function qty(string $item, string $location, string $name): int
    {
        $level = $this->inv[$item][$location] ?? [];
        if ($name === 'on_hand') {
            return array_sum(array_intersect_key($level, array_flip(['available', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock'])));
        }

        return (int) ($level[$name] ?? 0);
    }

    /**
     * A sale in Shopify: the units are committed at the location.
     */
    protected function shopifySale(string $item, string $location, int $qty): void
    {
        $this->inv[$item][$location]['available'] -= $qty;
        $this->inv[$item][$location]['committed'] += $qty;
    }

    /**
     * A fulfilment in Shopify: committed units leave.
     */
    protected function shopifyFulfil(string $item, string $location, int $qty): void
    {
        $this->inv[$item][$location]['committed'] -= $qty;
    }

    /**
     * A change in the Shopify admin.
     */
    protected function shopifyAdjust(string $item, string $location, string $name, int $delta): void
    {
        $this->inv[$item][$location][$name] += $delta;
    }

    /**
     * @param  list<array{location: string, lines: list<array{item: string, qty: int, line_item_id: string}>}>  $fulfilmentOrders
     */
    protected function fulfilmentOrders(string $orderId, array $fulfilmentOrders): void
    {
        $this->fos[$orderId] = array_map(fn ($fo, $i) => [
            'id' => "gid://shopify/FulfillmentOrder/{$orderId}{$i}",
            'status' => 'OPEN',
            'requestStatus' => 'UNSUBMITTED',
            'assignedLocation' => ['location' => ['id' => $fo['location']]],
            'lineItems' => ['nodes' => array_map(fn ($l, $j) => [
                'id' => "gid://shopify/FulfillmentOrderLineItem/{$orderId}{$i}{$j}",
                'remainingQuantity' => $l['qty'],
                'totalQuantity' => $l['qty'],
                'inventoryItemId' => $l['item'],
                'lineItem' => ['id' => 'gid://shopify/LineItem/'.$l['line_item_id']],
                'variant' => ['id' => 'gid://shopify/ProductVariant/'.md5($l['item'])],
            ], $fo['lines'], array_keys($fo['lines']))],
        ], $fulfilmentOrders, array_keys($fulfilmentOrders));
    }

    /**
     * @return list<array{query: string, variables: array<string, mixed>}>
     */
    protected function appliedTo(string $mutation): array
    {
        return array_values(array_filter($this->applied, fn ($c) => str_contains($c['query'], $mutation)));
    }

    // ------------------------------------------------------------------

    private function itemNode(string $id, string $query): array
    {
        $node = ['id' => $id];
        preg_match_all('/(l\d+): inventoryLevel\(locationId: "([^"]+)"\)/', $query, $m, PREG_SET_ORDER);
        foreach ($m as [$all, $alias, $location]) {
            if (! isset($this->inv[$id][$location])) {
                $node[$alias] = null;

                continue;
            }
            $names = ['available', 'on_hand', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'];
            $node[$alias] = [
                'updatedAt' => now()->toIso8601String(),
                'location' => ['id' => $location],
                'quantities' => array_map(fn ($n) => ['name' => $n, 'quantity' => $this->qty($id, $location, $n)], $names),
            ];
        }

        return $node;
    }

    /**
     * @param  \Closure(): array{0: array<string, mixed>, 1: bool}  $apply
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function idempotentOnce(string $root, array $variables, \Closure $apply): array
    {
        $key = (string) ($variables['key'] ?? '');
        $params = json_encode($variables['input'] ?? []);

        if ($key !== '' && isset($this->idempotent[$key])) {
            if ($this->idempotent[$key]['params'] !== $params) {
                return ['data' => [$root => ['inventoryAdjustmentGroup' => null, 'userErrors' => [['field' => null, 'message' => 'Idempotency key reused with different parameters', 'code' => 'IDEMPOTENCY_KEY_PARAMETER_MISMATCH']]]]];
            }

            return $this->idempotent[$key]['body'];
        }

        [$payload, $changed] = $apply();
        $body = ['data' => [$root => $payload]];
        if ($key !== '') {
            $this->idempotent[$key] = ['params' => $params, 'body' => $body];
        }
        if ($changed) {
            $this->applied[] = ['query' => $root, 'variables' => $variables];
        }

        return $body;
    }

    private function staleAnswer(string $message): array
    {
        return [['inventoryAdjustmentGroup' => null, 'userErrors' => [['field' => ['input'], 'message' => $message, 'code' => 'CHANGE_FROM_QUANTITY_STALE']]], false];
    }

    private function adjustQ(array $input): array
    {
        $name = $input['name'];
        foreach ($input['changes'] as $c) {
            if (array_key_exists('changeFromQuantity', $c) && $c['changeFromQuantity'] !== null && $this->qty($c['inventoryItemId'], $c['locationId'], $name) !== (int) $c['changeFromQuantity']) {
                return $this->staleAnswer("The changeFromQuantity is stale for {$name}.");
            }
        }
        $changes = [];
        foreach ($input['changes'] as $c) {
            $this->inv[$c['inventoryItemId']][$c['locationId']] ??= array_fill_keys(['available', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'], 0);
            $this->inv[$c['inventoryItemId']][$c['locationId']][$name] += (int) $c['delta'];
            $changes[] = ['name' => $name, 'delta' => (int) $c['delta'], 'quantityAfterChange' => $this->qty($c['inventoryItemId'], $c['locationId'], $name), 'item' => ['id' => $c['inventoryItemId']], 'location' => ['id' => $c['locationId']]];
        }

        return [['inventoryAdjustmentGroup' => ['id' => 'gid://shopify/InventoryAdjustmentGroup/'.count($this->applied), 'changes' => $changes], 'userErrors' => []], true];
    }

    private function moveQ(array $input): array
    {
        foreach ($input['changes'] as $c) {
            $from = $c['from'];
            if (($from['changeFromQuantity'] ?? null) !== null && $this->qty($c['inventoryItemId'], $from['locationId'], $from['name']) !== (int) $from['changeFromQuantity']) {
                return $this->staleAnswer('The changeFromQuantity is stale for '.$from['name'].'.');
            }
        }
        foreach ($input['changes'] as $c) {
            $this->inv[$c['inventoryItemId']][$c['from']['locationId']][$c['from']['name']] -= (int) $c['quantity'];
            $this->inv[$c['inventoryItemId']][$c['to']['locationId']][$c['to']['name']] += (int) $c['quantity'];
        }

        return [['inventoryAdjustmentGroup' => ['id' => 'gid://shopify/InventoryAdjustmentGroup/m'.count($this->applied), 'changes' => []], 'userErrors' => []], true];
    }

    private function setQ(array $input): array
    {
        $name = $input['name'];
        foreach ($input['quantities'] as $q) {
            if (($q['changeFromQuantity'] ?? null) !== null && $this->qty($q['inventoryItemId'], $q['locationId'], $name) !== (int) $q['changeFromQuantity']) {
                return $this->staleAnswer("The changeFromQuantity is stale for {$name}.");
            }
        }
        foreach ($input['quantities'] as $q) {
            $delta = (int) $q['quantity'] - $this->qty($q['inventoryItemId'], $q['locationId'], $name);
            // Setting on_hand moves available.
            $this->inv[$q['inventoryItemId']][$q['locationId']][$name === 'on_hand' ? 'available' : $name] += $delta;
        }

        return [['inventoryAdjustmentGroup' => ['id' => 'gid://shopify/InventoryAdjustmentGroup/s'.count($this->applied), 'changes' => []], 'userErrors' => []], true];
    }

    private function moveFo(array $variables): array
    {
        foreach ($this->fos as $orderId => $fos) {
            foreach ($fos as $i => $fo) {
                if ($fo['id'] !== $variables['id']) {
                    continue;
                }
                $from = $fo['assignedLocation']['location']['id'];
                $to = $variables['newLocationId'];
                // As Shopify: only to a location where the items are stocked.
                foreach ($fo['lineItems']['nodes'] as $line) {
                    if (! isset($this->inv[$line['inventoryItemId']][$to])) {
                        return ['movedFulfillmentOrder' => null, 'originalFulfillmentOrder' => null, 'remainingFulfillmentOrder' => null, 'userErrors' => [['field' => ['newLocationId'], 'message' => 'None of the items are stocked at the new location.']]];
                    }
                }
                $moved = ['id' => $fo['id'].'-moved', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED', 'assignedLocation' => ['location' => ['id' => $to]], 'lineItems' => ['nodes' => []]];

                foreach ($variables['lineItems'] ?? [] as $want) {
                    foreach ($this->fos[$orderId][$i]['lineItems']['nodes'] as $j => $line) {
                        if ($line['id'] !== $want['id']) {
                            continue;
                        }
                        $qty = min((int) $want['quantity'], (int) $line['remainingQuantity']);
                        $this->fos[$orderId][$i]['lineItems']['nodes'][$j]['remainingQuantity'] -= $qty;
                        $moved['lineItems']['nodes'][] = array_merge($line, ['id' => $line['id'].'-m', 'remainingQuantity' => $qty, 'totalQuantity' => $qty]);
                        $item = $line['inventoryItemId'];
                        // The commitment moves with the fulfilment order.
                        $this->inv[$item][$from]['committed'] -= $qty;
                        $this->inv[$item][$from]['available'] += $qty;
                        $this->inv[$item][$to]['committed'] += $qty;
                        $this->inv[$item][$to]['available'] -= $qty;
                    }
                }
                $this->fos[$orderId][] = $moved;
                $this->applied[] = ['query' => 'fulfillmentOrderMove', 'variables' => $variables];

                return ['movedFulfillmentOrder' => ['id' => $moved['id'], 'status' => 'OPEN', 'assignedLocation' => ['location' => ['id' => $to]]], 'originalFulfillmentOrder' => ['id' => $fo['id'], 'status' => 'OPEN'], 'remainingFulfillmentOrder' => null, 'userErrors' => []];
            }
        }

        return ['movedFulfillmentOrder' => null, 'originalFulfillmentOrder' => null, 'remainingFulfillmentOrder' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Fulfillment order not found']]];
    }

    /**
     * Stock an item at a location (a level at zero); nothing if it is.
     */
    private function activateQ(array $variables): array
    {
        $this->inv[$variables['inventoryItemId']][$variables['locationId']] ??= array_fill_keys(['available', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'], 0);

        return ['inventoryLevel' => ['id' => 'gid://shopify/InventoryLevel/1'], 'userErrors' => []];
    }

    private function legacy(string $id): string
    {
        return str_contains($id, '/') ? substr($id, strrpos($id, '/') + 1) : $id;
    }

    private function editTransfer(array $variables): array
    {
        foreach ($this->transfers as $name => $transfer) {
            if ($transfer['id'] !== $variables['id']) {
                continue;
            }
            if ($transfer['status'] !== 'DRAFT') {
                return ['inventoryTransfer' => null, 'userErrors' => [['field' => ['input', 'destinationId'], 'message' => 'Destination can only be changed on a draft transfer', 'code' => 'INVALID']]];
            }
            $this->transfers[$name]['destination'] = ['name' => 'x', 'location' => ['id' => $variables['input']['destinationId']]];
            $this->applied[] = ['query' => 'inventoryTransferEdit', 'variables' => $variables];

            return ['inventoryTransfer' => ['id' => $transfer['id'], 'status' => 'DRAFT', 'destination' => ['location' => ['id' => $variables['input']['destinationId']]]], 'userErrors' => []];
        }

        return ['inventoryTransfer' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Not found', 'code' => 'NOT_FOUND']]];
    }

    private function receiveShipment(array $variables): array
    {
        foreach ($this->transfers as $name => $transfer) {
            foreach ($transfer['shipments']['nodes'] as $i => $shipment) {
                if ($shipment['id'] !== $variables['id']) {
                    continue;
                }
                $destination = $transfer['destination']['location']['id'];
                foreach ($variables['lineItems'] as $line) {
                    foreach ($shipment['lineItems']['nodes'] as $j => $node) {
                        if ($node['id'] !== $line['shipmentLineItemId']) {
                            continue;
                        }
                        $item = $node['inventoryItem']['id'];
                        $this->inv[$item][$destination]['incoming'] -= (int) $line['quantity'];
                        if ($line['reason'] === 'ACCEPTED') {
                            $this->inv[$item][$destination]['available'] += (int) $line['quantity'];
                        }
                        $this->transfers[$name]['shipments']['nodes'][$i]['lineItems']['nodes'][$j]['unreceivedQuantity'] -= (int) $line['quantity'];
                    }
                }
                $this->applied[] = ['query' => 'inventoryShipmentReceive', 'variables' => $variables];

                return ['inventoryShipment' => ['id' => $shipment['id'], 'status' => 'RECEIVED'], 'userErrors' => []];
            }
        }

        return ['inventoryShipment' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Not found', 'code' => 'NOT_FOUND']]];
    }

    /**
     * A transfer created ready to ship: the units are committed at the
     * origin.
     */
    private function createTransferQ(array $input): array
    {
        $id = 'gid://shopify/InventoryTransfer/'.(count($this->transfers) + 100);
        $name = '#T'.str_pad((string) (count($this->transfers) + 100), 4, '0', STR_PAD_LEFT);
        $lines = [];
        foreach ($input['lineItems'] as $i => $line) {
            $this->inv[$line['inventoryItemId']][$input['originLocationId']]['available'] -= (int) $line['quantity'];
            $this->inv[$line['inventoryItemId']][$input['originLocationId']]['committed'] += (int) $line['quantity'];
            $lines[] = ['id' => $id.'/line/'.$i, 'totalQuantity' => (int) $line['quantity'], 'inventoryItem' => ['id' => $line['inventoryItemId']]];
        }
        $this->transfers[$name] = [
            'id' => $id, 'name' => $name, 'status' => 'READY_TO_SHIP', 'referenceName' => $input['referenceName'] ?? null,
            'origin' => ['name' => 'o', 'location' => ['id' => $input['originLocationId']]],
            'destination' => ['name' => 'd', 'location' => ['id' => $input['destinationLocationId']]],
            'lineItems' => ['nodes' => $lines], 'shipments' => ['nodes' => []],
        ];

        return [['inventoryTransfer' => ['id' => $id, 'name' => $name, 'status' => 'READY_TO_SHIP'], 'userErrors' => []], true];
    }

    /**
     * A shipment in transit: the units leave the origin (committed) and are
     * incoming at the destination.
     */
    private function shipTransferQ(array $input): array
    {
        foreach ($this->transfers as $name => $transfer) {
            if ($transfer['id'] !== $input['movementId']) {
                continue;
            }
            $origin = $transfer['origin']['location']['id'];
            $destination = $transfer['destination']['location']['id'];
            $shipmentId = 'gid://shopify/InventoryShipment/'.(count($this->applied) + 1);
            $nodes = [];
            foreach ($input['lineItems'] as $i => $line) {
                $item = $line['inventoryItemId'];
                $this->inv[$item][$origin]['committed'] -= (int) $line['quantity'];
                $this->inv[$item][$destination] ??= array_fill_keys(['available', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'], 0);
                $this->inv[$item][$destination]['incoming'] += (int) $line['quantity'];
                $nodes[] = ['id' => $shipmentId.'/line/'.$i, 'quantity' => (int) $line['quantity'], 'unreceivedQuantity' => (int) $line['quantity'], 'inventoryItem' => ['id' => $item]];
            }
            $shipment = ['id' => $shipmentId, 'name' => $name.'-S1', 'status' => 'IN_TRANSIT', 'tracking' => $input['trackingInput'] ?? null, 'lineItems' => ['nodes' => $nodes]];
            $this->transfers[$name]['status'] = 'IN_PROGRESS';
            $this->transfers[$name]['shipments']['nodes'][] = $shipment;

            return [['inventoryShipment' => $shipment, 'userErrors' => []], true];
        }

        return [['inventoryShipment' => null, 'userErrors' => [['field' => ['input', 'movementId'], 'message' => 'Not found', 'code' => 'NOT_FOUND']]], false];
    }

    private function cancelTransferQ(string $id): array
    {
        foreach ($this->transfers as $name => $transfer) {
            if ($transfer['id'] !== $id) {
                continue;
            }
            if ($transfer['status'] !== 'READY_TO_SHIP' && $transfer['status'] !== 'DRAFT') {
                return ['inventoryTransfer' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Only a transfer not shipped can be cancelled', 'code' => 'INVALID']]];
            }
            foreach ($transfer['lineItems']['nodes'] as $line) {
                $this->inv[$line['inventoryItem']['id']][$transfer['origin']['location']['id']]['committed'] -= $line['totalQuantity'];
                $this->inv[$line['inventoryItem']['id']][$transfer['origin']['location']['id']]['available'] += $line['totalQuantity'];
            }
            $this->transfers[$name]['status'] = 'CANCELED';
            $this->applied[] = ['query' => 'inventoryTransferCancel', 'variables' => ['id' => $id]];

            return ['inventoryTransfer' => ['id' => $id, 'status' => 'CANCELED'], 'userErrors' => []];
        }

        return ['inventoryTransfer' => null, 'userErrors' => [['field' => ['id'], 'message' => 'Not found', 'code' => 'NOT_FOUND']]];
    }

    private function createFulfillmentQ(array $input): array
    {
        $id = count($this->fulfillments) + 500;
        $location = null;
        foreach ($input['lineItemsByFulfillmentOrder'] as $byFo) {
            foreach ($this->fos as $orderId => $fos) {
                foreach ($fos as $i => $fo) {
                    if ($fo['id'] !== $byFo['fulfillmentOrderId']) {
                        continue;
                    }
                    $location = $fo['assignedLocation']['location']['id'];
                    foreach ($byFo['fulfillmentOrderLineItems'] as $want) {
                        foreach ($fo['lineItems']['nodes'] as $j => $line) {
                            if ($line['id'] !== $want['id']) {
                                continue;
                            }
                            if ($want['quantity'] > $line['remainingQuantity']) {
                                return ['fulfillment' => null, 'userErrors' => [['field' => ['fulfillment'], 'message' => 'Quantity exceeds remaining']]];
                            }
                            $this->fos[$orderId][$i]['lineItems']['nodes'][$j]['remainingQuantity'] -= $want['quantity'];
                            // The units leave: committed and on hand go down.
                            $this->inv[$line['inventoryItemId']][$location]['committed'] -= $want['quantity'];
                        }
                    }
                }
            }
        }
        $this->fulfillments[] = $input + ['id' => $id, 'location' => $location];
        $this->applied[] = ['query' => 'fulfillmentCreate', 'variables' => $input];

        return ['fulfillment' => ['id' => 'gid://shopify/Fulfillment/'.$id, 'legacyResourceId' => (string) $id, 'status' => 'SUCCESS', 'location' => ['id' => $location]], 'userErrors' => []];
    }
}
