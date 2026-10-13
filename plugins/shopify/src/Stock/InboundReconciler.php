<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Models\Inventory\VariantStockOperation;
use App\Services\Inventory\VariantStockService;
use Illuminate\Support\Facades\DB;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryLevel;
use Inventoros\Plugins\Shopify\Models\ShopifyStockIssue;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\Models\ShopifyWebhookEvent;
use Inventoros\Plugins\Shopify\ShopifyClient;

/**
 * Brings changes made in Shopify into Inventoros, without ever counting
 * one twice.
 *
 * A Shopify webhook only says "this level changed"; the reconciler reads the
 * level again (all quantity names) and compares, for each name, what Shopify
 * changed since the two sides last agreed (dS) with what Inventoros changed
 * since then (dL, in Shopify's terms). Their difference r = dS - dL is what
 * the other side has not seen:
 *
 *  - committed differs: an order Shopify took, shipped or cancelled has not
 *    been processed here yet. Nothing is applied; the check waits and runs
 *    again. If it keeps differing it becomes an issue for the operator.
 *  - otherwise r is a change made in Shopify (an adjustment in the Shopify
 *    admin, units marked damaged...): it is applied here as an external
 *    operation, which is never sent back.
 *  - while an operation of ours for the level is still on its way, nothing
 *    is decided: the webhook may be the echo of it.
 *  - if an operation of ours for the level failed or is in conflict, a
 *    difference is not applied either: it becomes an issue, because the
 *    figures may disagree for either reason.
 *
 * The order webhooks are not reordered by arrival: the check always reads
 * Shopify's current figures, so the order deliveries come in does not
 * matter, and a missed webhook is caught by the periodic check.
 */
class InboundReconciler
{
    /** Checks waiting for orders before the difference becomes an issue (2 minutes apart). */
    private const MAX_DEFERRALS = 15;

    private LevelBook $book;

    private ChannelMap $map;

    public function __construct(
        private readonly ShopifyConnection $connection,
        private ?ShopifyClient $client = null,
    ) {
        $this->client ??= new ShopifyClient($connection);
        $this->book = new LevelBook($connection);
        $this->map = new ChannelMap($connection, $this->client);
    }

    /**
     * Check some levels now.
     *
     * @param  list<array{item: string, location: string}>  $pairs
     * @return array<string, int> outcome => count
     */
    public function check(array $pairs): array
    {
        $summary = [];
        $items = array_values(array_unique(array_map(fn ($p) => ShopifyClient::gid('InventoryItem', $p['item']), $pairs)));
        $locations = $this->map->locations();

        if ($items === [] || $locations === []) {
            return $summary;
        }

        $levels = $this->client->inventoryLevels($items, $locations);

        foreach ($pairs as $pair) {
            $item = ShopifyClient::gid('InventoryItem', $pair['item']);
            $location = ShopifyClient::gid('Location', $pair['location']);
            if (! in_array($location, $locations, true)) {
                continue;
            }
            $read = $levels[$item][$location] ?? null;
            $outcome = $this->checkLevel($item, $location, $read['quantities'] ?? array_fill_keys(ShopifyInventoryLevel::NAMES, 0), $read['updated_at'] ?? null);
            $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
        }

        return $summary;
    }

    /**
     * Compare one level with what Shopify reported for it.
     *
     * @param  array<string, int>  $shopify
     * @return string outcome: synced, applied, deferred, conflict, unmapped, paused
     */
    public function checkLevel(string $item, string $location, array $shopify, ?string $updatedAt = null): string
    {
        $level = $this->book->level($item, $location);
        $level->fillShopify($shopify);
        $level->read_at = now();
        $level->shopify_updated_at = $updatedAt;
        $level->save();

        if ($level->product_variant_id === null || $level->warehouse_id === null) {
            $this->book->refreshStatus($level);

            return 'unmapped';
        }

        if (! $this->connection->isTwoWay()) {
            return 'paused';
        }

        // Our own operation on its way: this may be its echo.
        $open = $this->book->openOperations($level);
        if ($open->contains(fn ($op) => in_array($op->status, [ShopifyStockOperation::PENDING, ShopifyStockOperation::SENDING, ShopifyStockOperation::AWAITING_APPROVAL], true))) {
            $this->book->refreshStatus($level);

            return 'deferred';
        }

        $local = $this->map->expected((int) $level->product_variant_id, (int) $level->warehouse_id);

        if ($level->agreed_s === null || $level->agreed_l === null) {
            // Never compared: only identical figures can be taken as agreed.
            if ($this->same($shopify, $local)) {
                $this->book->agree($level, $shopify, $local);

                return 'synced';
            }

            return $this->issue($level, 'unexplained_difference', 'Shopify and Inventoros have different quantities and were never compared. Run the stock set-up comparison or decide which figure is right.', $shopify, $local);
        }

        $dS = $this->diff($shopify, $level->agreed_s);
        $dL = $this->diff($local, $level->agreed_l);

        // Orders on one side not yet processed on the other.
        if ($dS['committed'] !== $dL['committed'] || $this->ordersWaiting()) {
            return $this->defer($level, $shopify, $local);
        }

        $r = [];
        foreach (['on_hand', 'reserved', 'damaged', 'quality_control'] as $name) {
            $r[$name] = $dS[$name] - $dL[$name];
        }

        $incoming = (int) $shopify['incoming'] - (int) $local['incoming'];

        if (array_filter($r) === []) {
            if ($incoming !== 0) {
                $this->applyExternal($level, ['incoming' => $incoming], $shopify);
                $local = $this->map->expected((int) $level->product_variant_id, (int) $level->warehouse_id);
            }
            $this->book->agree($level, $shopify, $local);

            return 'synced';
        }

        if ($open->isNotEmpty()) {
            return $this->issue($level, 'unexplained_difference', 'Shopify and Inventoros differ while one of our changes for this item was refused: decide which figure is right.', $shopify, $local);
        }

        // A change made in Shopify: bring it here.
        $this->applyExternal($level, array_filter($r + ['incoming' => $incoming]), $shopify);
        $this->book->agree($level, $shopify, $this->map->expected((int) $level->product_variant_id, (int) $level->warehouse_id));

        return 'applied';
    }

    /**
     * @param  array<string, int>  $deltas
     * @param  array<string, int>  $shopify
     */
    private function applyExternal(ShopifyInventoryLevel $level, array $deltas, array $shopify): void
    {
        if ($deltas === []) {
            return;
        }

        DB::transaction(function () use ($level, $deltas, $shopify) {
            app(VariantStockService::class)->apply((int) $this->connection->organization_id, VariantStockOperation::TYPE_CHANNEL_SYNC, [[
                'variant' => (int) $level->product_variant_id,
                'warehouse_id' => (int) $level->warehouse_id,
                // Shopify does not know owners: units it adds or removes are
                // "to be attributed" until the operator assigns them.
                'owner_id' => null,
                'deltas' => $deltas,
            ]], [
                'source' => VariantStockOperation::SOURCE_EXTERNAL,
                'allow_negative' => true,
                'user_id' => $this->connection->user_id,
                'reason' => 'Changed in Shopify',
                'meta' => ['channel' => 'shopify', 'inventory_item_id' => $level->inventory_item_id, 'location_id' => $level->location_id, 'shopify' => $shopify],
            ]);
        });
    }

    /**
     * @param  array<string, int>  $shopify
     * @param  array<string, int>  $local
     */
    private function defer(ShopifyInventoryLevel $level, array $shopify, array $local): string
    {
        $level->deferrals++;
        $level->check_after = now()->addMinutes(2);
        $level->save();

        if ($level->deferrals >= self::MAX_DEFERRALS) {
            return $this->issue($level, 'committed_mismatch',
                'Units committed to orders differ between Shopify and Inventoros for more than half an hour: an order may have been missed or assigned elsewhere. Re-align the order or check it.',
                $shopify, $local);
        }

        $this->book->refreshStatus($level);

        return 'deferred';
    }

    /**
     * @param  array<string, int>  $shopify
     * @param  array<string, int>  $local
     */
    private function issue(ShopifyInventoryLevel $level, string $kind, string $message, array $shopify, array $local): string
    {
        ShopifyStockIssue::raise([
            'organization_id' => $this->connection->organization_id,
            'kind' => $kind,
            'inventory_item_id' => $level->inventory_item_id,
            'location_id' => $level->location_id,
            'product_variant_id' => $level->product_variant_id,
            'warehouse_id' => $level->warehouse_id,
            'message' => $message,
            'details' => ['shopify' => $shopify, 'inventoros' => $local, 'agreed_shopify' => $level->agreed_s, 'agreed_inventoros' => $level->agreed_l],
        ]);

        $this->book->refreshStatus($level);

        return 'conflict';
    }

    /**
     * Order webhooks received but not processed yet: their effect may
     * already be in Shopify's figures.
     */
    private function ordersWaiting(): bool
    {
        return ShopifyWebhookEvent::query()
            ->where('organization_id', $this->connection->organization_id)
            ->where('topic', 'like', 'orders/%')
            ->whereIn('status', [ShopifyWebhookEvent::RECEIVED, ShopifyWebhookEvent::PROCESSING, ShopifyWebhookEvent::DEFERRED])
            ->exists();
    }

    /**
     * @param  array<string, int>  $now
     * @param  array<string, int>  $then
     * @return array<string, int>
     */
    private function diff(array $now, array $then): array
    {
        $out = [];
        foreach (ShopifyInventoryLevel::COMPARED as $name) {
            $out[$name] = (int) ($now[$name] ?? 0) - (int) ($then[$name] ?? 0);
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $shopify
     * @param  array<string, int>  $local
     */
    private function same(array $shopify, array $local): bool
    {
        foreach (ShopifyInventoryLevel::COMPARED as $name) {
            if ((int) ($shopify[$name] ?? 0) !== (int) ($local[$name] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve an issue by taking Shopify's figures as right: the difference
     * is applied here and the level agreed.
     */
    public function acceptShopify(ShopifyStockIssue $issue, ?int $userId = null): void
    {
        $level = $this->book->level((string) $issue->inventory_item_id, (string) $issue->location_id);
        $read = $this->client->inventoryLevels([$level->inventory_item_id], [$level->location_id]);
        $shopify = $read[$level->inventory_item_id][$level->location_id]['quantities'] ?? array_fill_keys(ShopifyInventoryLevel::NAMES, 0);
        $local = $this->map->expected((int) $level->product_variant_id, (int) $level->warehouse_id);

        $deltas = [];
        foreach (['on_hand', 'reserved', 'damaged', 'quality_control', 'incoming'] as $name) {
            $deltas[$name] = (int) ($shopify[$name] ?? 0) - (int) ($local[$name] ?? 0);
        }
        $this->applyExternal($level, array_filter($deltas), $shopify);

        $this->cancelRefused($issue);
        $issue->forceFill(['status' => 'resolved', 'resolution' => 'accepted_shopify', 'resolved_by' => $userId, 'resolved_at' => now()])->save();
        $this->book->agree($level, $shopify, $this->map->expected((int) $level->product_variant_id, (int) $level->warehouse_id));
    }

    /**
     * Resolve an issue by sending Inventoros's figures: an absolute on_hand
     * based on Shopify's current figure, so it cannot overwrite a change
     * made in the meantime without being refused again.
     */
    public function keepInventoros(ShopifyStockIssue $issue, ?int $userId = null): ShopifyStockOperation
    {
        $level = $this->book->level((string) $issue->inventory_item_id, (string) $issue->location_id);
        $read = $this->client->inventoryLevels([$level->inventory_item_id], [$level->location_id]);
        $shopify = $read[$level->inventory_item_id][$level->location_id]['quantities'] ?? array_fill_keys(ShopifyInventoryLevel::NAMES, 0);
        $local = $this->map->expected((int) $level->product_variant_id, (int) $level->warehouse_id);

        $this->cancelRefused($issue);

        $effect = [];
        OutboundPlanner::addEffect($effect, $level->inventory_item_id, $level->location_id, 'on_hand', $local['on_hand'] - (int) $shopify['on_hand']);

        $operation = ShopifyStockOperation::create([
            'organization_id' => $this->connection->organization_id,
            'kind' => 'set',
            'status' => ShopifyStockOperation::PENDING,
            'mode' => 'absolute',
            'payload' => [
                'name' => 'on_hand',
                'reason' => 'correction',
                'reference' => 'gid://inventoros/ShopifyStockIssue/'.$issue->id,
                'quantities' => [['inventory_item_id' => $level->inventory_item_id, 'location_id' => $level->location_id, 'quantity' => $local['on_hand']]],
                'change_from_override' => [$level->inventory_item_id.'|'.$level->location_id.'|on_hand' => (int) $shopify['on_hand']],
            ],
            'effect' => $effect,
        ]);

        // The level agrees on everything else as Shopify shows it now; the
        // on_hand difference is what the operation sends.
        $shopifyAfter = $shopify;
        $shopifyAfter['on_hand'] = (int) $shopify['on_hand'];
        $level->agreed_s = array_intersect_key($shopifyAfter, array_flip([...ShopifyInventoryLevel::COMPARED, 'available', 'incoming']));
        $level->agreed_l = array_merge($local, ['on_hand' => (int) $shopify['on_hand']]);
        $level->save();

        $issue->forceFill(['status' => 'resolved', 'resolution' => 'kept_inventoros', 'resolved_by' => $userId, 'resolved_at' => now()])->save();
        $this->book->refreshStatus($level);

        return $operation;
    }

    private function cancelRefused(ShopifyStockIssue $issue): void
    {
        if ($issue->stock_operation_id !== null) {
            ShopifyStockOperation::query()->whereKey($issue->stock_operation_id)
                ->whereIn('status', [ShopifyStockOperation::CONFLICT, ShopifyStockOperation::FAILED])
                ->update(['status' => ShopifyStockOperation::CANCELLED]);
        }
    }
}
