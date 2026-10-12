<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use App\Models\Auth\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One organization's Shopify store.
 *
 * Not tenant-scoped by a global scope: the webhook endpoint looks connections
 * up by their token without a signed-in user, so every other read filters by
 * organization_id explicitly (see forOrganization()).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property string $shop_domain
 * @property string|null $shop_name
 * @property string $client_id
 * @property string $client_secret
 * @property string|null $access_token
 * @property Carbon|null $token_expires_at
 * @property string $api_version
 * @property string|null $location_id
 * @property array<int, array{id: string, name: string}>|null $locations
 * @property int|null $warehouse_id
 * @property string $webhook_token
 * @property bool $import_orders
 * @property bool $push_inventory
 * @property Carbon|null $webhooks_registered_at
 * @property Carbon|null $last_full_sync_at
 * @property array<string, mixed>|null $last_full_sync_summary
 * @property string|null $last_error
 */
class ShopifyConnection extends Model
{
    public const DEFAULT_API_VERSION = '2026-07';

    protected $table = 'shopify_connections';

    protected $fillable = [
        'organization_id',
        'user_id',
        'shop_domain',
        'shop_name',
        'client_id',
        'client_secret',
        'access_token',
        'token_expires_at',
        'api_version',
        'location_id',
        'locations',
        'warehouse_id',
        'webhook_token',
        'import_orders',
        'push_inventory',
        'webhooks_registered_at',
        'last_full_sync_at',
        'last_full_sync_summary',
        'last_error',
    ];

    protected $hidden = ['client_secret', 'access_token', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'locations' => 'array',
            'import_orders' => 'boolean',
            'push_inventory' => 'boolean',
            'webhooks_registered_at' => 'datetime',
            'last_full_sync_at' => 'datetime',
            'last_full_sync_summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $connection): void {
            $connection->webhook_token ??= Str::random(48);
            $connection->api_version ??= self::DEFAULT_API_VERSION;
        });
    }

    public static function forOrganization(int $organizationId): ?self
    {
        return static::query()->where('organization_id', $organizationId)->first();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function canPushInventory(): bool
    {
        return $this->push_inventory && filled($this->location_id);
    }

    public function locationName(): ?string
    {
        foreach ($this->locations ?? [] as $location) {
            if (($location['id'] ?? null) === $this->location_id) {
                return $location['name'] ?? null;
            }
        }

        return null;
    }
}
