<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Order\OrderItemAllocation;
use App\Models\Inventory\VariantStockLevel;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyInventoryItem;
use Inventoros\Plugins\Shopify\ShopifyClient;

/**
 * Who is who between the two systems for one store: an Inventoros variant
 * and its Shopify inventory item (matched by SKU), a warehouse and its
 * Shopify location; and what Inventoros expects Shopify to show for a
 * variant in a warehouse.
 */
class ChannelMap
{
    /** @var array<int, string|null> */
    private array $itemByVariant = [];

    public function __construct(
        private readonly ShopifyConnection $connection,
        private readonly ?ShopifyClient $client = null,
    ) {}

    public function connection(): ShopifyConnection
    {
        return $this->connection;
    }

    /**
     * The Shopify inventory item gid of a variant, looking it up by SKU
     * when it is not cached yet. Null when Shopify has no tracked item.
     */
    public function itemForVariant(ProductVariant|int $variant): ?string
    {
        $variant = $variant instanceof ProductVariant ? $variant : ProductVariant::withTrashed()->find($variant);
        if ($variant === null) {
            return null;
        }

        if (array_key_exists($variant->id, $this->itemByVariant)) {
            return $this->itemByVariant[$variant->id];
        }

        $sku = trim((string) $variant->sku);
        if ($sku === '') {
            return $this->itemByVariant[$variant->id] = null;
        }

        $row = ShopifyInventoryItem::query()
            ->where('organization_id', $this->connection->organization_id)
            ->where('sku', $sku)
            ->first();

        if ($row === null && $this->client !== null) {
            $found = $this->client->variantsBySku([$sku])[$sku] ?? null;
            if ($found !== null) {
                $row = ShopifyInventoryItem::query()->updateOrCreate(
                    ['organization_id' => $this->connection->organization_id, 'sku' => $sku],
                    ['inventory_item_id' => $found['inventory_item_id'], 'variant_id' => $found['variant_id'], 'tracked' => $found['tracked']],
                );
            }
        }

        return $this->itemByVariant[$variant->id] = ($row !== null && $row->tracked)
            ? ShopifyClient::gid('InventoryItem', $row->inventory_item_id)
            : null;
    }

    /**
     * The Inventoros variant behind a Shopify inventory item, or null.
     */
    public function variantForItem(string $inventoryItemId): ?ProductVariant
    {
        $gid = ShopifyClient::gid('InventoryItem', $inventoryItemId);
        $legacy = ShopifyClient::legacyId($gid);

        $row = ShopifyInventoryItem::query()
            ->where('organization_id', $this->connection->organization_id)
            ->whereIn('inventory_item_id', [$gid, $legacy])
            ->first();

        if ($row === null) {
            return null;
        }

        return ProductVariant::query()
            ->where('organization_id', $this->connection->organization_id)
            ->where('sku', $row->sku)
            ->first();
    }

    public function locationForWarehouse(int $warehouseId): ?string
    {
        return $this->connection->locationForWarehouse($warehouseId);
    }

    public function warehouseForLocation(string $locationId): ?int
    {
        return $this->connection->warehouseForLocation($locationId);
    }

    /**
     * @return list<string> mapped location gids, main first
     */
    public function locations(): array
    {
        return array_values(array_map(fn ($e) => $e['location_id'], array_filter(
            $this->connection->locationMap(),
            fn ($e) => $e['warehouse_id'] !== null,
        )));
    }

    /**
     * What Shopify should show for a variant in a warehouse, by Inventoros:
     * on_hand as is; committed = units committed to Shopify orders (Shopify
     * commits those itself); reserved = units reserved here plus units
     * committed to orders taken outside Shopify (sent as reserved);
     * damaged and quality_control as is; incoming as is.
     *
     * @return array<string, int>
     */
    public function expected(int $variantId, int $warehouseId): array
    {
        $levels = VariantStockLevel::where('product_variant_id', $variantId)->where('warehouse_id', $warehouseId)->get();

        $byChannel = ['shopify' => 0, 'other' => 0];
        $allocations = OrderItemAllocation::query()
            ->where('order_item_allocations.product_variant_id', $variantId)
            ->where('order_item_allocations.warehouse_id', $warehouseId)
            ->join('orders', 'orders.id', '=', 'order_item_allocations.order_id')
            ->selectRaw("case when orders.source = 'shopify' then 'shopify' else 'other' end as channel")
            ->selectRaw('sum(order_item_allocations.quantity - order_item_allocations.fulfilled_quantity - order_item_allocations.released_quantity) as open')
            ->groupBy('channel')
            ->pluck('open', 'channel');

        foreach ($allocations as $channel => $open) {
            $byChannel[$channel] = (int) $open;
        }

        return [
            'on_hand' => (int) $levels->sum('on_hand'),
            'committed' => $byChannel['shopify'],
            'reserved' => (int) $levels->sum('reserved') + $byChannel['other'],
            'damaged' => (int) $levels->sum('damaged'),
            'quality_control' => (int) $levels->sum('quality_control'),
            'incoming' => (int) $levels->sum('incoming'),
        ];
    }

    public static function isShopifyOrder(?int $orderId): bool
    {
        return $orderId !== null && Order::withoutGlobalScopes()->whereKey($orderId)->value('source') === 'shopify';
    }
}
