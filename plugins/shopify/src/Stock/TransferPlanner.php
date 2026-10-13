<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Models\Inventory\VariantStockOperation;
use App\Models\User;
use App\Services\Inventory\VariantStockService;
use App\Services\Inventory\VariantTransferService;
use Illuminate\Support\Str;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Bring a Shopify inventory transfer to the warehouse it should arrive at
 * (e.g. #T0001 to MC2 instead of Via Maragliano), the way Shopify allows in
 * the transfer's real state. Nothing is done until the operator approves the
 * plan; each plan is recorded as outbox operations awaiting approval.
 *
 *  - DRAFT: the destination can be edited (inventoryTransferEdit).
 *  - READY_TO_SHIP (nothing shipped yet): the destination can no longer be
 *    edited. Cancel it, duplicate it (a new draft), set the copy's
 *    destination, mark the copy ready to ship. Origin commitments are
 *    released by the cancel and taken again by the copy: nothing counted
 *    twice.
 *  - IN_PROGRESS with a shipment not yet received: receive the shipment's
 *    units as REJECTED at the planned destination (they do not enter its
 *    stock, and its incoming clears), then add the same units at the right
 *    warehouse with a receipt that refers to the transfer. Shopify's
 *    transfer history will show them as rejected there: that is why it
 *    needs approval.
 *  - TRANSFERRED: already received at the planned destination; the units
 *    that physically arrived elsewhere are moved with an immediate transfer.
 *
 * The old instruction "receive at Via Maragliano, then move" is never the
 * default.
 */
class TransferPlanner
{
    private ShopifyClient $client;

    public function __construct(private readonly ShopifyConnection $connection, ?ShopifyClient $client = null)
    {
        $this->client = $client ?? new ShopifyClient($connection);
    }

    /**
     * Read the transfer and record the plan (awaiting approval).
     *
     * @return array{transfer: array<string, mixed>, status: string, plan: list<string>, operations: list<int>}
     */
    public function plan(string $name, int $targetWarehouseId): array
    {
        $transfer = $this->client->inventoryTransfer($name);
        if ($transfer === null) {
            throw new ShopifyException("Shopify has no transfer named {$name} (or the app lacks read_inventory_transfers).");
        }

        $target = $this->connection->locationForWarehouse($targetWarehouseId);
        if ($target === null) {
            throw new ShopifyException('The target warehouse is not mapped to a Shopify location.');
        }

        $status = (string) ($transfer['status'] ?? 'OTHER');
        $destination = (string) ($transfer['destination']['location']['id'] ?? '');
        $summary = ['transfer' => $transfer, 'status' => $status, 'plan' => [], 'operations' => []];

        if ($destination === $target && $status !== 'TRANSFERRED') {
            $summary['plan'][] = "The transfer already goes to {$this->locationName($target)}. Nothing to change.";

            return $summary;
        }

        $base = ['transfer_id' => $transfer['id'], 'transfer_name' => $transfer['name'] ?? $name, 'destination' => $target];

        switch ($status) {
            case 'DRAFT':
                $summary['plan'][] = "Change the destination of the draft from {$this->locationName($destination)} to {$this->locationName($target)}.";
                $summary['operations'][] = $this->record('transfer_edit', $base)->id;
                break;

            case 'READY_TO_SHIP':
                $summary['plan'][] = 'Shopify does not let a transfer that is ready to ship change its destination.';
                $summary['plan'][] = "Cancel {$base['transfer_name']} (its units are released at the origin), duplicate it as a new draft, set the copy's destination to {$this->locationName($target)}, mark the copy ready to ship.";
                $summary['operations'][] = $this->record('transfer_redirect', $base)->id;
                break;

            case 'IN_PROGRESS':
                $open = [];
                foreach ((array) ($transfer['shipments']['nodes'] ?? []) as $shipment) {
                    $lines = [];
                    foreach ((array) ($shipment['lineItems']['nodes'] ?? []) as $line) {
                        $qty = (int) ($line['unreceivedQuantity'] ?? 0);
                        if ($qty > 0) {
                            $lines[] = ['id' => $line['id'], 'quantity' => $qty, 'reason' => 'REJECTED',
                                'inventory_item_id' => $line['inventoryItem']['id'] ?? null, 'sku' => $line['inventoryItem']['sku'] ?? null];
                        }
                    }
                    if ($lines !== []) {
                        $open[] = ['shipment_id' => $shipment['id'], 'lines' => $lines];
                    }
                }

                if ($open === []) {
                    $summary['plan'][] = 'Every shipment of the transfer has been received already: re-read it once it shows TRANSFERRED.';
                    break;
                }

                $units = array_sum(array_map(fn ($s) => array_sum(array_column($s['lines'], 'quantity')), $open));
                $summary['plan'][] = "Shopify does not let a transfer in progress change its destination. Alternative, {$units} unit(s):";
                $summary['plan'][] = "1) receive the open shipment units as REJECTED at {$this->locationName($destination)}: they do not enter its stock and its incoming clears;";
                $summary['plan'][] = "2) add the same units to {$this->locationName($target)} with a receipt referring to {$base['transfer_name']} (owner to be assigned).";
                $summary['plan'][] = 'Shopify\'s transfer history will show the units as rejected at the planned destination.';
                foreach ($open as $shipment) {
                    $summary['operations'][] = $this->record('shipment_receive', $base + $shipment)->id;
                }
                break;

            case 'TRANSFERRED':
                $summary['plan'][] = "Already received at {$this->locationName($destination)}. If the units are physically at {$this->locationName($target)}, move them with an immediate transfer (it changes the site, not the owner).";
                $lines = [];
                foreach ((array) ($transfer['lineItems']['nodes'] ?? []) as $line) {
                    $lines[] = ['inventory_item_id' => $line['inventoryItem']['id'] ?? null, 'sku' => $line['inventoryItem']['sku'] ?? null, 'quantity' => (int) ($line['totalQuantity'] ?? 0)];
                }
                $summary['operations'][] = $this->record('local_transfer', $base + ['from' => $destination, 'lines' => $lines])->id;
                break;

            default:
                $summary['plan'][] = "Transfer status {$status}: no automatic plan. Check it in Shopify.";
        }

        return $summary;
    }

    /**
     * Carry out an approved plan step.
     *
     * @return list<string> what was done
     */
    public function approve(ShopifyStockOperation $operation, ?int $userId = null): array
    {
        if ($operation->status !== ShopifyStockOperation::AWAITING_APPROVAL || (int) $operation->organization_id !== (int) $this->connection->organization_id) {
            throw new ShopifyException('This plan step is not waiting for approval.');
        }

        $payload = $operation->payload;
        $done = [];

        switch ($operation->kind) {
            case 'transfer_edit':
                $this->fail($operation, $this->client->editTransferDestination($payload['transfer_id'], $payload['destination']));
                $done[] = "Destination of {$payload['transfer_name']} changed.";
                break;

            case 'transfer_redirect':
                $this->fail($operation, $this->client->cancelTransfer($payload['transfer_id']));
                $copy = $this->client->duplicateTransfer($payload['transfer_id']);
                $this->fail($operation, $copy['errors']);
                $this->fail($operation, $this->client->editTransferDestination((string) $copy['id'], $payload['destination']));
                $this->fail($operation, $this->client->markTransferReady((string) $copy['id'], $operation->idempotency_key));
                $payload['new_transfer'] = $copy;
                $done[] = "{$payload['transfer_name']} cancelled; copy {$copy['name']} goes to the new destination and is ready to ship.";
                break;

            case 'shipment_receive':
                $this->fail($operation, $this->client->receiveShipment($payload['shipment_id'], array_map(
                    fn ($l) => ['id' => $l['id'], 'quantity' => $l['quantity'], 'reason' => 'REJECTED'], $payload['lines']), $operation->idempotency_key));
                $done[] = 'Shipment units received as rejected at the planned destination.';
                $done = [...$done, ...$this->receiveAtTarget($payload, $userId)];
                break;

            case 'local_transfer':
                $done = [...$done, ...$this->moveReceived($payload, $userId)];
                break;

            default:
                throw new ShopifyException("Unknown plan step {$operation->kind}.");
        }

        $operation->forceFill(['status' => ShopifyStockOperation::CONFIRMED, 'confirmed_at' => now(), 'payload' => $payload, 'response' => ['done' => $done]])->save();

        return $done;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(string $kind, array $payload): ShopifyStockOperation
    {
        return ShopifyStockOperation::create([
            'organization_id' => $this->connection->organization_id,
            'kind' => $kind,
            'status' => ShopifyStockOperation::AWAITING_APPROVAL,
            'idempotency_key' => (string) Str::uuid(),
            'mode' => 'delta',
            'payload' => $payload,
            'effect' => [],
        ]);
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function fail(ShopifyStockOperation $operation, array $errors): void
    {
        if ($errors !== []) {
            $operation->forceFill(['status' => ShopifyStockOperation::FAILED, 'last_error' => implode('; ', $errors)])->save();

            throw new ShopifyException('Shopify refused: '.implode('; ', $errors));
        }
    }

    /**
     * Units received as rejected elsewhere: add them where they really are.
     * A local receipt, sent to Shopify like any other (as available).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function receiveAtTarget(array $payload, ?int $userId): array
    {
        $map = new ChannelMap($this->connection, $this->client);
        $warehouse = $this->connection->warehouseForLocation($payload['destination']);
        $done = [];

        foreach ($payload['lines'] as $line) {
            $variant = $line['inventory_item_id'] !== null ? $map->variantForItem($line['inventory_item_id']) : null;
            if ($variant === null || $warehouse === null) {
                $done[] = "Not booked (no Inventoros variant): {$line['sku']} × {$line['quantity']}.";

                continue;
            }

            app(VariantStockService::class)->adjust($variant, $warehouse, null, (int) $line['quantity'], [
                'type' => VariantStockOperation::TYPE_ADJUSTMENT,
                'user_id' => $userId,
                'reason' => "Received from transfer {$payload['transfer_name']}",
                'idempotency_key' => "transfer-receive:{$payload['transfer_id']}:{$line['id']}",
                'meta' => ['shopify_transfer' => $payload['transfer_id']],
            ]);
            $done[] = "Booked {$line['quantity']} × {$variant->sku} at the target warehouse.";
        }

        return $done;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function moveReceived(array $payload, ?int $userId): array
    {
        $map = new ChannelMap($this->connection, $this->client);
        $from = $this->connection->warehouseForLocation($payload['from']);
        $to = $this->connection->warehouseForLocation($payload['destination']);
        $lines = [];
        $done = [];

        foreach ($payload['lines'] as $line) {
            $variant = $line['inventory_item_id'] !== null ? $map->variantForItem($line['inventory_item_id']) : null;
            if ($variant !== null && $line['quantity'] > 0) {
                $lines[] = ['product_variant_id' => $variant->id, 'quantity' => $line['quantity']];
            } else {
                $done[] = "Not moved (no Inventoros variant): {$line['sku']}.";
            }
        }

        if ($lines !== [] && $from !== null && $to !== null) {
            $transfer = app(VariantTransferService::class)->transfer((int) $this->connection->organization_id, $from, $to, $lines,
                $userId !== null ? User::find($userId) : null, "Transfer {$payload['transfer_name']} received at the wrong site");
            $done[] = "Immediate transfer {$transfer->reference} recorded.";
        }

        return $done;
    }

    private function locationName(string $id): string
    {
        foreach ($this->connection->locations ?? [] as $location) {
            if (($location['id'] ?? null) === $id) {
                return (string) $location['name'];
            }
        }

        return $id;
    }
}
