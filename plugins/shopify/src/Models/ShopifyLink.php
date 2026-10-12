<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A Shopify fulfilment, refund or payment and the Inventoros shipment,
 * return or payment it became, so reconciling an order applies each once.
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

    /** The money Shopify received for an order; state holds the amount recorded so far. */
    public const PAYMENT = 'payment';

    /** The money a Shopify refund gave back. */
    public const REFUND_PAYMENT = 'refund_payment';

    protected $table = 'shopify_links';

    protected $fillable = ['organization_id', 'shopify_order_id', 'kind', 'shopify_id', 'local_id', 'state'];
}
