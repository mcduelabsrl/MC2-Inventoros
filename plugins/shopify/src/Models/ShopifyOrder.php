<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Shopify order the plugin received and what became of it.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $shopify_order_id
 * @property string|null $shopify_order_name
 * @property int|null $order_id
 * @property string $status
 * @property string|null $message
 * @property string $payload
 * @property int $attempts
 */
class ShopifyOrder extends Model
{
    public const IMPORTED = 'imported';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    protected $table = 'shopify_orders';

    protected $fillable = [
        'organization_id',
        'shopify_order_id',
        'shopify_order_name',
        'order_id',
        'status',
        'message',
        'payload',
        'attempts',
    ];

    protected $hidden = ['payload'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function decodedPayload(): array
    {
        $decoded = json_decode($this->payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A failed or skipped order can be imported again (after fixing stock or
     * SKUs); imported and cancelled ones are final.
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, [self::FAILED, self::SKIPPED], true);
    }
}
