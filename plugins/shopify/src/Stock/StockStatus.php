<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryLevel;
use Inventoros\Plugins\Shopify\Models\ShopifyStockIssue;

/**
 * Shopify's sync status and open issues, for the core stock-by-warehouse
 * screen (filters variant_stock_channel_status / _issues).
 */
class StockStatus
{
    /**
     * @param  array<int, array<int, array<string, mixed>>>  $statuses
     * @param  array<int, int>  $variantIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function statuses(array $statuses, int $organizationId, array $variantIds): array
    {
        $connection = ShopifyConnection::forOrganization($organizationId);
        if ($connection === null || $variantIds === [] || $connection->stock_sync_mode === ShopifyConnection::STOCK_LEGACY) {
            return $statuses;
        }

        $paused = ! $connection->isTwoWay();

        ShopifyInventoryLevel::query()
            ->where('organization_id', $organizationId)
            ->whereIn('product_variant_id', $variantIds)
            ->whereNotNull('warehouse_id')
            ->get()
            ->each(function (ShopifyInventoryLevel $level) use (&$statuses, $paused) {
                $statuses[(int) $level->product_variant_id][(int) $level->warehouse_id] = [
                    'channel' => 'Shopify',
                    // While sync is paused nothing is confirmed on Shopify.
                    'status' => $paused && $level->sync_status === ShopifyInventoryLevel::SYNCED ? 'paused' : $level->sync_status,
                    'detail' => $paused ? 'Sync with Shopify is paused until the set-up comparison is approved.' : $level->status_detail,
                    'last_synced_at' => $level->last_synced_at?->toIso8601String(),
                    'shopify' => $level->shopifyQuantities(),
                ];
            });

        return $statuses;
    }

    /**
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    public static function issues(array $issues, int $organizationId, ?int $variantId): array
    {
        $query = ShopifyStockIssue::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->orderByDesc('id')
            ->limit(200);

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        }

        foreach ($query->get() as $issue) {
            $actions = [];
            if ($issue->inventory_item_id !== null && $issue->location_id !== null && $issue->product_variant_id !== null) {
                $actions[] = ['label' => 'Use Shopify figures', 'url' => route('plg.shopify.stock.issues.accept', $issue->id)];
                $actions[] = ['label' => 'Send Inventoros figures', 'url' => route('plg.shopify.stock.issues.keep', $issue->id)];
            }
            if ($issue->kind === 'committed_mismatch' || $issue->order_id !== null) {
                $actions[] = ['label' => 'Re-align order with Shopify', 'url' => route('plg.shopify.stock.issues.realign', $issue->id)];
            }
            if ($issue->stock_operation_id !== null && $issue->kind === 'operation_failed') {
                $actions[] = ['label' => 'Retry', 'url' => route('plg.shopify.stock.operations.retry', $issue->stock_operation_id)];
            }

            $issues[] = [
                'id' => 'shopify-'.$issue->id,
                'channel' => 'Shopify',
                'kind' => $issue->kind,
                'message' => $issue->message,
                'product_variant_id' => $issue->product_variant_id,
                'warehouse_id' => $issue->warehouse_id,
                'details' => $issue->details,
                'created_at' => $issue->created_at?->toIso8601String(),
                'actions' => $actions,
            ];
        }

        return $issues;
    }
}
