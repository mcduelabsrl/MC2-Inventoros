<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use App\Enums\Permission;
use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustment;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\Organizations\ActiveOrganization;
use App\Services\ProductService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;

/**
 * Copies products between the Shopify catalogue and Inventoros, matched by
 * SKU, never touching a product that already exists on the other side:
 *
 *  - importFromShopify() creates an Inventoros product for every Shopify
 *    product none of whose SKUs Inventoros has: a simple product for a
 *    single-variant product, otherwise a product with options and variants.
 *    Optionally it books Shopify's available stock as opening stock.
 *  - publishToShopify() creates a Shopify product (draft by default) for
 *    every active Inventoros product none of whose SKUs Shopify has, with
 *    its options, variants, prices, barcodes and stock at each mapped
 *    location.
 */
class CatalogueSync
{
    private const SAMPLE_SIZE = 50;

    private readonly ShopifyClient $client;

    public function __construct(private readonly ShopifyConnection $connection, ?ShopifyClient $client = null)
    {
        $this->client = $client ?? new ShopifyClient($connection);
    }

    /**
     * @return array<string, mixed> summary, also stored on the connection
     */
    public function importFromShopify(bool $withStock): array
    {
        $member = $this->member(Permission::CREATE_PRODUCTS);
        $orgId = $this->connection->organization_id;
        $currency = Organization::currencyFor($orgId);
        $existing = $this->inventorosSkus();

        $summary = ['products' => 0, 'variants' => 0, 'with_stock' => $withStock, 'existing' => 0, 'existing_sample' => [], 'no_sku' => 0, 'no_sku_sample' => [], 'failed' => 0, 'failed_sample' => []];
        $cache = [];

        PushGate::while(function () use ($member, $orgId, $currency, $withStock, &$existing, &$summary, &$cache) {
            $locations = array_column($this->connection->locationMap(), 'location_id')
                ?: array_column((array) ($this->connection->locations ?? []), 'id');

            foreach ($this->client->products($withStock ? $locations : []) as $product) {
                $variants = array_values(array_filter(
                    (array) ($product['variants']['nodes'] ?? []),
                    fn ($v) => is_array($v) && trim((string) ($v['sku'] ?? '')) !== '',
                ));
                $title = (string) ($product['title'] ?? 'Untitled');

                if (! empty($product['_incomplete'])) {
                    $summary['failed']++;
                    self::sample($summary['failed_sample'], $title.': more than 100 variants, create it by hand');

                    continue;
                }

                if ($variants === []) {
                    $summary['no_sku']++;
                    self::sample($summary['no_sku_sample'], $title);

                    continue;
                }

                $skus = array_map(fn ($v) => trim((string) $v['sku']), $variants);
                if (array_intersect($skus, array_keys($existing)) !== [] || count(array_unique($skus)) !== count($skus)) {
                    $summary['existing']++;
                    self::sample($summary['existing_sample'], $title);

                    continue;
                }

                try {
                    $created = $this->organizations()->runAs($member, $orgId, Permission::CREATE_PRODUCTS, fn (User $actor) => DB::transaction(
                        fn () => $this->createInventorosProduct($product, $variants, $currency, $withStock, $actor),
                    ));
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    self::sample($summary['failed_sample'], $title.': '.$e->getMessage());
                    Log::warning('Shopify catalogue import: product skipped', ['title' => $title, 'error' => $e->getMessage()]);

                    continue;
                }

                $summary['products']++;
                $summary['variants'] += $created;
                foreach ($variants as $variant) {
                    $sku = trim((string) $variant['sku']);
                    $existing[$sku] = true;
                    $cache[$sku] = [
                        'sku' => $sku,
                        'variant_id' => (string) $variant['id'],
                        'inventory_item_id' => (string) ($variant['inventoryItem']['id'] ?? ''),
                        'tracked' => (bool) ($variant['inventoryItem']['tracked'] ?? true),
                    ];
                }
            }
        });

        (new InventorySync($this->connection, $this->client))->cache(array_filter($cache, fn ($v) => $v['inventory_item_id'] !== ''), forgetOthers: false);

        $summary['finished_at'] = now()->toIso8601String();
        $this->connection->forceFill(['catalogue_import_summary' => $summary])->save();

        return $summary;
    }

    /**
     * @return array<string, mixed> summary, also stored on the connection
     */
    public function publishToShopify(string $status = 'DRAFT'): array
    {
        $member = $this->member(Permission::VIEW_PRODUCTS);
        $orgId = $this->connection->organization_id;
        $status = strtoupper($status) === 'ACTIVE' ? 'ACTIVE' : 'DRAFT';

        // What Shopify already has, refreshed.
        $shopify = [];
        foreach ($this->client->allVariants() as $variant) {
            $shopify[$variant['sku']] ??= $variant;
        }
        $sync = new InventorySync($this->connection, $this->client);
        $sync->cache($shopify);

        $levels = new StockLevels($this->connection);
        $summary = ['products' => 0, 'variants' => 0, 'status' => $status, 'already' => 0, 'partial' => 0, 'partial_sample' => [], 'no_sku' => 0, 'no_sku_sample' => [], 'failed' => 0, 'failed_sample' => []];

        $this->organizations()->runAs($member, $orgId, Permission::VIEW_PRODUCTS, function () use ($orgId, $status, $levels, $sync, &$shopify, &$summary) {
            Product::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $orgId)
                ->where('is_active', true)
                ->with(['options', 'variants'])
                ->chunkById(100, function (Collection $products) use ($status, $levels, $sync, &$shopify, &$summary) {
                    foreach ($products as $product) {
                        $this->publishOne($product, $status, $levels, $sync, $shopify, $summary);
                    }
                });
        });

        $summary['finished_at'] = now()->toIso8601String();
        $this->connection->forceFill(['catalogue_publish_summary' => $summary])->save();

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $shopify  sku => variant, updated with what is created
     * @param  array<string, mixed>  $summary
     */
    private function publishOne(Product $product, string $status, StockLevels $levels, InventorySync $sync, array &$shopify, array &$summary): void
    {
        $variants = $product->has_variants ? $product->variants->values() : collect();
        $skus = $product->has_variants
            ? $variants->map(fn (ProductVariant $v) => trim((string) $v->sku))->all()
            : [trim((string) $product->sku)];

        if ($skus === [] || in_array('', $skus, true)) {
            $summary['no_sku']++;
            self::sample($summary['no_sku_sample'], $product->name);

            return;
        }

        $present = array_intersect($skus, array_keys($shopify));
        if (count($present) === count($skus)) {
            $summary['already']++;

            return;
        }
        if ($present !== []) {
            $summary['partial']++;
            self::sample($summary['partial_sample'], $product->name);

            return;
        }

        $quantities = fn (array $byLocation) => array_map(
            fn ($locationId, $quantity) => ['locationId' => $locationId, 'name' => 'available', 'quantity' => $quantity],
            array_keys($byLocation),
            $byLocation,
        );

        if ($product->has_variants) {
            $options = $product->options->sortBy('position')->values();
            $productOptions = $options->map(fn ($o) => [
                'name' => $o->name,
                'values' => array_map(fn ($value) => ['name' => (string) $value], array_values((array) $o->values)),
            ])->all();
            $variantInputs = $variants->map(fn (ProductVariant $v) => array_filter([
                'optionValues' => $options->map(fn ($o) => [
                    'optionName' => $o->name,
                    'name' => (string) (((array) $v->option_values)[$o->name] ?? ''),
                ])->all(),
                'sku' => trim((string) $v->sku),
                'price' => Money::of($v->price ?? $product->selling_price ?? $product->price ?? 0),
                'barcode' => $v->barcode ?: null,
                'inventoryItem' => ['tracked' => true],
                'inventoryQuantities' => $quantities($levels->for($product, $v)),
            ], fn ($value) => $value !== null))->all();
        } else {
            $productOptions = [['name' => 'Title', 'values' => [['name' => 'Default Title']]]];
            $variantInputs = [array_filter([
                'optionValues' => [['optionName' => 'Title', 'name' => 'Default Title']],
                'sku' => trim((string) $product->sku),
                'price' => Money::of($product->selling_price ?? $product->price ?? 0),
                'barcode' => $product->barcode ?: null,
                'inventoryItem' => ['tracked' => true],
                'inventoryQuantities' => $quantities($levels->for($product)),
            ], fn ($value) => $value !== null)];
        }

        try {
            $result = $this->client->createProduct([
                'title' => $product->name,
                'descriptionHtml' => $product->description ? nl2br(e($product->description)) : null,
                'status' => $status,
                'productOptions' => $productOptions,
                'variants' => $variantInputs,
            ]);
        } catch (ShopifyException $e) {
            if ($e->transient) {
                throw $e;
            }
            $result = ['product' => null, 'errors' => [$e->getMessage()]];
        }

        if ($result['product'] === null || $result['errors'] !== []) {
            $summary['failed']++;
            self::sample($summary['failed_sample'], $product->name.': '.implode('; ', $result['errors'] ?: ['no product returned']));

            return;
        }

        $created = [];
        foreach ((array) ($result['product']['variants']['nodes'] ?? []) as $node) {
            $sku = trim((string) ($node['sku'] ?? ''));
            if ($sku !== '' && ! empty($node['inventoryItem']['id'])) {
                $created[$sku] = ['sku' => $sku, 'variant_id' => (string) $node['id'], 'inventory_item_id' => (string) $node['inventoryItem']['id'], 'tracked' => (bool) ($node['inventoryItem']['tracked'] ?? true)];
            }
        }
        $sync->cache($created, forgetOthers: false);
        $shopify += $created;

        $summary['products']++;
        $summary['variants'] += count($variantInputs);
    }

    /**
     * @param  array<string, mixed>  $product  GraphQL product node
     * @param  array<int, array<string, mixed>>  $variants  its variants that have a SKU
     * @return int variants created (1 for a simple product)
     */
    private function createInventorosProduct(array $product, array $variants, string $currency, bool $withStock, User $actor): int
    {
        $orgId = $this->connection->organization_id;
        $options = array_values(array_filter(
            (array) ($product['options'] ?? []),
            fn ($o) => is_array($o) && ! (($o['name'] ?? '') === 'Title' && (array) ($o['values'] ?? []) === ['Default Title']),
        ));
        $simple = count($variants) === 1 && $options === [];
        $first = $variants[0];

        $data = [
            'organization_id' => $orgId,
            'type' => 'standard',
            'name' => mb_substr((string) ($product['title'] ?? 'Untitled'), 0, 255),
            'description' => self::plainText($product['descriptionHtml'] ?? null),
            'currency' => $currency,
            'price' => Money::of($first['price'] ?? 0),
            'purchase_price' => isset($first['inventoryItem']['unitCost']['amount']) ? Money::of($first['inventoryItem']['unitCost']['amount']) : null,
            'stock' => 0,
            'min_stock' => 0,
            'is_active' => ($product['status'] ?? 'ACTIVE') === 'ACTIVE',
            'tracking_type' => 'none',
            'notes' => 'Imported from Shopify ('.$this->connection->shop_domain.', product '.($product['legacyResourceId'] ?? ShopifyClient::legacyId($product['id'] ?? '')).').',
        ];

        if ($simple) {
            $data['sku'] = trim((string) $first['sku']);
            $data['barcode'] = ($first['barcode'] ?? null) ?: null;
            $created = app(ProductService::class)->create($data);
            if ($withStock) {
                $this->bookOpeningStock($created, null, $first, $actor);
            }

            return 1;
        }

        $data['sku'] = $this->parentSku($product);
        $data['has_variants'] = true;
        $data['price'] = Money::of(min(array_map(fn ($v) => (float) ($v['price'] ?? 0), $variants)));
        $data['options'] = array_map(fn ($o) => ['name' => (string) $o['name'], 'values' => array_values(array_map('strval', (array) $o['values']))], $options);
        $data['variants'] = array_map(fn ($v) => [
            'sku' => trim((string) $v['sku']),
            'barcode' => ($v['barcode'] ?? null) ?: null,
            'option_values' => collect((array) ($v['selectedOptions'] ?? []))->mapWithKeys(fn ($o) => [(string) $o['name'] => (string) $o['value']])->all(),
            'price' => Money::of($v['price'] ?? 0),
            'purchase_price' => isset($v['inventoryItem']['unitCost']['amount']) ? Money::of($v['inventoryItem']['unitCost']['amount']) : null,
            'stock' => 0,
        ], $variants);

        $created = app(ProductService::class)->create($data);

        if ($withStock) {
            $bySku = ProductVariant::withoutGlobalScope(OrganizationScope::class)->where('product_id', $created->id)->get()->keyBy('sku');
            foreach ($variants as $variant) {
                if (($local = $bySku->get(trim((string) $variant['sku']))) !== null) {
                    $this->bookOpeningStock($created, $local, $variant, $actor);
                }
            }
        }

        return count($variants);
    }

    /**
     * Book Shopify's available quantity as opening stock: per mapped
     * warehouse for a product, as one figure for a variant (no bins).
     *
     * @param  array<string, mixed>  $shopifyVariant
     */
    private function bookOpeningStock(Product $product, ?ProductVariant $variant, array $shopifyVariant, User $actor): void
    {
        $available = [];
        foreach ((array) ($shopifyVariant['inventoryItem']['inventoryLevels']['nodes'] ?? []) as $level) {
            $locationId = (string) ($level['location']['id'] ?? '');
            foreach ((array) ($level['quantities'] ?? []) as $q) {
                if (($q['name'] ?? '') === 'available') {
                    $available[$locationId] = max(0, (int) ($q['quantity'] ?? 0));
                }
            }
        }

        $map = $this->connection->locationMap();
        $mapped = $map === [] ? array_map(fn ($id) => ['location_id' => $id, 'warehouse_id' => null], array_keys($available)) : $map;

        if ($variant !== null) {
            $total = array_sum(array_map(fn ($e) => $available[$e['location_id']] ?? 0, $mapped));
            if ($total > 0) {
                StockAdjustment::adjustVariant($variant, $total, ProductService::OPENING_STOCK_TYPE, 'Opening stock from Shopify', actor: $actor);
            }

            return;
        }

        foreach ($mapped as $entry) {
            $quantity = $available[$entry['location_id']] ?? 0;
            if ($quantity <= 0) {
                continue;
            }
            $locationId = $entry['warehouse_id'] === null ? null : $this->binIn($entry['warehouse_id']);
            StockAdjustment::adjust($product->fresh(), $quantity, ProductService::OPENING_STOCK_TYPE, 'Opening stock from Shopify', actor: $actor, locationId: $locationId);
        }
    }

    /**
     * A bin in the warehouse to book stock into, or null for the product's primary bin.
     */
    private function binIn(int $warehouseId): ?int
    {
        $id = ProductLocation::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->connection->organization_id)
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * An unused SKU for the parent of a product sold by variant.
     *
     * @param  array<string, mixed>  $product
     */
    private function parentSku(array $product): string
    {
        $base = strtoupper(Str::limit((string) ($product['handle'] ?? ''), 200, '')) ?: 'SHOPIFY';
        $candidate = $base;
        $suffix = (string) ($product['legacyResourceId'] ?? ShopifyClient::legacyId($product['id'] ?? ''));

        $taken = fn (string $sku) => Product::withoutGlobalScope(OrganizationScope::class)
            ->withTrashed()
            ->where('organization_id', $this->connection->organization_id)
            ->where('sku', $sku)
            ->exists()
            || ProductVariant::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $this->connection->organization_id)
                ->where('sku', $sku)
                ->exists();

        if ($taken($candidate)) {
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * @return array<string, true> every SKU Inventoros uses (products and variants)
     */
    private function inventorosSkus(): array
    {
        $orgId = $this->connection->organization_id;
        $skus = Product::withoutGlobalScope(OrganizationScope::class)->withTrashed()->where('organization_id', $orgId)->whereNotNull('sku')->pluck('sku')
            ->merge(ProductVariant::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $orgId)->whereNotNull('sku')->pluck('sku'))
            ->map(fn ($sku) => trim((string) $sku))
            ->filter()
            ->all();

        return array_fill_keys($skus, true);
    }

    private function member(Permission $permission): User
    {
        $user = app(OrderImporter::class)->actingUser($this->connection);
        if ($user === null) {
            throw new ShopifyException('The user who connected Shopify is no longer a member of this organization. Save the Shopify settings again to take over.');
        }

        return $this->organizations()->authorize($user, $this->connection->organization_id, $permission);
    }

    private function organizations(): ActiveOrganization
    {
        return app(ActiveOrganization::class);
    }

    private static function plainText(mixed $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $text = html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h\d)\s*/?>#i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text) ?: null;
    }

    /**
     * @param  array<int, string>  $sample
     */
    private static function sample(array &$sample, string $value): void
    {
        if (count($sample) < self::SAMPLE_SIZE) {
            $sample[] = $value;
        }
    }
}
