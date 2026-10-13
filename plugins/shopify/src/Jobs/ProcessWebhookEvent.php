<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyWebhookEvent;
use Inventoros\Plugins\Shopify\OrderImporter;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\Stock\StockBootstrap;
use Throwable;

/**
 * Process one stored webhook delivery.
 *
 * - Order topics reconcile the order (OrderImporter: one lock per order,
 *   and a payload older than the state already applied is ignored).
 * - inventory_levels/update asks for a check of that level a little later
 *   (InboundReconciler re-reads Shopify, so it does not matter which of
 *   several deliveries for the level arrives last).
 * - While the stock set-up runs, events wait (deferred) and are processed
 *   right after it; nothing is lost.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public readonly int $eventId) {}

    public function handle(OrderImporter $importer): void
    {
        $event = ShopifyWebhookEvent::query()->find($this->eventId);
        if ($event === null || in_array($event->status, [ShopifyWebhookEvent::PROCESSED, ShopifyWebhookEvent::IGNORED], true)) {
            return;
        }

        $connection = ShopifyConnection::forOrganization((int) $event->organization_id);
        if ($connection === null) {
            $event->forceFill(['status' => ShopifyWebhookEvent::IGNORED])->save();

            return;
        }

        if (StockBootstrap::isRunning((int) $event->organization_id)) {
            $event->forceFill(['status' => ShopifyWebhookEvent::DEFERRED])->save();
            $this->release(60);

            return;
        }

        $lock = Cache::lock('shopify-webhook-event-'.$event->id, 300);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $event->forceFill(['status' => ShopifyWebhookEvent::PROCESSING, 'attempts' => $event->attempts + 1])->save();
            self::process($event, $connection, $importer);
            $event->forceFill(['status' => ShopifyWebhookEvent::PROCESSED, 'processed_at' => now(), 'error' => null])->save();
        } catch (Throwable $e) {
            $event->forceFill(['status' => ShopifyWebhookEvent::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function process(ShopifyWebhookEvent $event, ShopifyConnection $connection, OrderImporter $importer): void
    {
        $payload = $event->data();

        if ($event->topic === 'inventory_levels/update') {
            $item = isset($payload['inventory_item_id']) ? ShopifyClient::gid('InventoryItem', (string) $payload['inventory_item_id']) : null;
            $location = isset($payload['location_id']) ? ShopifyClient::gid('Location', (string) $payload['location_id']) : null;

            if ($item !== null && $location !== null && $connection->warehouseForLocation($location) !== null) {
                // A short delay lets the order webhook of the same change
                // arrive and be processed first.
                ReconcileInventoryLevels::dispatch((int) $connection->organization_id, [['item' => $item, 'location' => $location]])
                    ->delay(now()->addSeconds(20));
            }

            return;
        }

        if (! $connection->import_orders) {
            return;
        }

        if ($event->topic === 'orders/cancelled') {
            $importer->cancel($connection, $payload);

            return;
        }

        $createdAt = isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : null;
        $importNew = $event->topic === 'orders/create'
            || $connection->orders_since === null
            || $createdAt === null
            || $createdAt->gte($connection->orders_since);

        $importer->reconcile($connection, $payload, $importNew);
    }
}
