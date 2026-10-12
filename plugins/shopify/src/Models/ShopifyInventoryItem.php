<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The Shopify inventory item behind one SKU, and the last quantity pushed.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $sku
 * @property string $inventory_item_id
 * @property string|null $variant_id
 * @property bool $tracked
 * @property int|null $last_pushed_quantity
 * @property Carbon|null $last_pushed_at
 * @property string|null $last_error
 */
class ShopifyInventoryItem extends Model
{
    protected $table = 'shopify_inventory_items';

    protected $fillable = [
        'organization_id',
        'sku',
        'inventory_item_id',
        'variant_id',
        'tracked',
        'last_pushed_quantity',
        'last_pushed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'tracked' => 'boolean',
            'last_pushed_quantity' => 'integer',
            'last_pushed_at' => 'datetime',
        ];
    }
}
