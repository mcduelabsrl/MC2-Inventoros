<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Shopify fulfilment or refund and the Inventoros shipment or return it
 * became, so reconciling an order applies each one once.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $shopify_order_id
 * @property string $kind
 * @property string $shopify_id
 * @property int|null $local_id
 * @property string|null $state
 */
class ShopifyLink extends Model
{
    public const FULFILLMENT = 'fulfillment';

    public const REFUND = 'refund';

    protected $table = 'shopify_links';

    protected $fillable = ['organization_id', 'shopify_order_id', 'kind', 'shopify_id', 'local_id', 'state'];
}
