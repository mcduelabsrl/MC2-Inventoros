<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\InventorySync;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Match every Shopify variant to Inventoros by SKU and push every matched
 * quantity. Started from the settings page.
 */
class FullInventorySync implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return "shopify-full-sync-{$this->organizationId}";
    }

    public function handle(): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null || ! $connection->legacyPushAllowed()) {
            return;
        }

        try {
            InventorySync::for($connection)->fullSync();
        } catch (ShopifyException $e) {
            $connection->forceFill(['last_error' => 'Full stock sync failed: '.$e->getMessage()])->save();
            if ($e->transient) {
                throw $e;
            }
        }
    }
}
