<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockOwner;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the units of a variant order line come from: warehouse and owner.
 *
 * open = quantity - fulfilled - released: units still committed.
 * The owner is kept after fulfilment so a return goes back to the same
 * owner; a NULL owner stays "to be verified".
 *
 * @property int $id
 * @property int $organization_id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int|null $stock_owner_id
 * @property int $quantity
 * @property int $fulfilled_quantity
 * @property int $released_quantity
 * @property int $returned_quantity
 * @property int $shortfall_quantity
 * @property-read int $open_quantity
 */
class OrderItemAllocation extends Model
{
    protected $fillable = [
        'organization_id', 'order_id', 'order_item_id', 'product_variant_id', 'warehouse_id',
        'stock_owner_id', 'quantity', 'fulfilled_quantity', 'released_quantity',
        'returned_quantity', 'shortfall_quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'fulfilled_quantity' => 'integer',
        'released_quantity' => 'integer',
        'returned_quantity' => 'integer',
        'shortfall_quantity' => 'integer',
    ];

    public function getOpenQuantityAttribute(): int
    {
        return max(0, (int) $this->quantity - (int) $this->fulfilled_quantity - (int) $this->released_quantity);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<StockOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(StockOwner::class, 'stock_owner_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
