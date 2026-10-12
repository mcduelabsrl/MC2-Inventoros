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
 * @property array<int, array{location_id: string, warehouse_id: int|null}>|null $location_map
 * @property Carbon|null $orders_since
 * @property Carbon|null $orders_synced_at
 * @property array<string, mixed>|null $orders_sync_summary
 * @property Carbon|null $webhooks_checked_at
 * @property array<string, mixed>|null $catalogue_import_summary
 * @property array<string, mixed>|null $catalogue_publish_summary
 * @property array<int, string>|null $missing_scopes
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
        'location_map',
        'orders_since',
        'orders_synced_at',
        'orders_sync_summary',
        'webhooks_checked_at',
        'catalogue_import_summary',
        'catalogue_publish_summary',
        'missing_scopes',
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
            'location_map' => 'array',
            'orders_since' => 'datetime',
            'orders_synced_at' => 'datetime',
            'orders_sync_summary' => 'array',
            'webhooks_checked_at' => 'datetime',
            'catalogue_import_summary' => 'array',
            'catalogue_publish_summary' => 'array',
            'missing_scopes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $connection): void {
            $connection->webhook_token ??= Str::random(48);
            $connection->api_version ??= self::DEFAULT_API_VERSION;
            $connection->orders_since ??= now();
        });

        // location_id mirrors the first mapped location, for older readers.
        static::saving(function (self $connection): void {
            if ($connection->isDirty('location_map')) {
                $connection->location_id = $connection->locationMap()[0]['location_id'] ?? null;
            }
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
        return $this->push_inventory && $this->locationMap() !== [];
    }

    /**
     * The Shopify locations Inventoros controls, each fed by one Inventoros
     * warehouse, or by the total stock of every warehouse (warehouse_id null,
     * allowed only when a single location is mapped). The first entry is the
     * primary location: variant stock, which has no per-warehouse breakdown,
     * goes there.
     *
     * @return array<int, array{location_id: string, warehouse_id: int|null}>
     */
    public function locationMap(): array
    {
        $map = [];
        foreach ((array) ($this->location_map ?? []) as $entry) {
            if (is_array($entry) && filled($entry['location_id'] ?? null)) {
                $map[] = [
                    'location_id' => (string) $entry['location_id'],
                    'warehouse_id' => isset($entry['warehouse_id']) && $entry['warehouse_id'] !== '' ? (int) $entry['warehouse_id'] : null,
                ];
            }
        }

        return $map;
    }

    /**
     * The Inventoros warehouse mapped to a Shopify location (gid or numeric id).
     */
    public function warehouseForLocation(string|int|null $locationId): ?int
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }

        $locationId = (string) $locationId;
        foreach ($this->locationMap() as $entry) {
            if ($entry['location_id'] === $locationId || str_ends_with($entry['location_id'], '/'.$locationId)) {
                return $entry['warehouse_id'];
            }
        }

        return null;
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
