<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

/**
 * One shape for a Shopify order, whichever way it arrived.
 *
 * Webhooks deliver orders in the REST shape (snake_case, numeric ids);
 * recovery and reconciliation read them from GraphQL. fromGraphql() turns a
 * GraphQL order node (ShopifyClient::ORDER_FIELDS) into the REST shape, so
 * the importer reads one format: id, name, email, note, currency,
 * financial_status, created_at, processed_at, updated_at, cancelled_at,
 * shipping_address, billing_address, line_items, shipping_lines, refunds and
 * fulfillments, with the REST field names. `_incomplete` marks an order
 * whose lines did not fit in one GraphQL page.
 */
final class OrderPayload
{
    /** Refund restock types after which the goods count as sold (or back via a return). */
    private const KEPT_ON_ORDER = ['no_restock', 'return', 'legacy_restock'];

    /** Refund restock types that bring goods back through a return. */
    public const RETURNED = ['return', 'legacy_restock'];

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public static function fromGraphql(array $node): array
    {
        $money = fn ($set) => (string) ($set['shopMoney']['amount'] ?? '0');

        // A list longer than one page was cut short: the order cannot be
        // compared line by line from this copy.
        $more = fn ($connection) => (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
        $incomplete = $more($node['lineItems'] ?? null);
        foreach ((array) ($node['refunds'] ?? []) as $refund) {
            $incomplete = $incomplete || $more($refund['refundLineItems'] ?? null);
        }
        foreach ((array) ($node['fulfillments'] ?? []) as $fulfillment) {
            $incomplete = $incomplete || $more($fulfillment['fulfillmentLineItems'] ?? null);
        }

        return [
            '_incomplete' => $incomplete,
            'id' => (string) ($node['legacyResourceId'] ?? ShopifyClient::legacyId($node['id'] ?? '')),
            'name' => $node['name'] ?? null,
            'email' => $node['email'] ?? null,
            'note' => $node['note'] ?? null,
            'currency' => $node['currencyCode'] ?? null,
            'financial_status' => isset($node['displayFinancialStatus']) ? strtolower((string) $node['displayFinancialStatus']) : null,
            // Received before refunds; refunds carry their own amounts.
            'total_received' => isset($node['totalReceivedSet']) ? $money($node['totalReceivedSet']) : null,
            'created_at' => $node['createdAt'] ?? null,
            'processed_at' => $node['processedAt'] ?? null,
            'updated_at' => $node['updatedAt'] ?? null,
            'cancelled_at' => $node['cancelledAt'] ?? null,
            'shipping_address' => self::address($node['shippingAddress'] ?? null),
            'billing_address' => self::address($node['billingAddress'] ?? null),
            'line_items' => array_map(fn (array $line) => [
                'id' => ShopifyClient::legacyId($line['id'] ?? ''),
                'sku' => $line['sku'] ?? null,
                'title' => $line['name'] ?? null,
                'quantity' => (int) ($line['quantity'] ?? 0),
                'current_quantity' => (int) ($line['currentQuantity'] ?? $line['quantity'] ?? 0),
                'price' => $money($line['originalUnitPriceSet'] ?? null),
                'discount_allocations' => array_map(fn ($a) => ['amount' => $money($a['allocatedAmountSet'] ?? null)], (array) ($line['discountAllocations'] ?? [])),
                'tax_lines' => array_map(fn ($t) => ['price' => $money($t['priceSet'] ?? null)], (array) ($line['taxLines'] ?? [])),
            ], self::nodes($node['lineItems'] ?? null)),
            'shipping_lines' => array_map(fn (array $line) => [
                'price' => $money($line['originalPriceSet'] ?? null),
                'discounted_price' => $money($line['discountedPriceSet'] ?? null),
            ], self::nodes($node['shippingLines'] ?? null)),
            'refunds' => array_map(fn (array $refund) => [
                'id' => ShopifyClient::legacyId($refund['id'] ?? ''),
                'created_at' => $refund['createdAt'] ?? null,
                'refunded_amount' => isset($refund['totalRefundedSet']) ? $money($refund['totalRefundedSet']) : null,
                'refund_line_items' => array_map(fn (array $item) => [
                    'line_item_id' => ShopifyClient::legacyId($item['lineItem']['id'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                    'restock_type' => strtolower((string) ($item['restockType'] ?? 'no_restock')),
                    'line_item' => ['sku' => $item['lineItem']['sku'] ?? null],
                ], self::nodes($refund['refundLineItems'] ?? null)),
            ], (array) ($node['refunds'] ?? [])),
            'fulfillments' => array_map(fn (array $f) => [
                'id' => ShopifyClient::legacyId($f['id'] ?? ''),
                'status' => strtolower((string) ($f['status'] ?? '')),
                'shipment_status' => isset($f['displayStatus']) ? strtolower((string) $f['displayStatus']) : null,
                'created_at' => $f['createdAt'] ?? null,
                'location_id' => isset($f['location']['id']) ? ShopifyClient::legacyId($f['location']['id']) : null,
                'tracking_company' => $f['trackingInfo'][0]['company'] ?? null,
                'tracking_number' => $f['trackingInfo'][0]['number'] ?? null,
                'tracking_url' => $f['trackingInfo'][0]['url'] ?? null,
                'line_items' => array_map(fn (array $item) => [
                    'id' => ShopifyClient::legacyId($item['lineItem']['id'] ?? ''),
                    'sku' => $item['lineItem']['sku'] ?? null,
                    'quantity' => (int) ($item['quantity'] ?? 0),
                ], self::nodes($f['fulfillmentLineItems'] ?? null)),
            ], (array) ($node['fulfillments'] ?? [])),
        ];
    }

    /**
     * How many units of each line the Inventoros order should hold: what the
     * customer still has after order edits and refunds that cancelled items
     * before shipping. Units refunded without restocking, and units returned
     * (which come back through an Inventoros return), stay on the order.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, int> line item id => quantity
     */
    public static function orderedQuantities(array $payload): array
    {
        $kept = [];
        foreach ((array) ($payload['refunds'] ?? []) as $refund) {
            foreach ((array) ($refund['refund_line_items'] ?? []) as $item) {
                if (in_array($item['restock_type'] ?? 'no_restock', self::KEPT_ON_ORDER, true)) {
                    $id = (string) ($item['line_item_id'] ?? '');
                    $kept[$id] = ($kept[$id] ?? 0) + (int) ($item['quantity'] ?? 0);
                }
            }
        }

        $quantities = [];
        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $id = (string) ($line['id'] ?? '');
            $current = array_key_exists('current_quantity', $line) ? (int) $line['current_quantity'] : (int) ($line['quantity'] ?? 0);
            $quantities[$id] = max(0, min((int) ($line['quantity'] ?? $current), $current + ($kept[$id] ?? 0)));
        }

        return $quantities;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function nodes(mixed $connection): array
    {
        if (! is_array($connection)) {
            return [];
        }

        return array_values(array_filter((array) ($connection['nodes'] ?? $connection), 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function address(mixed $address): ?array
    {
        if (! is_array($address)) {
            return null;
        }

        return [
            'first_name' => $address['firstName'] ?? null,
            'last_name' => $address['lastName'] ?? null,
            'company' => $address['company'] ?? null,
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $address['city'] ?? null,
            'zip' => $address['zip'] ?? null,
            'province_code' => $address['provinceCode'] ?? null,
            'country' => $address['country'] ?? null,
            'phone' => $address['phone'] ?? null,
        ];
    }
}
