<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductLocationStock;
use App\Models\Inventory\ProductVariant;
use App\Models\Scopes\OrganizationScope;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;

/**
 * What Inventoros has on hand for each mapped Shopify location.
 *
 * A location fed by one warehouse gets the product's on-hand in that
 * warehouse's bins; a location fed by every warehouse (the single-location
 * setup) gets the total. Variants have no per-warehouse breakdown, so their
 * whole stock goes to the primary (first) location only, and the other
 * locations are left alone.
 */
class StockLevels
{
    /** @var array<int, int|null> location id => warehouse id, cached per instance */
    private array $locationWarehouses = [];

    public function __construct(private readonly ShopifyConnection $connection) {}

    /**
     * @return array<string, int> Shopify location id => quantity (never negative)
     */
    public function for(Product $product, ?ProductVariant $variant = null): array
    {
        $map = $this->connection->locationMap();
        $levels = [];

        if ($variant !== null) {
            if ($map !== []) {
                $levels[$map[0]['location_id']] = max(0, (int) $variant->stock);
            }

            return $levels;
        }

        foreach ($map as $entry) {
            $quantity = $entry['warehouse_id'] === null
                ? (int) $product->stock
                : $this->inWarehouse($product, $entry['warehouse_id']);
            $levels[$entry['location_id']] = max(0, $quantity);
        }

        return $levels;
    }

    /**
     * A product's on-hand in one warehouse: the sum of its bins there, or,
     * for a product never binned, its whole stock when its primary location
     * is in that warehouse (the same rule ProductLocationStockService uses).
     */
    public function inWarehouse(Product $product, int $warehouseId): int
    {
        $binned = ProductLocationStock::withoutGlobalScope(OrganizationScope::class)->where('product_id', $product->id)->exists();

        if (! $binned) {
            return $product->location_id !== null && $this->warehouseOf((int) $product->location_id) === $warehouseId
                ? (int) $product->stock
                : 0;
        }

        return (int) ProductLocationStock::withoutGlobalScope(OrganizationScope::class)
            ->join('product_locations', 'product_locations.id', '=', 'product_location_stocks.location_id')
            ->where('product_location_stocks.product_id', $product->id)
            ->where('product_locations.warehouse_id', $warehouseId)
            ->sum('product_location_stocks.quantity');
    }

    private function warehouseOf(int $locationId): ?int
    {
        if (! array_key_exists($locationId, $this->locationWarehouses)) {
            $warehouse = ProductLocation::withoutGlobalScope(OrganizationScope::class)->whereKey($locationId)->value('warehouse_id');
            $this->locationWarehouses[$locationId] = $warehouse === null ? null : (int) $warehouse;
        }

        return $this->locationWarehouses[$locationId];
    }
}
