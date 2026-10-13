<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A verified Shopify webhook delivery, stored before it is processed.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $webhook_id
 * @property string $topic
 * @property Carbon|null $triggered_at
 * @property string $payload
 * @property string $status received|processing|processed|deferred|failed|ignored
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
class ShopifyWebhookEvent extends Model
{
    public const RECEIVED = 'received';

    public const PROCESSING = 'processing';

    public const PROCESSED = 'processed';

    public const DEFERRED = 'deferred';

    public const FAILED = 'failed';

    public const IGNORED = 'ignored';

    protected $table = 'shopify_webhook_events';

    protected $fillable = [
        'organization_id', 'webhook_id', 'topic', 'triggered_at', 'payload', 'status', 'attempts', 'error', 'processed_at',
    ];

    protected $casts = ['triggered_at' => 'datetime', 'processed_at' => 'datetime'];

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = json_decode($this->payload, true);

        return is_array($data) ? $data : [];
    }

    public function isOrderTopic(): bool
    {
        return str_starts_with($this->topic, 'orders/');
    }
}
