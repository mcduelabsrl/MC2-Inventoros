<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One Shopify mutation Inventoros has to make (the outbox).
 *
 * The idempotency key is fixed when the row is created and sent with every
 * attempt, so Shopify applies the mutation once however often a retry or a
 * crash makes it go out again. Only `confirmed` means Shopify has it.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $kind adjust|move|set|fulfillment_order_move|transfer_edit|shipment_receive
 * @property string $status awaiting_approval|pending|sending|confirmed|failed|conflict|cancelled
 * @property string $idempotency_key
 * @property int|null $variant_stock_operation_id
 * @property int|null $transfer_id
 * @property int|null $order_id
 * @property int|null $depends_on_id
 * @property int $step
 * @property string $mode delta|absolute
 * @property array<string, mixed> $payload
 * @property array<string, int>|null $effect item|location|name => delta
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $adjustment_group_id
 * @property array<string, mixed>|null $response
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $confirmed_at
 */
class ShopifyStockOperation extends Model
{
    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const CONFIRMED = 'confirmed';

    public const FAILED = 'failed';

    public const CONFLICT = 'conflict';

    public const CANCELLED = 'cancelled';

    /** Statuses that still have to reach Shopify. */
    public const OPEN = [self::AWAITING_APPROVAL, self::PENDING, self::SENDING, self::FAILED, self::CONFLICT];

    protected $table = 'shopify_stock_operations';

    protected $guarded = ['id'];

    protected $casts = [
        'payload' => 'array',
        'effect' => 'array',
        'response' => 'array',
        'next_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $operation): void {
            $operation->idempotency_key ??= (string) Str::uuid();
        });
    }

    /**
     * Item|location pairs this operation touches.
     *
     * @return list<array{item: string, location: string}>
     */
    public function pairs(): array
    {
        $pairs = [];
        foreach (array_keys($this->effect ?? []) as $key) {
            [$item, $location] = explode('|', $key);
            $pairs[$item.'|'.$location] = ['item' => $item, 'location' => $location];
        }

        return array_values($pairs);
    }
}
