<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Order\Order;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An immediate move of variant units from one warehouse to another,
 * confirmed once by the operator. Ownership never changes in a transfer.
 *
 * channel_status tracks the sales channel side (e.g. Shopify):
 * not_applicable, pending, synced, error, conflict.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $reference
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property int|null $order_id
 * @property int|null $user_id
 * @property string $status
 * @property string $channel_status
 * @property string|null $channel_error
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class VariantStockTransfer extends Model
{
    public const CHANNEL_NOT_APPLICABLE = 'not_applicable';

    public const CHANNEL_PENDING = 'pending';

    public const CHANNEL_SYNCED = 'synced';

    public const CHANNEL_ERROR = 'error';

    public const CHANNEL_CONFLICT = 'conflict';

    protected $fillable = [
        'organization_id', 'reference', 'from_warehouse_id', 'to_warehouse_id', 'order_id',
        'user_id', 'status', 'channel_status', 'channel_error', 'notes', 'completed_at',
    ];

    protected $casts = ['completed_at' => 'datetime'];

    /**
     * @return HasMany<VariantStockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(VariantStockTransferLine::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function nextReference(int $organizationId): string
    {
        $last = static::where('organization_id', $organizationId)->max('id') ?? 0;

        do {
            $last++;
            $reference = 'TV'.str_pad((string) $last, 5, '0', STR_PAD_LEFT);
        } while (static::where('organization_id', $organizationId)->where('reference', $reference)->exists());

        return $reference;
    }
}
