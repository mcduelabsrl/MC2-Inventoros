<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Represents an individual item line in a stock audit.
 *
 * @property int $id
 * @property int $stock_audit_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int|null $location_id
 * @property int|null $warehouse_id
 * @property int $system_quantity
 * @property int|null $counted_quantity
 * @property int $discrepancy
 * @property string $status
 * @property int|null $counted_by
 * @property Carbon|null $counted_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockAudit $stockAudit
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read ProductLocation|null $location
 * @property-read User|null $countedByUser
 */
class StockAuditItem extends Model
{
    protected $fillable = [
        'stock_audit_id',
        'product_id',
        'product_variant_id',
        'location_id',
        'warehouse_id',
        'system_quantity',
        'counted_quantity',
        'discrepancy',
        'status',
        'counted_by',
        'counted_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'system_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'discrepancy' => 'integer',
            'counted_at' => 'datetime',
        ];
    }

    /**
     * Get the stock audit that owns this item.
     *
     * @return BelongsTo<StockAudit, $this>
     */
    public function stockAudit(): BelongsTo
    {
        return $this->belongsTo(StockAudit::class);
    }

    /**
     * Get the product for this audit item.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the variant for this audit item.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Get the location for this audit item.
     *
     * @return BelongsTo<ProductLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class, 'location_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Get the user who counted this item.
     *
     * @return BelongsTo<User, $this>
     */
    public function countedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
