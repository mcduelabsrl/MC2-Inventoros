<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many units of one variant one owner has in one warehouse, by state.
 *
 * on_hand is everything physically there. committed (set aside for orders),
 * quality_control (returns waiting for inspection), damaged and reserved
 * (other unsellable units) are parts of on_hand. available is what is left
 * to sell. incoming is on its way and not part of on_hand.
 *
 * Quantities may go negative when a sales channel reports more than was
 * recorded; they are kept as they are and shown as anomalies.
 *
 * Only VariantStockService writes these rows.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $product_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int|null $stock_owner_id
 * @property int $owner_key
 * @property int $on_hand
 * @property int $committed
 * @property int $quality_control
 * @property int $damaged
 * @property int $reserved
 * @property int $incoming
 * @property-read int $available
 * @property-read int $unavailable
 */
class VariantStockLevel extends Model
{
    /** Buckets that are part of on_hand but cannot be sold. */
    public const UNAVAILABLE_BUCKETS = ['quality_control', 'damaged', 'reserved'];

    /** Every stored bucket. */
    public const BUCKETS = ['on_hand', 'committed', 'quality_control', 'damaged', 'reserved', 'incoming'];

    protected $fillable = [
        'organization_id', 'product_id', 'product_variant_id', 'warehouse_id',
        'stock_owner_id', 'owner_key',
        'on_hand', 'committed', 'quality_control', 'damaged', 'reserved', 'incoming',
    ];

    protected $casts = [
        'on_hand' => 'integer',
        'committed' => 'integer',
        'quality_control' => 'integer',
        'damaged' => 'integer',
        'reserved' => 'integer',
        'incoming' => 'integer',
        'owner_key' => 'integer',
    ];

    protected $appends = ['available', 'unavailable'];

    public function getAvailableAttribute(): int
    {
        return (int) $this->on_hand - (int) $this->committed - $this->getUnavailableAttribute();
    }

    public function getUnavailableAttribute(): int
    {
        return (int) $this->quality_control + (int) $this->damaged + (int) $this->reserved;
    }

    /**
     * SQL for available, for sums and filters.
     */
    public static function availableSql(string $table = 'variant_stock_levels'): string
    {
        return "({$table}.on_hand - {$table}.committed - {$table}.quality_control - {$table}.damaged - {$table}.reserved)";
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('variant_stock_levels.organization_id', $organizationId);
    }
}
