<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryLevel;
use Inventoros\Plugins\Shopify\ShopifyException;
use Inventoros\Plugins\Shopify\Stock\InboundReconciler;

/**
 * Check inventory levels against Shopify: the given ones, or (null) the
 * ones waiting for a check (deferred, pending, never compared).
 */
class ReconcileInventoryLevels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  list<array{item: string, location: string}>|null  $pairs
     */
    public function __construct(public readonly int $organizationId, public readonly ?array $pairs = null) {}

    public function handle(): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null || ! $connection->isTwoWay()) {
            return;
        }

        $pairs = $this->pairs ?? ShopifyInventoryLevel::query()
            ->where('organization_id', $this->organizationId)
            ->whereNotNull('product_variant_id')
            ->whereIn('sync_status', [ShopifyInventoryLevel::PENDING])
            ->where(fn ($q) => $q->whereNull('check_after')->orWhere('check_after', '<=', now()))
            ->limit(500)
            ->get(['inventory_item_id', 'location_id'])
            ->map(fn ($l) => ['item' => $l->inventory_item_id, 'location' => $l->location_id])
            ->all();

        if ($pairs === []) {
            return;
        }

        try {
            (new InboundReconciler($connection))->check($pairs);
        } catch (ShopifyException $e) {
            if ($e->transient) {
                throw $e;
            }
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }
}
