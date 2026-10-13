<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Enums\OrderStatus;
use App\Models\Auth\Organization;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockOwner;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockOperation;
use App\Models\Order\Order;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStockService;
use App\Support\VariantWarehouseStockSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inventoros\Plugins\Shopify\Jobs\ProcessStockOutbox;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhookEvent;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryLevel;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\Models\ShopifyWebhookEvent;
use Inventoros\Plugins\Shopify\OrderImporter;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Sets up stock per warehouse from Shopify, repeatably:
 *
 *  1. creates (or reuses) the two warehouses and the two owners, and maps
 *     each Shopify location to its warehouse (main warehouse first);
 *  2. reads every inventory item at both locations (all quantity names);
 *  3. sets each variant's per-warehouse balances to Shopify's figures,
 *     owner "to be assigned" (Shopify knows no owners), as opening-balance
 *     operations that are never sent back to Shopify;
 *  4. commits the units of the open Shopify orders where Shopify assigned
 *     them (no stock is taken again: on-hand comes from Shopify as is);
 *  5. processes what arrived meanwhile (webhooks wait while it runs), then
 *     re-reads the levels those events touched;
 *  6. records the figures both sides agree on, and writes a comparison per
 *     variant and location.
 *
 * Nothing is written to Shopify. Stock sync stays paused until activate()
 * is called, after the comparison has been checked.
 *
 * Run without $apply it only reads and compares (dry run).
 */
class StockBootstrap
{
    public const MAIN = ['code' => 'MC2', 'name' => 'MC2 Lab Srl — Via Luccoli', 'location' => 'MC2 Lab Srl', 'priority' => 10];

    public const SECOND = ['code' => 'SAX', 'name' => 'Saxone — Via Maragliano', 'location' => 'VIA MARAGLIANO 7/1', 'priority' => 5];

    public const OWNERS = [['code' => 'MC2', 'name' => 'MC2'], ['code' => 'SAXONE', 'name' => 'Saxone']];

    private ShopifyClient $client;

    public function __construct(private readonly ShopifyConnection $connection, ?ShopifyClient $client = null)
    {
        $this->client = $client ?? new ShopifyClient($connection);
    }

    public static function isRunning(int $organizationId): bool
    {
        return Cache::has('shopify-stock-bootstrap-'.$organizationId);
    }

    /**
     * @param  array{main_location?: string, second_location?: string}  $options
     * @return array<string, mixed> summary, with the comparison file path
     */
    public function run(bool $apply, array $options = []): array
    {
        $orgId = (int) $this->connection->organization_id;
        $locations = $this->resolveLocations($options);
        $summary = ['mode' => $apply ? 'apply' : 'dry_run', 'started_at' => now()->toIso8601String(), 'locations' => $locations];

        if ($apply) {
            Cache::put('shopify-stock-bootstrap-'.$orgId, true, now()->addHours(3));
        }

        try {
            $warehouses = $apply ? $this->ensureWarehouses() : $this->existingWarehouses();
            $summary['warehouses'] = $warehouses;

            if ($apply) {
                $this->ensureOwners();
                $this->connection->forceFill([
                    'location_map' => [
                        ['location_id' => $locations['main'], 'warehouse_id' => $warehouses['main']],
                        ['location_id' => $locations['second'], 'warehouse_id' => $warehouses['second']],
                    ],
                    'warehouse_id' => $warehouses['main'],
                    'push_inventory' => true,
                    'stock_sync_mode' => ShopifyConnection::STOCK_PAUSED,
                    'stock_baseline_at' => now(),
                ])->save();

                VariantWarehouseStockSettings::save(Organization::query()->findOrFail($orgId), new VariantWarehouseStockSettings(
                    enabled: true,
                    defaultWarehouseId: $warehouses['main'],
                    returnsWarehouseId: $warehouses['main'],
                ));

                $summary['legacy_jobs_removed'] = self::purgeLegacyJobs();
            }

            $byLocation = [$locations['main'] => $warehouses['main'], $locations['second'] => $warehouses['second']];

            // 2. Shopify's figures.
            $shopify = $this->readShopify(array_keys($byLocation), $summary);

            // 3. Balances (on hand and unsellable states; not committed).
            if ($apply) {
                $summary['balances'] = $this->setBalances($shopify, $byLocation);
            }

            // 4. Open orders: their units are committed where Shopify
            // assigned them, out of the balances just set.
            $summary['orders'] = $apply ? $this->commitOpenOrders() : $this->countOpenOrders();
        } finally {
            if ($apply) {
                Cache::forget('shopify-stock-bootstrap-'.$orgId);
            }
        }

        if ($apply) {
            // 5. What arrived meanwhile, then the levels it touched again.
            $touched = $this->processWaitingEvents();
            if ($touched !== []) {
                $fresh = $this->client->inventoryLevels($touched, array_keys($byLocation));
                foreach ($fresh as $item => $levels) {
                    $shopify[$item]['levels'] = $levels;
                }
                $this->setBalances(array_intersect_key($shopify, array_flip($touched)), $byLocation, 'settle');
            }
            $summary['events_processed_after'] = count($touched);

            // 6. Agreement.
            $this->agree($shopify, $byLocation);
        }

        $summary['comparison'] = $this->compare($shopify, $byLocation, $apply);
        $summary['finished_at'] = now()->toIso8601String();

        if ($apply) {
            $this->connection->forceFill(['stock_bootstrap_summary' => $summary])->save();
        }

        return $summary;
    }

    /**
     * Turn on two-way sync after the comparison was approved: changes made
     * here since the set-up are queued for Shopify, then every level is
     * checked once.
     *
     * @return array{queued: int}
     */
    public function activate(): array
    {
        if ($this->connection->stock_baseline_at === null) {
            throw new ShopifyException('Run the stock set-up first.');
        }

        $this->connection->forceFill(['stock_sync_mode' => ShopifyConnection::STOCK_TWO_WAY])->save();

        $planner = new OutboundPlanner($this->connection);
        $queued = 0;
        VariantStockOperation::query()
            ->where('organization_id', $this->connection->organization_id)
            ->where('source', VariantStockOperation::SOURCE_LOCAL)
            ->where('created_at', '>=', $this->connection->stock_baseline_at)
            ->whereNotIn('id', ShopifyStockOperation::query()->whereNotNull('variant_stock_operation_id')->select('variant_stock_operation_id'))
            ->orderBy('id')
            ->each(function (VariantStockOperation $operation) use ($planner, &$queued) {
                $queued += count($planner->plan($operation));
            });

        ProcessStockOutbox::dispatch((int) $this->connection->organization_id);

        return ['queued' => $queued];
    }

    /**
     * Remove 1.x stock pushes still waiting in a database queue: they would
     * pour the total of every warehouse into one Shopify location.
     */
    public static function purgeLegacyJobs(): int
    {
        if (config('queue.default') !== 'database' || ! Schema::hasTable('jobs')) {
            return 0;
        }

        return DB::table('jobs')
            ->where(fn ($q) => $q->where('payload', 'like', '%PushInventory%')->orWhere('payload', 'like', '%FullInventorySync%'))
            ->delete();
    }

    // ------------------------------------------------------------------

    /**
     * @param  array{main_location?: string, second_location?: string}  $options
     * @return array{main: string, second: string}
     */
    private function resolveLocations(array $options): array
    {
        $info = $this->client->shopInfo();
        $find = function (string $name) use ($info): string {
            foreach ($info['locations'] as $location) {
                if (strcasecmp(trim($location['name']), trim($name)) === 0 || $location['id'] === $name) {
                    return $location['id'];
                }
            }

            throw new ShopifyException("Shopify has no active location named \"{$name}\". Locations: ".implode(', ', array_column($info['locations'], 'name')));
        };

        return [
            'main' => $find($options['main_location'] ?? self::MAIN['location']),
            'second' => $find($options['second_location'] ?? self::SECOND['location']),
        ];
    }

    /**
     * @return array{main: int, second: int}
     */
    private function ensureWarehouses(): array
    {
        $out = [];
        foreach (['main' => self::MAIN, 'second' => self::SECOND] as $role => $spec) {
            $warehouse = Warehouse::withoutGlobalScopes()
                ->where('organization_id', $this->connection->organization_id)
                ->where('code', $spec['code'])
                ->first();

            $warehouse ??= Warehouse::withoutGlobalScopes()->create([
                'organization_id' => $this->connection->organization_id,
                'name' => $spec['name'],
                'code' => $spec['code'],
                'is_active' => true,
                'priority' => $spec['priority'],
            ]);

            $warehouse->forceFill(['is_active' => true, 'is_default' => $role === 'main', 'priority' => $spec['priority']])->save();
            $out[$role] = (int) $warehouse->id;
        }

        Warehouse::withoutGlobalScopes()->where('organization_id', $this->connection->organization_id)
            ->whereNotIn('id', $out)->update(['is_default' => false]);

        return $out;
    }

    /**
     * @return array{main: int|null, second: int|null}
     */
    private function existingWarehouses(): array
    {
        $find = fn (string $code) => Warehouse::withoutGlobalScopes()->where('organization_id', $this->connection->organization_id)
            ->where('code', $code)->value('id');

        return ['main' => $find(self::MAIN['code']), 'second' => $find(self::SECOND['code'])];
    }

    private function ensureOwners(): void
    {
        foreach (self::OWNERS as $owner) {
            StockOwner::query()->firstOrCreate(
                ['organization_id' => $this->connection->organization_id, 'code' => $owner['code']],
                ['name' => $owner['name'], 'is_active' => true],
            );
        }
    }

    /**
     * Every Shopify inventory item with its variant here and its levels.
     *
     * @param  list<string>  $locations
     * @param  array<string, mixed>  $summary
     * @return array<string, array{variant_id: int|null, sku: string|null, levels: array<string, array{quantities: array<string, int>, updated_at: string|null}>}>
     */
    private function readShopify(array $locations, array &$summary): array
    {
        $out = [];
        $unmatched = 0;
        $orgId = (int) $this->connection->organization_id;

        foreach ($this->client->allInventoryLevels($locations) as $node) {
            if (! $node['tracked']) {
                continue;
            }
            $sku = $node['sku'] ?? ($node['variant_id'] !== null ? ShopifyClient::generatedSku($node['variant_id']) : null);
            $variant = $sku === null ? null : ProductVariant::query()->where('organization_id', $orgId)->where('sku', $sku)->first();

            if ($sku !== null) {
                ShopifyInventoryItem::query()->updateOrCreate(
                    ['organization_id' => $orgId, 'sku' => $sku],
                    ['inventory_item_id' => $node['inventory_item_id'], 'variant_id' => $node['variant_id'] !== null ? ShopifyClient::gid('ProductVariant', $node['variant_id']) : null, 'tracked' => true],
                );
            }

            if ($variant === null) {
                $unmatched++;
            }

            $out[$node['inventory_item_id']] = ['variant_id' => $variant?->id, 'sku' => $sku, 'levels' => $node['levels']];
        }

        $summary['shopify_items'] = count($out);
        $summary['shopify_items_without_variant'] = $unmatched;

        return $out;
    }

    /**
     * @return array{open: int, realigned: int, notes: array<int, string>}
     */
    private function commitOpenOrders(): array
    {
        $importer = app(OrderImporter::class);
        $result = ['open' => 0, 'realigned' => 0, 'notes' => []];

        $this->openOrders()->each(function (Order $order) use ($importer, &$result) {
            $result['open']++;
            $plan = $importer->allocationPlan($this->connection, (string) $order->external_id);
            if ($plan === null) {
                $result['notes'][] = "{$order->external_reference}: fulfilment orders not readable; nothing committed.";

                return;
            }

            $changed = app(VariantStockService::class)->realignOrder($order, $plan, [
                'source' => VariantStockOperation::SOURCE_IMPORT,
                'user_id' => $this->connection->user_id,
                'reason' => "Stock set-up: order {$order->external_reference}",
            ]);
            $result['realigned'] += $changed ? 1 : 0;
        });

        return $result;
    }

    /**
     * @return array{open: int}
     */
    private function countOpenOrders(): array
    {
        return ['open' => $this->openOrders()->count()];
    }

    /**
     * @return Collection<int, Order>
     */
    private function openOrders(): Collection
    {
        return Order::withoutGlobalScopes()
            ->where('organization_id', $this->connection->organization_id)
            ->where('source', 'shopify')
            ->whereIn('status', [OrderStatus::PENDING->value, OrderStatus::PROCESSING->value])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Set each variant's per-warehouse figures (other than committed, which
     * comes from the orders) to Shopify's.
     *
     * @param  array<string, array{variant_id: int|null, sku: string|null, levels: array<string, array{quantities: array<string, int>, updated_at: string|null}>}>  $shopify
     * @param  array<string, int>  $byLocation
     * @return array{variants: int, operations: int}
     */
    private function setBalances(array $shopify, array $byLocation, string $phase = 'opening'): array
    {
        $service = app(VariantStockService::class);
        $run = (string) Str::uuid();
        $result = ['variants' => 0, 'operations' => 0];

        foreach ($shopify as $item => $entry) {
            if ($entry['variant_id'] === null) {
                continue;
            }
            $result['variants']++;
            $changes = [];

            foreach ($byLocation as $location => $warehouseId) {
                $q = $entry['levels'][$location]['quantities'] ?? array_fill_keys(ShopifyInventoryLevel::NAMES, 0);
                $current = VariantStockLevel::where('product_variant_id', $entry['variant_id'])->where('warehouse_id', $warehouseId)->get();

                $deltas = [];
                foreach (['on_hand', 'quality_control', 'damaged', 'reserved', 'incoming'] as $bucket) {
                    $delta = (int) $q[$bucket] - (int) $current->sum($bucket);
                    if ($delta !== 0) {
                        $deltas[$bucket] = $delta;
                    }
                }

                if ($deltas !== []) {
                    // Owner unknown: Shopify does not have it.
                    $changes[] = ['variant' => $entry['variant_id'], 'warehouse_id' => $warehouseId, 'owner_id' => null, 'deltas' => $deltas];
                }
            }

            if ($changes !== []) {
                $service->apply((int) $this->connection->organization_id, VariantStockOperation::TYPE_OPENING, $changes, [
                    'source' => VariantStockOperation::SOURCE_IMPORT,
                    'allow_negative' => true,
                    'user_id' => $this->connection->user_id,
                    'reason' => $phase === 'opening' ? 'Opening balance from Shopify' : 'Opening balance from Shopify (changes during set-up)',
                    'idempotency_key' => "shopify-bootstrap:{$run}:{$entry['variant_id']}",
                    'meta' => ['inventory_item_id' => $item, 'phase' => $phase],
                ]);
                $result['operations']++;
            }
        }

        return $result;
    }

    /**
     * Process webhook deliveries that waited during the set-up, oldest
     * first. Returns the inventory items they touched.
     *
     * @return list<string>
     */
    private function processWaitingEvents(): array
    {
        $importer = app(OrderImporter::class);
        $touched = [];

        ShopifyWebhookEvent::query()
            ->where('organization_id', $this->connection->organization_id)
            ->whereIn('status', [ShopifyWebhookEvent::RECEIVED, ShopifyWebhookEvent::DEFERRED])
            ->orderBy('id')
            ->each(function (ShopifyWebhookEvent $event) use ($importer, &$touched) {
                try {
                    ProcessWebhookEvent::process($event, $this->connection->fresh(), $importer);
                    $event->forceFill(['status' => ShopifyWebhookEvent::PROCESSED, 'processed_at' => now()])->save();
                } catch (\Throwable $e) {
                    $event->forceFill(['status' => ShopifyWebhookEvent::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();
                }

                $payload = $event->data();
                if ($event->topic === 'inventory_levels/update' && isset($payload['inventory_item_id'])) {
                    $touched[] = ShopifyClient::gid('InventoryItem', (string) $payload['inventory_item_id']);
                }
                foreach ((array) ($payload['line_items'] ?? []) as $line) {
                    $sku = is_array($line) ? trim((string) ($line['sku'] ?? '')) : '';
                    $row = $sku === '' ? null : ShopifyInventoryItem::query()->where('organization_id', $this->connection->organization_id)->where('sku', $sku)->first();
                    if ($row !== null) {
                        $touched[] = ShopifyClient::gid('InventoryItem', $row->inventory_item_id);
                    }
                }
            });

        return array_values(array_unique($touched));
    }

    /**
     * @param  array<string, array{variant_id: int|null, sku: string|null, levels: array<string, array{quantities: array<string, int>, updated_at: string|null}>}>  $shopify
     * @param  array<string, int>  $byLocation
     */
    private function agree(array $shopify, array $byLocation): void
    {
        $book = new LevelBook($this->connection);
        $map = new ChannelMap($this->connection);

        foreach ($shopify as $item => $entry) {
            foreach ($byLocation as $location => $warehouseId) {
                $level = $book->level($item, $location, $entry['variant_id']);
                $q = $entry['levels'][$location]['quantities'] ?? array_fill_keys(ShopifyInventoryLevel::NAMES, 0);
                $level->shopify_updated_at = $entry['levels'][$location]['updated_at'] ?? null;

                if ($entry['variant_id'] === null) {
                    $level->fillShopify($q);
                    $level->save();
                    $book->refreshStatus($level);

                    continue;
                }

                $book->agree($level, $q, $map->expected((int) $entry['variant_id'], $warehouseId));
            }
        }
    }

    /**
     * One row per variant and location: Shopify's figures, Inventoros's, and
     * whether they match. Committed may differ where Shopify holds units for
     * orders Inventoros does not have (e.g. lines without a matching SKU).
     *
     * @param  array<string, array{variant_id: int|null, sku: string|null, levels: array<string, array{quantities: array<string, int>, updated_at: string|null}>}>  $shopify
     * @param  array<string, int>  $byLocation
     * @return array<string, mixed>
     */
    private function compare(array $shopify, array $byLocation, bool $applied): array
    {
        $map = new ChannelMap($this->connection);
        $names = [];
        foreach ($this->connection->locations ?? [] as $location) {
            $names[$location['id']] = $location['name'];
        }

        $rows = [['sku', 'variant_id', 'location', 'shopify_on_hand', 'shopify_available', 'shopify_committed', 'shopify_unavailable', 'shopify_incoming',
            'inventoros_on_hand', 'inventoros_available', 'inventoros_committed', 'inventoros_unavailable', 'inventoros_incoming', 'status', 'note']];
        $stats = ['rows' => 0, 'match' => 0, 'differ' => 0, 'committed_only' => 0, 'not_in_inventoros' => 0, 'negative_available' => 0,
            'shopify_on_hand' => 0, 'inventoros_on_hand' => 0];

        foreach ($shopify as $entry) {
            foreach ($byLocation as $location => $warehouseId) {
                $q = $entry['levels'][$location]['quantities'] ?? array_fill_keys(ShopifyInventoryLevel::NAMES, 0);
                $sUnavailable = $q['reserved'] + $q['damaged'] + $q['quality_control'] + $q['safety_stock'];

                if ($entry['variant_id'] === null) {
                    if (array_sum($q) !== 0) {
                        $rows[] = [$entry['sku'], '', $names[$location] ?? $location, $q['on_hand'], $q['available'], $q['committed'], $sUnavailable, $q['incoming'], '', '', '', '', '', 'not_in_inventoros', 'No Inventoros variant with this SKU'];
                        $stats['not_in_inventoros']++;
                    }

                    continue;
                }

                if (! $applied || $warehouseId === null) {
                    $local = ['on_hand' => 0, 'committed' => 0, 'reserved' => 0, 'damaged' => 0, 'quality_control' => 0, 'incoming' => 0];
                    if ($warehouseId !== null) {
                        $local = $map->expected((int) $entry['variant_id'], $warehouseId);
                    }
                } else {
                    $local = $map->expected((int) $entry['variant_id'], $warehouseId);
                }
                $lUnavailable = $local['reserved'] + $local['damaged'] + $local['quality_control'];
                $lAvailable = $local['on_hand'] - $local['committed'] - $lUnavailable;

                $sameStock = $q['on_hand'] === $local['on_hand'] && $q['quality_control'] === $local['quality_control']
                    && $q['damaged'] === $local['damaged'] && $q['reserved'] === $local['reserved'];
                $status = $sameStock ? ($q['committed'] === $local['committed'] ? 'match' : 'committed_differs') : 'differs';
                $note = match ($status) {
                    'committed_differs' => 'Shopify holds '.$q['committed'].' for orders, Inventoros '.$local['committed'].': check orders not imported or lines without SKU.',
                    'differs' => $applied ? 'Changed during the set-up: checked again by the next sync.' : 'Will be set to Shopify\'s figures.',
                    default => '',
                };
                if ($lAvailable < 0) {
                    $stats['negative_available']++;
                    $note = trim($note.' Negative available: more committed than on hand.');
                }

                $rows[] = [$entry['sku'], $entry['variant_id'], $names[$location] ?? $location,
                    $q['on_hand'], $q['available'], $q['committed'], $sUnavailable, $q['incoming'],
                    $local['on_hand'], $lAvailable, $local['committed'], $lUnavailable, $local['incoming'], $status, $note];

                $stats['rows']++;
                $stats[$status === 'match' ? 'match' : ($status === 'committed_differs' ? 'committed_only' : 'differ')]++;
                $stats['shopify_on_hand'] += $q['on_hand'];
                $stats['inventoros_on_hand'] += $local['on_hand'];
            }
        }

        $path = 'shopify/stock-comparison-'.$this->connection->organization_id.'-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        Storage::disk('local')->put($path, (string) stream_get_contents($handle));
        fclose($handle);

        return $stats + ['file' => Storage::disk('local')->path($path)];
    }
}
