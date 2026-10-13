<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Stock\OutboxProcessor;

/**
 * Send the store's pending stock operations to Shopify (see
 * OutboxProcessor). One per store at a time; the scheduler starts it again
 * every minute while operations wait, so a retry needs no queue backoff.
 */
class ProcessStockOutbox implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return 'shopify-outbox-'.$this->organizationId;
    }

    public function handle(): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null) {
            return;
        }

        (new OutboxProcessor($connection))->run();
    }
}
