<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Models\Auth\Organization;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockOwner;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStockService;
use App\Services\OrderService;
use App\Support\VariantWarehouseStockSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * An organization with two warehouses (MC2 main, Saxone second), two stock
 * owners and a shoe sold by size, with per-warehouse variant stock on.
 */
trait BuildsTwoWarehouses
{
    protected Organization $org;

    protected User $admin;

    protected Warehouse $mc2;

    protected Warehouse $saxone;

    protected StockOwner $ownerMc2;

    protected StockOwner $ownerSaxone;

    protected Product $shoe;

    protected ProductVariant $size42;

    protected ProductVariant $size43;

    protected function setUpTwoWarehouses(): void
    {
        Mail::fake();
        Notification::fake();

        $this->org = Organization::create([
            'name' => 'Saxone', 'email' => 'shop@saxone.test', 'currency' => 'EUR', 'timezone' => 'Europe/Rome',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@saxone.test', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'admin',
        ]);

        $this->mc2 = Warehouse::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'name' => 'MC2 Lab Srl — Via Luccoli', 'code' => 'MC2',
            'is_default' => true, 'is_active' => true, 'priority' => 10,
        ]);
        $this->saxone = Warehouse::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'name' => 'Saxone — Via Maragliano', 'code' => 'SAX',
            'is_default' => false, 'is_active' => true, 'priority' => 5,
        ]);

        $this->ownerMc2 = StockOwner::create(['organization_id' => $this->org->id, 'name' => 'MC2', 'code' => 'MC2']);
        $this->ownerSaxone = StockOwner::create(['organization_id' => $this->org->id, 'name' => 'Saxone', 'code' => 'SAXONE']);

        $this->shoe = Product::create([
            'organization_id' => $this->org->id,
            'sku' => 'SUNDAY-BLACK', 'name' => 'Sunday Loafer Black', 'price' => 159.00, 'currency' => 'EUR',
            'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'has_variants' => true,
        ]);

        $this->size42 = $this->makeSize('42');
        $this->size43 = $this->makeSize('43');

        VariantWarehouseStockSettings::save($this->org, new VariantWarehouseStockSettings(
            enabled: true,
            defaultWarehouseId: $this->mc2->id,
            returnsWarehouseId: $this->mc2->id,
        ));

        $this->actingAs($this->admin);
    }

    protected function makeSize(string $size): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->shoe->id,
            'organization_id' => $this->org->id,
            'sku' => "SUNDAY-BLACK-{$size}", 'title' => $size, 'option_values' => ['Size' => $size],
            'price' => 159.00, 'stock' => 0, 'min_stock' => 0, 'is_active' => true, 'position' => 0,
        ]);
    }

    protected function stockService(): VariantStockService
    {
        return app(VariantStockService::class);
    }

    protected function putStock(ProductVariant $variant, Warehouse $warehouse, int $quantity, ?StockOwner $owner = null): void
    {
        $this->stockService()->adjust($variant, $warehouse->id, $owner?->id, $quantity, ['reason' => 'test stock']);
    }

    /**
     * @return array<string, int>
     */
    protected function levelAt(ProductVariant $variant, Warehouse $warehouse): array
    {
        return $this->stockService()->totalsByWarehouse($variant)[$warehouse->id]
            ?? ['on_hand' => 0, 'committed' => 0, 'quality_control' => 0, 'damaged' => 0, 'reserved' => 0, 'incoming' => 0, 'available' => 0, 'unavailable' => 0];
    }

    protected function placeOrder(ProductVariant $variant, int $quantity, array $extra = []): Order
    {
        return app(OrderService::class)->create([
            'customer_name' => 'Cliente',
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_price' => 159.00,
            ] + ($extra['item'] ?? [])],
        ] + ($extra['order'] ?? []), $this->admin, $extra['source'] ?? 'manual', $extra['adjust_stock'] ?? true);
    }
}
