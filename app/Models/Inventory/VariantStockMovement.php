<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One bucket change of one stock level, written by VariantStockService.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $variant_stock_operation_id
 * @property int $variant_stock_level_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int|null $stock_owner_id
 * @property string $bucket
 * @property int $delta
 * @property int $before
 * @property int $after
 */
class VariantStockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'variant_stock_operation_id', 'variant_stock_level_id',
        'product_variant_id', 'warehouse_id', 'stock_owner_id', 'bucket', 'delta', 'before', 'after',
    ];

    protected $casts = ['delta' => 'integer', 'before' => 'integer', 'after' => 'integer'];

    /**
     * @return BelongsTo<VariantStockOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(VariantStockOperation::class, 'variant_stock_operation_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
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
}
