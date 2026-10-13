<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\Stock\ChannelMap;
use Inventoros\Plugins\Shopify\Stock\InboundReconciler;

/**
 * Read every inventory level of the mapped locations and check it (see
 * InboundReconciler): what no webhook reported is found here. Reads only;
 * differences are applied or raised as issues, never answered with a push
 * of local totals.
 */
class FullStockReconcile implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return 'shopify-full-reconcile-'.$this->organizationId;
    }

    public function handle(): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null || ! $connection->isTwoWay()) {
            return;
        }

        $client = new ShopifyClient($connection);
        $reconciler = new InboundReconciler($connection, $client);
        $locations = (new ChannelMap($connection))->locations();
        $summary = [];

        foreach ($client->allInventoryLevels($locations) as $node) {
            if (! $node['tracked']) {
                continue;
            }
            foreach ($locations as $location) {
                $level = $node['levels'][$location] ?? null;
                $outcome = $reconciler->checkLevel(
                    $node['inventory_item_id'],
                    $location,
                    $level['quantities'] ?? array_fill_keys(ShopifyClient::QUANTITY_NAMES, 0),
                    $level['updated_at'] ?? null,
                );
                $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
            }
        }

        $connection->forceFill(['stock_reconciled_at' => now(), 'last_full_sync_summary' => ['reconcile' => $summary, 'finished_at' => now()->toIso8601String()]])->save();
    }
}
