<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\OrderImporter;
use Inventoros\Plugins\Shopify\OrderRecovery;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Read back recently changed Shopify orders and reconcile them (see
 * OrderRecovery). Scheduled every ten minutes per connected store, and
 * started from the settings page with a date to import missed orders.
 */
class RecoverOrders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $organizationId, public readonly ?string $from = null) {}

    public function uniqueId(): string
    {
        return "shopify-recover-orders-{$this->organizationId}";
    }

    public function handle(OrderImporter $importer): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null || ! $connection->import_orders || blank($connection->shop_name)) {
            return;
        }

        try {
            (new OrderRecovery($connection, $importer))->run($this->from === null ? null : Carbon::parse($this->from));
        } catch (ShopifyException $e) {
            $connection->forceFill(['last_error' => 'Order recovery failed: '.$e->getMessage()])->save();
            if ($e->transient) {
                throw $e;
            }
        }
    }
}
