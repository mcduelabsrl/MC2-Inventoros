<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Exceptions\InvalidStateException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Scopes\OrganizationScope;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Organizations\ActiveOrganization;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;

/**
 * Turns Shopify order webhooks (orders/create, orders/cancelled) into
 * Inventoros orders, through OrderService like every other surface, so stock,
 * bins, the ledger and hooks behave exactly as for an order typed in by hand.
 *
 * Line items are matched by SKU: first against variant SKUs, then against
 * products sold without variants. Lines with no match (gift cards, services,
 * products Inventoros does not stock) are left out and listed in the order's
 * notes. Each Shopify order is recorded in shopify_orders, so a webhook
 * delivered twice creates one order, and a failed import (not enough stock,
 * no matching SKU) can be retried from the settings page once fixed.
 */
class OrderImporter
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ActiveOrganization $organizations,
    ) {}

    /**
     * Import one Shopify order payload (the orders/create webhook body, or a
     * stored one on retry).
     *
     * @param  array<string, mixed>  $payload
     */
    public function import(ShopifyConnection $connection, array $payload): ShopifyOrder
    {
        $shopifyId = (string) ($payload['id'] ?? '');
        if ($shopifyId === '') {
            throw new \InvalidArgumentException('The Shopify order has no id.');
        }

        // Shopify may deliver the same order twice at once (and a retry from
        // the settings page can meet a late webhook): one import at a time.
        $lock = Cache::lock("shopify-order-{$connection->organization_id}-{$shopifyId}", 120);
        try {
            $lock->block(30);
        } catch (LockTimeoutException) {
            return $this->record($connection, $payload);
        }

        try {
            return $this->importLocked($connection, $payload, $shopifyId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importLocked(ShopifyConnection $connection, array $payload, string $shopifyId): ShopifyOrder
    {
        $record = $this->record($connection, $payload);

        if (! $record->isRetryable()) {
            return $record; // already imported or cancelled: a duplicate delivery
        }

        $record->forceFill(['payload' => json_encode($payload), 'attempts' => $record->attempts + 1]);

        // An order created before this record existed (or whose record was
        // lost) is linked, never created again.
        $existing = Order::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $connection->organization_id)
            ->where('source', 'shopify')
            ->where('external_id', $shopifyId)
            ->withTrashed()
            ->first();

        if ($existing?->trashed()) {
            return $this->finish($record, ShopifyOrder::SKIPPED, "Inventoros order {$existing->order_number} was created for it and then deleted.");
        }

        if ($existing !== null) {
            return $this->finish($record, ShopifyOrder::IMPORTED, null, $existing->id);
        }

        if (! empty($payload['cancelled_at'])) {
            return $this->finish($record, ShopifyOrder::SKIPPED, 'The order was already cancelled in Shopify.');
        }

        $user = $this->actingUser($connection);
        if ($user === null) {
            return $this->finish($record, ShopifyOrder::FAILED, 'The user who connected Shopify is no longer a member of this organization. Save the Shopify settings again to take over.');
        }

        try {
            return $this->organizations->runAs($user, $connection->organization_id, Permission::CREATE_ORDERS, function (User $member) use ($connection, $payload, $record) {
                [$items, $unmatched] = $this->items($connection->organization_id, $payload);

                if ($items === []) {
                    return $this->finish($record, ShopifyOrder::SKIPPED, $unmatched === []
                        ? 'The order has no line items to fulfil.'
                        : 'No line item matches an Inventoros SKU: '.implode(', ', $unmatched).'.');
                }

                $order = $this->orders->create($this->orderData($connection, $payload, $items, $unmatched), $member, 'shopify');

                return $this->finish($record, ShopifyOrder::IMPORTED, $unmatched === [] ? null : 'Imported without unmatched lines: '.implode(', ', $unmatched).'.', $order->id);
            });
        } catch (InsufficientStockException|InvalidOrderItemException|BusinessRuleException $e) {
            return $this->finish($record, ShopifyOrder::FAILED, $e->getMessage());
        } catch (ValidationException $e) {
            return $this->finish($record, ShopifyOrder::FAILED, implode(' ', $e->validator->errors()->all()));
        } catch (AuthorizationException) {
            return $this->finish($record, ShopifyOrder::FAILED, 'The user who connected Shopify may no longer create orders. Save the Shopify settings again as a user who can.');
        } catch (\Throwable $e) {
            Log::error('Shopify order import failed', ['organization_id' => $connection->organization_id, 'shopify_order_id' => $shopifyId, 'error' => $e->getMessage()]);

            return $this->finish($record, ShopifyOrder::FAILED, 'Unexpected error: '.$e->getMessage());
        }
    }

    /**
     * Cancel the Inventoros order behind a Shopify order (orders/cancelled),
     * giving its stock back. Orders already shipped are left alone.
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancel(ShopifyConnection $connection, array $payload): ShopifyOrder
    {
        $shopifyId = (string) ($payload['id'] ?? '');
        if ($shopifyId === '') {
            throw new \InvalidArgumentException('The Shopify order has no id.');
        }

        $lock = Cache::lock("shopify-order-{$connection->organization_id}-{$shopifyId}", 120);
        $lock->block(30);

        try {
            return $this->cancelLocked($connection, $payload);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function cancelLocked(ShopifyConnection $connection, array $payload): ShopifyOrder
    {
        $record = $this->record($connection, $payload);

        if ($record->wasRecentlyCreated) {
            // The cancellation overtook the order itself: record it as
            // cancelled so the late orders/create cannot import it.
            return $this->finish($record, ShopifyOrder::CANCELLED, 'Cancelled in Shopify before it was imported.');
        }

        if ($record->status === ShopifyOrder::CANCELLED) {
            return $record;
        }

        if ($record->order_id === null) {
            // Failed or skipped: make sure a retry cannot import it now.
            return $this->finish($record, ShopifyOrder::CANCELLED, 'Cancelled in Shopify before it was imported.');
        }

        $order = Order::withoutGlobalScope(OrganizationScope::class)->where('organization_id', $connection->organization_id)->find($record->order_id);
        if ($order === null) {
            return $this->finish($record, ShopifyOrder::CANCELLED, 'Cancelled in Shopify; the Inventoros order no longer exists.');
        }

        $user = $this->actingUser($connection);

        try {
            $this->orders->cancel($order, $user);
        } catch (InvalidStateException|\RuntimeException $e) {
            $record->forceFill(['message' => 'Cancelled in Shopify, but not in Inventoros: '.$e->getMessage()])->save();

            return $record;
        }

        return $this->finish($record, ShopifyOrder::CANCELLED, 'Cancelled in Shopify; stock was returned.', $order->id);
    }

    /**
     * The user orders are created as: whoever saved the connection, working
     * in the connection's organization, or null once they left it.
     */
    public function actingUser(ShopifyConnection $connection): ?User
    {
        return $connection->user_id === null
            ? null
            : $this->organizations->userIn($connection->user_id, $connection->organization_id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(ShopifyConnection $connection, array $payload): ShopifyOrder
    {
        $attributes = ['organization_id' => $connection->organization_id, 'shopify_order_id' => (string) $payload['id']];

        try {
            return ShopifyOrder::query()->firstOrCreate($attributes, [
                'shopify_order_name' => isset($payload['name']) ? (string) $payload['name'] : null,
                'status' => ShopifyOrder::FAILED,
                'message' => 'Import in progress.',
                'payload' => json_encode($payload),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two deliveries of the same order at once: the other one won.
            return ShopifyOrder::query()->where($attributes)->firstOrFail();
        }
    }

    private function finish(ShopifyOrder $record, string $status, ?string $message, ?int $orderId = null): ShopifyOrder
    {
        $record->forceFill([
            'status' => $status,
            'message' => $message,
            'order_id' => $orderId ?? $record->order_id,
        ])->save();

        return $record;
    }

    /**
     * Order lines for OrderService, and the labels of lines with no match.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function items(int $organizationId, array $payload): array
    {
        $items = [];
        $unmatched = [];

        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            // current_quantity drops lines removed by an order edit before the webhook went out.
            $quantity = (int) ($line['current_quantity'] ?? $line['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $sku = trim((string) ($line['sku'] ?? ''));
            $label = $sku !== '' ? $sku : (string) ($line['title'] ?? $line['name'] ?? 'line '.($line['id'] ?? '?'));
            $target = $sku === '' ? null : $this->findBySku($organizationId, $sku);

            if ($target === null) {
                $unmatched[] = $label;

                continue;
            }

            $unitPrice = Money::of(self::shopMoney($line, 'price'));
            $gross = Money::multiply($unitPrice, $quantity);

            $discount = '0.00';
            foreach ((array) ($line['discount_allocations'] ?? []) as $allocation) {
                $discount = Money::add($discount, self::shopMoney((array) $allocation, 'amount'));
            }
            // Never discount below zero (Shopify can allocate rounding cents oddly).
            $discount = Money::min($discount, $gross);

            $tax = '0.00';
            foreach ((array) ($line['tax_lines'] ?? []) as $taxLine) {
                $tax = Money::add($tax, self::shopMoney((array) $taxLine, 'price'));
            }

            $items[] = [
                'product_id' => $target['product_id'],
                'product_variant_id' => $target['product_variant_id'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax' => $tax,
                'discount_type' => Money::compare($discount, '0') > 0 ? 'fixed' : null,
                'discount_value' => Money::compare($discount, '0') > 0 ? $discount : null,
            ];
        }

        return [$items, $unmatched];
    }

    /**
     * @return array{product_id: int, product_variant_id: int|null}|null
     */
    private function findBySku(int $organizationId, string $sku): ?array
    {
        $variant = ProductVariant::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('sku', $sku)
            ->first(['id', 'product_id']);

        if ($variant !== null) {
            return ['product_id' => (int) $variant->product_id, 'product_variant_id' => (int) $variant->id];
        }

        $product = Product::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organizationId)
            ->where('sku', $sku)
            ->where('has_variants', false)
            ->first(['id']);

        return $product === null ? null : ['product_id' => (int) $product->id, 'product_variant_id' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $unmatched
     * @return array<string, mixed>
     */
    private function orderData(ShopifyConnection $connection, array $payload, array $items, array $unmatched): array
    {
        $shipping = '0.00';
        foreach ((array) ($payload['shipping_lines'] ?? []) as $line) {
            $line = (array) $line;
            $shipping = Money::add($shipping, self::shopMoney($line, array_key_exists('discounted_price', $line) ? 'discounted_price' : 'price'));
        }

        $name = (string) ($payload['name'] ?? '#'.$payload['id']);
        $notes = ["Shopify order {$name}."];
        if (filled($payload['note'] ?? null)) {
            $notes[] = 'Customer note: '.$payload['note'];
        }
        if ($unmatched !== []) {
            $notes[] = 'Not imported (no matching SKU): '.implode(', ', $unmatched).'.';
        }

        $paid = in_array($payload['financial_status'] ?? null, ['paid', 'partially_refunded'], true);

        return [
            'external_id' => (string) $payload['id'],
            'external_reference' => mb_substr($name, 0, 255),
            'customer_name' => mb_substr($this->customerName($payload), 0, 255),
            'customer_email' => $this->customerEmail($payload),
            'customer_address' => $this->address($payload['shipping_address'] ?? $payload['billing_address'] ?? null),
            'status' => $paid ? 'processing' : 'pending',
            'order_date' => $payload['processed_at'] ?? $payload['created_at'] ?? now(),
            // Shop money: the store's own currency, whatever the buyer paid in.
            'currency' => $payload['currency'] ?? null,
            'shipping' => $shipping,
            'warehouse_id' => $connection->warehouse_id,
            'notes' => implode("\n", $notes),
            'metadata' => ['shopify' => [
                'id' => (string) $payload['id'],
                'name' => $name,
                'shop' => $connection->shop_domain,
                'financial_status' => $payload['financial_status'] ?? null,
                'total_price' => $payload['total_price'] ?? null,
                'admin_url' => 'https://'.$connection->shop_domain.'/admin/orders/'.$payload['id'],
            ]],
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function customerName(array $payload): string
    {
        foreach ([$payload['shipping_address'] ?? null, $payload['billing_address'] ?? null, $payload['customer'] ?? null] as $source) {
            if (is_array($source)) {
                $name = trim(($source['first_name'] ?? '').' '.($source['last_name'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
                if (filled($source['company'] ?? null)) {
                    return (string) $source['company'];
                }
            }
        }

        return $this->customerEmail($payload) ?? 'Shopify customer';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function customerEmail(array $payload): ?string
    {
        $email = $payload['email'] ?? $payload['contact_email'] ?? ($payload['customer']['email'] ?? null);

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 255) : null;
    }

    private function address(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $cityLine = trim(implode(' ', array_filter([$address['zip'] ?? null, $address['city'] ?? null, $address['province_code'] ?? null])));
        $lines = array_filter([
            $address['company'] ?? null,
            $address['address1'] ?? null,
            $address['address2'] ?? null,
            $cityLine,
            $address['country'] ?? null,
            $address['phone'] ?? null,
        ], fn ($line) => is_string($line) && trim($line) !== '');

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * An amount in the shop's currency: the `{key}_set.shop_money.amount`
     * field when present, else the plain `{key}` field.
     *
     * @param  array<string, mixed>  $data
     */
    private static function shopMoney(array $data, string $key): string
    {
        $amount = $data[$key.'_set']['shop_money']['amount'] ?? $data[$key] ?? '0';

        return is_numeric($amount) ? (string) $amount : '0';
    }
}
