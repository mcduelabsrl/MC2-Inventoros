<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\OrderImporter;

/**
 * Handle a verified Shopify order webhook outside the request, so Shopify
 * gets its 200 within its five-second limit.
 */
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $connectionId,
        public readonly string $topic,
        public readonly array $payload,
    ) {}

    public function handle(OrderImporter $importer): void
    {
        $connection = ShopifyConnection::query()->find($this->connectionId);
        if ($connection === null || ! $connection->import_orders) {
            return;
        }

        match ($this->topic) {
            'orders/create' => $importer->import($connection, $this->payload),
            'orders/cancelled' => $importer->cancel($connection, $this->payload),
            default => null,
        };
    }
}
