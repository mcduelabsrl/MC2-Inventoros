<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $variant_stock_transfer_id
 * @property int $product_variant_id
 * @property int|null $stock_owner_id
 * @property int $quantity
 * @property bool $committed
 * @property int|null $order_item_id
 */
class VariantStockTransferLine extends Model
{
    protected $fillable = [
        'variant_stock_transfer_id', 'product_variant_id', 'stock_owner_id', 'quantity', 'committed', 'order_item_id',
    ];

    protected $casts = ['quantity' => 'integer', 'committed' => 'boolean'];

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<StockOwner, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(StockOwner::class, 'stock_owner_id');
    }
}
