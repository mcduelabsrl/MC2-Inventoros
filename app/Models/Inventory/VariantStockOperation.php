<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One business operation on variant stock (an adjustment, a transfer, an
 * order commitment, a return...). Its movements are the ledger lines.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $type
 * @property string $source local|external|import
 * @property string|null $idempotency_key
 * @property int|null $user_id
 * @property int|null $order_id
 * @property int|null $transfer_id
 * @property int|null $return_order_id
 * @property string $mode delta|absolute
 * @property string|null $reason
 * @property string|null $notes
 * @property array|null $meta
 */
class VariantStockOperation extends Model
{
    public const SOURCE_LOCAL = 'local';

    public const SOURCE_EXTERNAL = 'external';

    public const SOURCE_IMPORT = 'import';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_COUNT = 'count';

    public const TYPE_OPENING = 'opening_balance';

    public const TYPE_ORDER_COMMIT = 'order_commit';

    public const TYPE_ORDER_RELEASE = 'order_release';

    public const TYPE_ORDER_FULFIL = 'order_fulfil';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_TRANSFER_PREPARE = 'transfer_prepare';

    public const TYPE_TRANSFER_SHIP = 'transfer_ship';

    public const TYPE_TRANSFER_RECEIVE = 'transfer_receive';

    public const TYPE_TRANSFER_CANCEL = 'transfer_cancel';

    public const TYPE_RETURN_RECEIVED = 'return_received';

    public const TYPE_INSPECTION = 'inspection';

    public const TYPE_OWNER_CHANGE = 'owner_change';

    public const TYPE_STATE_CHANGE = 'state_change';

    public const TYPE_CHANNEL_SYNC = 'channel_sync';

    public const TYPE_INCOMING = 'incoming';

    protected $fillable = [
        'organization_id', 'type', 'source', 'idempotency_key', 'user_id', 'order_id',
        'transfer_id', 'return_order_id', 'mode', 'reason', 'notes', 'meta',
    ];

    protected $casts = ['meta' => 'array'];

    /**
     * @return HasMany<VariantStockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(VariantStockMovement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<VariantStockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(VariantStockTransfer::class, 'transfer_id');
    }
}
