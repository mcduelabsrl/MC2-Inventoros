<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use Illuminate\Support\Collection;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryLevel;
use Inventoros\Plugins\Shopify\Models\ShopifyStockIssue;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\ShopifyClient;

/**
 * The per item-and-location book of what Shopify showed and what both sides
 * agreed on, and the sync status shown to the operator.
 *
 * Status of a level:
 *  - conflict: an open issue needs a decision;
 *  - error: an operation for it failed;
 *  - pending: an operation is on its way, or a check is waiting for orders
 *    to be processed; never shown as confirmed;
 *  - synced: both sides agree, as of last_synced_at;
 *  - unmapped: Shopify has the item but Inventoros has no variant for it.
 */
class LevelBook
{
    public function __construct(private readonly ShopifyConnection $connection) {}

    public function level(string $item, string $location, ?int $variantId = null): ShopifyInventoryLevel
    {
        $item = ShopifyClient::gid('InventoryItem', $item);
        $location = ShopifyClient::gid('Location', $location);

        $level = ShopifyInventoryLevel::query()->firstOrCreate([
            'organization_id' => $this->connection->organization_id,
            'inventory_item_id' => $item,
            'location_id' => $location,
        ], [
            'warehouse_id' => $this->connection->warehouseForLocation($location),
            'sync_status' => ShopifyInventoryLevel::PENDING,
        ]);

        $updates = [];
        if ($level->product_variant_id === null) {
            $variantId ??= (new ChannelMap($this->connection))->variantForItem($item)?->id;
            if ($variantId !== null) {
                $updates['product_variant_id'] = $variantId;
            }
        }
        if ($level->warehouse_id === null && ($warehouse = $this->connection->warehouseForLocation($location)) !== null) {
            $updates['warehouse_id'] = $warehouse;
        }
        if ($updates !== []) {
            $level->forceFill($updates)->save();
        }

        return $level;
    }

    /**
     * The quantity Shopify had for a name when the two sides last agreed.
     */
    public function agreedShopify(string $item, string $location, string $name): ?int
    {
        $agreed = $this->level($item, $location)->agreed_s;

        return is_array($agreed) && array_key_exists($name, $agreed) ? (int) $agreed[$name] : null;
    }

    /**
     * Move both agreed figures by what a confirmed operation did, so the
     * echo of our own change is recognised as such.
     *
     * @param  array<string, int>  $effect  item|location|name => delta
     */
    public function applyEffect(array $effect): void
    {
        $byPair = [];
        foreach ($effect as $key => $delta) {
            [$item, $location, $name] = explode('|', $key);
            $byPair[$item.'|'.$location][$name] = $delta;
        }

        foreach ($byPair as $pair => $deltas) {
            [$item, $location] = explode('|', $pair);
            $level = $this->level($item, $location);

            $s = $level->agreed_s;
            $l = $level->agreed_l;
            foreach ($deltas as $name => $delta) {
                if (is_array($s)) {
                    $s[$name] = ($s[$name] ?? 0) + $delta;
                }
                if (is_array($l)) {
                    $l[$name] = ($l[$name] ?? 0) + $delta;
                }
                if (in_array($name, ShopifyInventoryLevel::NAMES, true)) {
                    $level->{'s_'.$name} = (int) $level->{'s_'.$name} + $delta;
                }
            }
            $level->agreed_s = $s;
            $level->agreed_l = $l;
            $level->save();
        }
    }

    /**
     * Record that both sides agree now.
     *
     * @param  array<string, int>  $shopify
     * @param  array<string, int>  $local
     */
    public function agree(ShopifyInventoryLevel $level, array $shopify, array $local): void
    {
        $level->fillShopify($shopify);
        $level->agreed_s = array_intersect_key($shopify, array_flip([...ShopifyInventoryLevel::COMPARED, 'available', 'incoming']));
        $level->agreed_l = $local;
        $level->read_at = now();
        $level->deferrals = 0;
        $level->check_after = null;
        $level->last_synced_at = now();
        $level->save();

        $this->refreshStatus($level);
    }

    public function refreshStatus(ShopifyInventoryLevel $level): void
    {
        if ($level->product_variant_id === null || $level->warehouse_id === null) {
            $level->forceFill(['sync_status' => ShopifyInventoryLevel::UNMAPPED])->save();

            return;
        }

        $openIssue = ShopifyStockIssue::query()
            ->where('organization_id', $this->connection->organization_id)
            ->where('status', 'open')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('inventory_item_id', $level->inventory_item_id)->where('location_id', $level->location_id))
                ->orWhereIn('stock_operation_id', $this->openOperations($level)->pluck('id')))
            ->first();

        $ops = $this->openOperations($level);

        [$status, $detail] = match (true) {
            $openIssue !== null && $openIssue->kind !== 'operation_failed' => [ShopifyInventoryLevel::CONFLICT, $openIssue->message],
            $ops->contains(fn ($o) => $o->status === ShopifyStockOperation::FAILED) => [ShopifyInventoryLevel::ERROR, $ops->firstWhere('status', ShopifyStockOperation::FAILED)?->last_error],
            $openIssue !== null => [ShopifyInventoryLevel::ERROR, $openIssue->message],
            $ops->isNotEmpty() => [ShopifyInventoryLevel::PENDING, 'Sending to Shopify'],
            $level->deferrals > 0 => [ShopifyInventoryLevel::PENDING, 'Waiting for orders to be processed'],
            $level->agreed_s === null => [ShopifyInventoryLevel::PENDING, 'Not compared yet'],
            default => [ShopifyInventoryLevel::SYNCED, null],
        };

        $level->forceFill(['sync_status' => $status, 'status_detail' => $detail])->save();
    }

    /**
     * Operations not yet confirmed that touch a level.
     *
     * @return Collection<int, ShopifyStockOperation>
     */
    public function openOperations(ShopifyInventoryLevel $level): Collection
    {
        $prefix = $level->inventory_item_id.'|'.$level->location_id.'|';

        return ShopifyStockOperation::query()
            ->where('organization_id', $this->connection->organization_id)
            ->whereIn('status', ShopifyStockOperation::OPEN)
            ->get()
            ->filter(function (ShopifyStockOperation $op) use ($prefix) {
                foreach (array_keys($op->effect ?? []) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * Refresh the status of every level an operation touches.
     */
    public function refreshFor(ShopifyStockOperation $operation): void
    {
        foreach ($operation->pairs() as $pair) {
            $this->refreshStatus($this->level($pair['item'], $pair['location']));
        }
    }
}
