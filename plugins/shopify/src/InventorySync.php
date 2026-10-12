<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Support\Collection;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;

/**
 * Pushes Inventoros on-hand stock to Shopify as the "available" quantity at
 * each mapped location (see StockLevels for what each location gets).
 *
 * Inventoros takes stock out when an order is created, before it ships, so
 * its on-hand figure is what Shopify calls available (Shopify's own unshipped
 * orders sit in "committed"). Inventoros is the source of truth: quantities
 * are set, never adjusted, and changes made to stock in Shopify's admin are
 * overwritten by the next push.
 *
 * Each SKU's Shopify inventory item is cached in shopify_inventory_items.
 * A product sold by variant is pushed per variant (each variant SKU); a
 * product without variants is pushed under its own SKU.
 */
class InventorySync
{
    private const SAMPLE_SIZE = 50;

    private readonly StockLevels $levels;

    public function __construct(private readonly ShopifyConnection $connection, private readonly ShopifyClient $client)
    {
        $this->levels = new StockLevels($connection);
    }

    public static function for(ShopifyConnection $connection): self
    {
        return new self($connection, new ShopifyClient($connection));
    }

    /**
     * Push one product (or one of its variants) to every mapped location.
     *
     * @return string pushed, unmatched, skipped or failed
     *
     * @throws ShopifyException on transient failures, so the job retries
     */
    public function push(Product $product, ?ProductVariant $variant = null): string
    {
        if ($variant === null && $product->has_variants) {
            return 'skipped'; // its variants are pushed instead
        }

        $sku = trim((string) ($variant?->sku ?? $product->sku));
        if ($sku === '') {
            return 'skipped';
        }

        $item = $this->item($sku);
        if ($item === null) {
            return 'unmatched';
        }

        $entries = [];
        foreach ($this->levels->for($product, $variant) as $locationId => $quantity) {
            $entries[] = ['item' => $item, 'location_id' => $locationId, 'quantity' => $quantity];
        }

        if ($entries === []) {
            return 'skipped';
        }

        return $this->pushBatch($entries) === 0 ? 'pushed' : 'failed';
    }

    /**
     * Refresh the SKU cache from every Shopify variant and push every matched
     * Inventoros product and variant. Returns a summary for the settings page.
     *
     * @return array<string, mixed>
     */
    public function fullSync(): array
    {
        $orgId = $this->connection->organization_id;

        // 1. Every Shopify variant with a SKU (the first one wins on duplicates).
        $shopify = [];
        foreach ($this->client->allVariants() as $variant) {
            $shopify[$variant['sku']] ??= $variant;
        }

        // 2. Refresh the cache: upsert what exists, forget what no longer does.
        $this->cache($shopify);

        // 3. What Inventoros has, per SKU and location.
        $local = $this->localLevels();

        $items = ShopifyInventoryItem::query()
            ->where('organization_id', $orgId)
            ->get()
            ->keyBy('sku');

        $entries = [];
        $matched = 0;
        $untracked = [];
        foreach ($local as $sku => $levels) {
            $item = $items->get($sku);
            if ($item === null) {
                continue;
            }
            $matched++;
            if (! $item->tracked) {
                $untracked[] = $sku;
                $item->forceFill(['last_error' => 'Shopify does not track inventory for this variant.'])->save();

                continue;
            }
            foreach ($levels as $locationId => $quantity) {
                $entries[] = ['item' => $item, 'location_id' => $locationId, 'quantity' => $quantity];
            }
        }

        foreach (array_chunk($entries, ShopifyClient::MAX_QUANTITIES_PER_CALL) as $chunk) {
            $this->pushBatch($chunk);
        }

        $failedSkus = ShopifyInventoryItem::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('last_error')
            ->where('tracked', true)
            ->get(['sku', 'last_error']);

        $onlyShopify = array_values(array_diff(array_keys($shopify), array_keys($local)));
        $onlyInventoros = array_values(array_diff(array_keys($local), array_keys($shopify)));

        $summary = [
            'shopify_skus' => count($shopify),
            'inventoros_skus' => count($local),
            'matched' => $matched,
            'pushed' => $matched - count($untracked) - $failedSkus->count(),
            'failed' => $failedSkus->count(),
            'untracked' => count($untracked),
            'untracked_sample' => array_slice($untracked, 0, self::SAMPLE_SIZE),
            'only_in_shopify' => count($onlyShopify),
            'only_in_shopify_sample' => array_slice($onlyShopify, 0, self::SAMPLE_SIZE),
            'only_in_inventoros' => count($onlyInventoros),
            'only_in_inventoros_sample' => array_slice($onlyInventoros, 0, self::SAMPLE_SIZE),
            'failed_sample' => $failedSkus->take(self::SAMPLE_SIZE)
                ->map(fn ($i) => ['sku' => $i->sku, 'error' => $i->last_error])
                ->values()
                ->all(),
        ];

        $this->connection->forceFill([
            'last_full_sync_at' => now(),
            'last_full_sync_summary' => $summary,
            'last_error' => null,
        ])->save();

        return $summary;
    }

    /**
     * Upsert Shopify variants into the SKU cache and, by default, forget the
     * SKUs that are no longer in Shopify.
     *
     * @param  array<string, array{sku: string, variant_id: string, inventory_item_id: string, tracked: bool}>  $variants
     */
    public function cache(array $variants, bool $forgetOthers = true): void
    {
        $orgId = $this->connection->organization_id;
        $now = now()->startOfSecond();

        foreach (array_chunk($variants, 500, true) as $chunk) {
            ShopifyInventoryItem::query()->upsert(array_map(fn (array $v) => [
                'organization_id' => $orgId,
                'sku' => $v['sku'],
                'inventory_item_id' => $v['inventory_item_id'],
                'variant_id' => $v['variant_id'],
                'tracked' => $v['tracked'],
                'created_at' => $now,
                'updated_at' => $now,
            ], array_values($chunk)), ['organization_id', 'sku'], ['inventory_item_id', 'variant_id', 'tracked', 'updated_at']);
        }

        if ($forgetOthers) {
            ShopifyInventoryItem::query()
                ->where('organization_id', $orgId)
                ->where('updated_at', '<', $now)
                ->delete();
        }
    }

    /**
     * Set the quantities of a batch of entries. Entries Shopify rejects are
     * recorded on their item; when an item is not yet stocked at a location
     * it is activated there and the batch is retried once.
     *
     * @param  array<int, array{item: ShopifyInventoryItem, location_id: string, quantity: int}>  $batch
     * @return int how many entries failed
     */
    private function pushBatch(array $batch, bool $retried = false): int
    {
        $batch = array_values($batch);
        if ($batch === []) {
            return 0;
        }

        $errors = $this->client->setAvailable(array_map(fn (array $e) => [
            'inventory_item_id' => $e['item']->inventory_item_id,
            'location_id' => $e['location_id'],
            'quantity' => $e['quantity'],
        ], $batch));

        if ($errors === []) {
            $this->recordPushed($batch);

            return 0;
        }

        // Shopify applied nothing. Set aside the entries it named, fixing what
        // can be fixed, and send the rest again.
        $failed = 0;
        $retry = [];
        $named = [];
        foreach ($errors as $error) {
            if ($error['index'] === null || ! isset($batch[$error['index']])) {
                continue;
            }
            $named[$error['index']] = true;
            $entry = $batch[$error['index']];

            if ($error['code'] === 'ITEM_NOT_STOCKED_AT_LOCATION' && ! $retried) {
                $activation = $this->client->activateAtLocation($entry['item']->inventory_item_id, $entry['location_id']);
                if ($activation === []) {
                    $retry[] = $entry;

                    continue;
                }
                $error['message'] .= ' Activating it at the location failed: '.implode('; ', $activation);
            }

            $entry['item']->forceFill(['last_error' => $error['message']])->save();
            $failed++;
        }

        if ($named === []) {
            // Errors we cannot pin on one entry fail the whole batch.
            $message = implode('; ', array_column($errors, 'message'));
            foreach ($batch as $entry) {
                $entry['item']->forceFill(['last_error' => $message])->save();
            }

            return count($batch);
        }

        foreach ($batch as $index => $entry) {
            if (! isset($named[$index])) {
                $retry[] = $entry;
            }
        }

        return $failed + ($retried ? count($retry) : $this->pushBatch($retry, true));
    }

    /**
     * @param  array<int, array{item: ShopifyInventoryItem, location_id: string, quantity: int}>  $batch
     */
    private function recordPushed(array $batch): void
    {
        $now = now();
        $byItem = [];
        foreach ($batch as $entry) {
            $byItem[$entry['item']->id]['item'] = $entry['item'];
            $byItem[$entry['item']->id]['levels'][$entry['location_id']] = $entry['quantity'];
        }

        foreach ($byItem as ['item' => $item, 'levels' => $levels]) {
            $pushed = array_merge((array) ($item->last_pushed ?? []), $levels);
            $item->forceFill([
                'last_pushed' => $pushed,
                'last_pushed_quantity' => array_sum($pushed),
                'last_pushed_at' => $now,
                'last_error' => null,
            ])->save();
        }
    }

    /**
     * The cached Shopify item for a SKU, looking it up on a cache miss.
     */
    private function item(string $sku): ?ShopifyInventoryItem
    {
        $orgId = $this->connection->organization_id;
        $item = ShopifyInventoryItem::query()->where('organization_id', $orgId)->where('sku', $sku)->first();

        if ($item !== null) {
            return $item->tracked ? $item : null;
        }

        $found = $this->client->variantsBySku([$sku])[$sku] ?? null;
        if ($found === null) {
            return null;
        }

        $item = ShopifyInventoryItem::query()->updateOrCreate(
            ['organization_id' => $orgId, 'sku' => $sku],
            ['inventory_item_id' => $found['inventory_item_id'], 'variant_id' => $found['variant_id'], 'tracked' => $found['tracked']],
        );

        return $item->tracked ? $item : null;
    }

    /**
     * Per-location levels of every SKU the organization sells: variant SKUs,
     * and the SKUs of products sold without variants.
     *
     * @return array<string, array<string, int>> sku => [location id => quantity]
     */
    private function localLevels(): array
    {
        $orgId = $this->connection->organization_id;
        $levels = [];

        ProductVariant::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->whereNotNull('sku')
            ->with(['product' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
            ->chunkById(500, function (Collection $variants) use (&$levels) {
                foreach ($variants as $variant) {
                    $sku = trim((string) $variant->sku);
                    if ($sku !== '' && $variant->product !== null && ! isset($levels[$sku])) {
                        $levels[$sku] = $this->levels->for($variant->product, $variant);
                    }
                }
            });

        Product::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $orgId)
            ->where('has_variants', false)
            ->whereNotNull('sku')
            ->chunkById(500, function (Collection $products) use (&$levels) {
                foreach ($products as $product) {
                    $sku = trim((string) $product->sku);
                    if ($sku !== '' && ! isset($levels[$sku])) {
                        $levels[$sku] = $this->levels->for($product);
                    }
                }
            });

        return $levels;
    }
}
