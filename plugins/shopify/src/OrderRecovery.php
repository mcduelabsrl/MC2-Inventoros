<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use Illuminate\Support\Carbon;
use Inventoros\Plugins\Shopify\Http\SettingsController;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;

/**
 * The backstop for webhooks: reads the orders Shopify changed since the last
 * run and reconciles each, so an order whose webhook never arrived (an
 * outage longer than Shopify's retries, a removed subscription) is still
 * imported, and edits, fulfilments, refunds and cancellations still apply.
 * It also puts back webhook subscriptions Shopify removed.
 *
 * Scheduled runs only import orders created after the store was connected
 * (orders_since), so connecting never pulls in old orders; a manual run from
 * a chosen date imports the orders created since then.
 */
class OrderRecovery
{
    /** Re-read a few minutes before the last run, for orders saved during it. */
    private const OVERLAP_MINUTES = 5;

    public function __construct(private readonly ShopifyConnection $connection, private readonly OrderImporter $importer) {}

    /**
     * @param  Carbon|null  $from  Manual run: import orders created since then. Null for the scheduled run.
     * @return array<string, mixed> summary, also stored on the connection
     */
    public function run(?Carbon $from = null): array
    {
        $startedAt = now();
        $connection = $this->connection;

        $since = $from ?? ($connection->orders_synced_at ?? $connection->orders_since ?? $connection->created_at ?? $startedAt)
            ->copy()->subMinutes(self::OVERLAP_MINUTES);
        $importFrom = $from ?? $connection->orders_since ?? $connection->created_at;

        $summary = ['since' => $since->toIso8601String(), 'manual' => $from !== null, 'checked' => 0, 'imported' => 0, 'updated' => 0, 'not_imported' => 0, 'ignored' => 0];
        $client = new ShopifyClient($connection);

        foreach ($client->ordersUpdatedSince($since) as $node) {
            $payload = OrderPayload::fromGraphql($node);
            $summary['checked']++;

            $createdAt = isset($payload['created_at']) ? Carbon::parse($payload['created_at']) : null;
            $importNew = $importFrom === null || ($createdAt !== null && $createdAt->gte($importFrom));
            $before = ShopifyOrder::query()
                ->where('organization_id', $connection->organization_id)
                ->where('shopify_order_id', $payload['id'])
                ->value('status');

            $record = $this->importer->reconcile($connection, $payload, $importNew);

            if (! $record->exists) {
                $summary['ignored']++; // created before the import window
            } elseif ($before === null || in_array($before, [ShopifyOrder::FAILED, ShopifyOrder::SKIPPED], true)) {
                $record->status === ShopifyOrder::IMPORTED ? $summary['imported']++ : $summary['not_imported']++;
            } else {
                $summary['updated']++;
            }
        }

        $summary['webhooks_repaired'] = $this->repairWebhooks($client);
        $summary['finished_at'] = now()->toIso8601String();

        $connection->forceFill([
            // Scheduled runs continue from here; a manual run from an older
            // date must not move the cursor back.
            'orders_synced_at' => $from === null || $connection->orders_synced_at === null || $startedAt->gt($connection->orders_synced_at) ? $startedAt : $connection->orders_synced_at,
            'orders_sync_summary' => $summary,
        ])->save();

        return $summary;
    }

    /**
     * Re-subscribe the order webhooks Shopify dropped (it removes a
     * subscription after repeated failed deliveries).
     *
     * @return array<int, string> topics put back
     */
    private function repairWebhooks(ShopifyClient $client): array
    {
        $url = SettingsController::webhookUrl($this->connection);
        if ($this->connection->webhooks_registered_at === null || ! str_starts_with($url, 'https://')) {
            return []; // never registered here, or Shopify could not reach it anyway
        }

        $missing = $client->missingWebhooks($url);
        if ($missing !== []) {
            $client->registerWebhooks($url);
        }

        $this->connection->forceFill(['webhooks_checked_at' => now()])->save();

        return $missing;
    }
}
