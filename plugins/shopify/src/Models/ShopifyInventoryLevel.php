<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Shopify inventory item at one mapped location: the quantities Shopify
 * reported last (s_*), and both sides' figures at the last moment they were
 * known to agree (agreed_s: Shopify's, agreed_l: what Inventoros expected
 * Shopify to show). What either side changed since that agreement is what
 * gets carried to the other.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $inventory_item_id
 * @property string $location_id
 * @property int|null $product_variant_id
 * @property int|null $warehouse_id
 * @property int $s_available
 * @property int $s_on_hand
 * @property int $s_committed
 * @property int $s_reserved
 * @property int $s_damaged
 * @property int $s_quality_control
 * @property int $s_safety_stock
 * @property int $s_incoming
 * @property array<string, int>|null $agreed_s
 * @property array<string, int>|null $agreed_l
 * @property Carbon|null $shopify_updated_at
 * @property Carbon|null $read_at
 * @property string $sync_status synced|pending|error|conflict|unmapped
 * @property string|null $status_detail
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $check_after
 * @property int $deferrals
 */
class ShopifyInventoryLevel extends Model
{
    /** Quantity names read from Shopify. */
    public const NAMES = ['available', 'on_hand', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'];

    /** Names compared between the two sides. */
    public const COMPARED = ['on_hand', 'committed', 'reserved', 'damaged', 'quality_control'];

    public const SYNCED = 'synced';

    public const PENDING = 'pending';

    public const ERROR = 'error';

    public const CONFLICT = 'conflict';

    public const UNMAPPED = 'unmapped';

    protected $table = 'shopify_inventory_levels';

    protected $guarded = ['id'];

    protected $casts = [
        'agreed_s' => 'array',
        'agreed_l' => 'array',
        'shopify_updated_at' => 'datetime',
        'read_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'check_after' => 'datetime',
    ];

    /**
     * @return array<string, int>
     */
    public function shopifyQuantities(): array
    {
        $out = [];
        foreach (self::NAMES as $name) {
            $out[$name] = (int) $this->{'s_'.$name};
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $quantities
     */
    public function fillShopify(array $quantities): void
    {
        foreach (self::NAMES as $name) {
            if (array_key_exists($name, $quantities)) {
                $this->{'s_'.$name} = (int) $quantities[$name];
            }
        }
    }
}
