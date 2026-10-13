<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Something about stock sync an operator has to look at and decide.
 *
 * Kinds:
 *  - count_conflict: an absolute count was refused because Shopify's figure
 *    moved since it was taken.
 *  - unexplained_difference: the two sides differ and neither recorded a
 *    change that explains it.
 *  - committed_mismatch: units committed to orders differ between the sides
 *    for longer than order processing takes.
 *  - operation_failed: Shopify refused an operation for another reason.
 *  - routing: an order's Shopify locations do not match where Inventoros
 *    committed its units.
 *  - ownership: owner split no longer matches the units on hand.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $kind
 * @property string|null $inventory_item_id
 * @property string|null $location_id
 * @property int|null $product_variant_id
 * @property int|null $warehouse_id
 * @property int|null $stock_operation_id
 * @property int|null $order_id
 * @property array<string, mixed>|null $details
 * @property string $message
 * @property string $status open|resolved
 * @property string|null $resolution
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 */
class ShopifyStockIssue extends Model
{
    protected $table = 'shopify_stock_issues';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'resolved_at' => 'datetime'];

    /**
     * Open an issue unless the same one is already open.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function raise(array $attributes): self
    {
        $match = array_intersect_key($attributes, array_flip([
            'organization_id', 'kind', 'inventory_item_id', 'location_id', 'stock_operation_id', 'order_id',
        ]));

        $issue = static::query()->where($match + ['status' => 'open'])->first();

        if ($issue !== null) {
            $issue->fill(['details' => $attributes['details'] ?? $issue->details, 'message' => $attributes['message'] ?? $issue->message])->save();

            return $issue;
        }

        return static::create($attributes + ['status' => 'open']);
    }
}
