<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify;

use App\Enums\OrderStatus;
use App\Enums\Permission;
use App\Enums\ShipmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderItemException;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Order\Order;
use App\Models\Scopes\OrganizationScope;
use App\Models\Shipping\Shipment;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Organizations\ActiveOrganization;
use App\Services\ReturnOrderService;
use App\Services\Shipping\ShipmentService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyLink;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;

/**
 * Keeps an Inventoros order in step with its Shopify order.
 *
 * reconcile() takes the full state of a Shopify order (a webhook body, or an
 * order read back from GraphQL, see OrderPayload) and applies whatever has
 * not been applied yet, always through the core services every other surface
 * uses, so stock, bins, the ledger and hooks behave as for changes made by
 * hand:
 *
 *  1. a new order is created (OrderService::create), its lines matched by
 *     SKU: variant SKUs first, then products sold without variants;
 *  2. a cancelled order is cancelled and its stock returned;
 *  3. order edits, and refunds that cancel items before shipping, change the
 *     order's lines (OrderService::replaceItems), while it has no shipments;
 *  4. each fulfilment becomes a shipment, marked shipped, and later delivered
 *     (ShipmentService); a cancelled fulfilment cancels its shipment;
 *  5. each refund that restocks returned goods becomes a return, received
 *     into stock (ReturnOrderService).
 *
 * Shopify orders are recorded in shopify_orders and their fulfilments and
 * refunds in shopify_links, so applying the same state twice changes nothing,
 * and a payload older than the state already applied is ignored.
 */
class OrderImporter
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ActiveOrganization $organizations,
        private readonly ShipmentService $shipments,
        private readonly ReturnOrderService $returns,
    ) {}

    /**
     * Apply a Shopify order's current state.
     *
     * @param  array<string, mixed>  $payload  REST-shaped order (see OrderPayload)
     * @param  bool  $importNew  False only updates orders already imported (recovery of old orders)
     */
    public function reconcile(ShopifyConnection $connection, array $payload, bool $importNew = true): ShopifyOrder
    {
        $shopifyId = (string) ($payload['id'] ?? '');
        if ($shopifyId === '') {
            throw new \InvalidArgumentException('The Shopify order has no id.');
        }

        // Shopify may deliver the same order twice at once (and a retry from
        // the settings page can meet a late webhook): one at a time.
        $lock = Cache::lock("shopify-order-{$connection->organization_id}-{$shopifyId}", 300);
        try {
            $lock->block(60);
        } catch (LockTimeoutException) {
            return $this->record($connection, $payload);
        }

        try {
            return $this->reconcileLocked($connection, $payload, $importNew);
        } finally {
            $lock->release();
        }
    }

    /**
     * Import (or re-apply) one Shopify order: the orders/create webhook body,
     * or a stored one on retry.
     *
     * @param  array<string, mixed>  $payload
     */
    public function import(ShopifyConnection $connection, array $payload): ShopifyOrder
    {
        return $this->reconcile($connection, $payload);
    }

    /**
     * Apply an orders/cancelled webhook.
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancel(ShopifyConnection $connection, array $payload): ShopifyOrder
    {
        $payload['cancelled_at'] ??= now()->toIso8601String();

        return $this->reconcile($connection, $payload);
    }

    /**
     * The user changes are made as: whoever saved the connection, working
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
    private function reconcileLocked(ShopifyConnection $connection, array $payload, bool $importNew): ShopifyOrder
    {
        $record = $this->record($connection, $payload);
        $updatedAt = self::time($payload['updated_at'] ?? null);

        if ($updatedAt !== null && $record->shopify_updated_at !== null && $updatedAt->lt($record->shopify_updated_at)) {
            return $record; // an older state than the one already applied
        }

        if ($record->status === ShopifyOrder::CANCELLED) {
            return $record;
        }

        if (! empty($payload['_incomplete'])) {
            // Too many lines to read back in one go: comparing a partial copy
            // would drop lines. Webhooks carry the whole order.
            if ($record->wasRecentlyCreated) {
                return $this->finish($record, ShopifyOrder::FAILED, 'This order has more lines than can be read back from Shopify at once, so it could not be imported automatically. Enter it by hand.');
            }

            return $record;
        }

        $notes = [];

        if ($record->order_id === null || $record->isRetryable()) {
            if (! $importNew && $record->wasRecentlyCreated) {
                $record->delete(); // history the recovery was not asked to import

                return $record;
            }

            if (! empty($payload['cancelled_at'])) {
                return $this->finish($record, ShopifyOrder::CANCELLED, 'Cancelled in Shopify before it was imported.', null, $updatedAt);
            }

            $record = $this->importOrder($connection, $record, $payload);
            if ($record->status !== ShopifyOrder::IMPORTED) {
                return $this->stamp($record, $updatedAt);
            }
            if (filled($record->message)) {
                $notes[] = $record->message;
            }
        }

        $order = Order::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $connection->organization_id)
            ->find($record->order_id);

        if ($order === null) {
            return $this->finish($record, $record->status, 'The Inventoros order no longer exists.', null, $updatedAt);
        }

        $user = $this->actingUser($connection);
        if ($user === null) {
            return $this->finish($record, $record->status, 'The user who connected Shopify is no longer a member of this organization. Save the Shopify settings again to take over.', null, $updatedAt);
        }

        try {
            return $this->organizations->runAs($user, $connection->organization_id, Permission::CREATE_ORDERS, function (User $member) use ($connection, $record, $order, $payload, $notes, $updatedAt) {
                if (! empty($payload['cancelled_at'])) {
                    return $this->cancelOrder($record, $order, $member, $updatedAt);
                }

                $notes[] = $this->applyEdits($connection, $order, $payload, $member);
                $order->refresh();
                array_push($notes, ...$this->applyFulfillments($connection, $order, $payload, $member));
                array_push($notes, ...$this->applyRefunds($connection, $order, $payload, $member));

                $notes = array_values(array_filter($notes));

                return $this->finish($record, ShopifyOrder::IMPORTED, $notes === [] ? null : implode(' ', $notes), $order->id, $updatedAt);
            });
        } catch (AuthorizationException) {
            return $this->finish($record, $record->status, 'The user who connected Shopify may no longer create orders. Save the Shopify settings again as a user who can.', null, $updatedAt);
        }
    }

    // ------------------------------------------------------------------
    // 1. Import
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importOrder(ShopifyConnection $connection, ShopifyOrder $record, array $payload): ShopifyOrder
    {
        $shopifyId = (string) $payload['id'];
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

                // An order placed before Inventoros took its stock figures
                // from Shopify (the connection, or a catalogue import with
                // stock) is already counted in them: it is recorded as
                // history, without taking stock or announcing a new sale.
                $historical = $this->isHistory($connection, $payload['created_at'] ?? null);
                $order = $this->orders->create($this->orderData($connection, $payload, $items, $unmatched), $member, 'shopify', adjustStock: ! $historical, announce: ! $historical);

                $notes = array_filter([
                    $historical ? 'Imported as history: placed before Inventoros took its stock from Shopify, so stock was not changed.' : null,
                    $unmatched === [] ? null : 'Imported without unmatched lines: '.implode(', ', $unmatched).'.',
                ]);

                return $this->finish($record, ShopifyOrder::IMPORTED, $notes === [] ? null : implode(' ', $notes), $order->id);
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

    // ------------------------------------------------------------------
    // 2. Cancellation
    // ------------------------------------------------------------------

    private function cancelOrder(ShopifyOrder $record, Order $order, User $member, ?Carbon $updatedAt): ShopifyOrder
    {
        try {
            $this->orders->cancel($order, $member);
        } catch (\RuntimeException $e) {
            return $this->finish($record, ShopifyOrder::IMPORTED, 'Cancelled in Shopify, but not in Inventoros: '.$e->getMessage(), null, $updatedAt);
        }

        return $this->finish($record, ShopifyOrder::CANCELLED, 'Cancelled in Shopify; stock was returned.', $order->id, $updatedAt);
    }

    // ------------------------------------------------------------------
    // 3. Edits
    // ------------------------------------------------------------------

    /**
     * Bring the order's lines in line with the quantities the customer still
     * has (order edits, items cancelled by a refund before shipping).
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyEdits(ShopifyConnection $connection, Order $order, array $payload, User $member): ?string
    {
        if ($order->status === OrderStatus::CANCELLED) {
            return null;
        }

        [$items] = $this->items($connection->organization_id, $payload);

        $wanted = [];
        foreach ($items as $item) {
            $key = self::targetKey($item['product_id'], $item['product_variant_id']);
            $wanted[$key] = ($wanted[$key] ?? 0) + $item['quantity'];
        }

        $held = [];
        foreach ($order->items()->get(['product_id', 'product_variant_id', 'quantity']) as $line) {
            $key = self::targetKey((int) $line->product_id, $line->product_variant_id === null ? null : (int) $line->product_variant_id);
            $held[$key] = ($held[$key] ?? 0) + (int) $line->quantity;
        }

        ksort($wanted);
        ksort($held);
        if ($wanted === $held) {
            return null;
        }

        if ($items === []) {
            try {
                $this->orders->cancel($order, $member);

                return 'Every line was removed in Shopify, so the order was cancelled.';
            } catch (\RuntimeException $e) {
                return 'Every line was removed in Shopify, but the order could not be cancelled: '.$e->getMessage();
            }
        }

        $shipping = $this->shipping($payload);

        try {
            DB::transaction(function () use ($order, $items, $member, $shipping) {
                $locked = Order::withoutGlobalScope(OrganizationScope::class)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                $this->orders->replaceItems($locked, $items, $member);
                $this->orders->recalculateTotals($locked, $locked->discount_type, $locked->discount_value, $this->orders->orderLevelTax($locked), $shipping);
                $locked->save();
            });
        } catch (BusinessRuleException|\RuntimeException $e) {
            return 'A change to the items in Shopify was not applied: '.$e->getMessage();
        } catch (ValidationException $e) {
            return 'A change to the items in Shopify was not applied: '.implode(' ', $e->validator->errors()->all());
        }

        return 'Items updated from Shopify.';
    }

    // ------------------------------------------------------------------
    // 4. Fulfilments
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string> notes
     */
    private function applyFulfillments(ShopifyConnection $connection, Order $order, array $payload, User $member): array
    {
        $notes = [];
        $lines = $this->lineTargets($connection->organization_id, $payload);

        foreach ((array) ($payload['fulfillments'] ?? []) as $fulfillment) {
            if (! is_array($fulfillment) || empty($fulfillment['id'])) {
                continue;
            }

            $status = strtolower((string) ($fulfillment['status'] ?? ''));
            $link = ShopifyLink::query()->where([
                'organization_id' => $connection->organization_id,
                'kind' => ShopifyLink::FULFILLMENT,
                'shopify_id' => (string) $fulfillment['id'],
            ])->first();
            $shipment = $link?->local_id === null ? null : Shipment::withoutGlobalScopes()->find($link->local_id);

            try {
                if (in_array($status, ['cancelled', 'error', 'failure'], true)) {
                    if ($shipment !== null && $shipment->status !== ShipmentStatus::CANCELLED) {
                        $this->shipments->cancel($shipment);
                        $link->update(['state' => 'cancelled']);
                    }

                    continue;
                }

                if ($status !== 'success') {
                    continue; // pending or open: not shipped yet
                }

                if ($link === null) {
                    $shipment = $this->createShipment($connection, $order, $fulfillment, $lines, $member);
                    $link = ShopifyLink::query()->create([
                        'organization_id' => $connection->organization_id,
                        'shopify_order_id' => (string) $payload['id'],
                        'kind' => ShopifyLink::FULFILLMENT,
                        'shopify_id' => (string) $fulfillment['id'],
                        'local_id' => $shipment?->id,
                        'state' => $shipment === null ? 'unmatched' : 'shipped',
                    ]);
                }

                if ($shipment !== null && ($fulfillment['shipment_status'] ?? null) === 'delivered' && $shipment->status !== ShipmentStatus::DELIVERED) {
                    $this->shipments->applyTrackingStatus($shipment, ShipmentStatus::DELIVERED);
                    $link->update(['state' => 'delivered']);
                }
            } catch (BusinessRuleException|AuthorizationException|\RuntimeException $e) {
                $notes[] = 'Shopify fulfilment '.$fulfillment['id'].' was not applied: '.$e->getMessage();
            }
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $fulfillment
     * @param  array<string, array{product_id: int, product_variant_id: int|null}>  $lines
     */
    private function createShipment(ShopifyConnection $connection, Order $order, array $fulfillment, array $lines, User $member): ?Shipment
    {
        $remaining = $this->shipments->remainingQuantities($order);
        $items = $this->allocate($order, (array) ($fulfillment['line_items'] ?? []), $lines, $remaining, 'id');

        if ($items === []) {
            return null; // nothing Inventoros stocks was in it
        }

        $shipment = $this->shipments->create($order, array_filter([
            'carrier' => 'manual',
            'carrier_name' => $fulfillment['tracking_company'] ?? null,
            'tracking_number' => $fulfillment['tracking_number'] ?? null,
            'tracking_url' => $fulfillment['tracking_url'] ?? null,
            'warehouse_id' => $connection->warehouseForLocation($fulfillment['location_id'] ?? null),
            // Shopify already told the customer.
            'notify_customer' => false,
            'items' => $items,
        ], fn ($v) => $v !== null), $member);

        return $this->shipments->markShipped($shipment, $member, self::time($fulfillment['created_at'] ?? null));
    }

    // ------------------------------------------------------------------
    // 5. Refunds
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string> notes
     */
    private function applyRefunds(ShopifyConnection $connection, Order $order, array $payload, User $member): array
    {
        $notes = [];
        $lines = $this->lineTargets($connection->organization_id, $payload);

        foreach ((array) ($payload['refunds'] ?? []) as $refund) {
            if (! is_array($refund) || empty($refund['id'])) {
                continue;
            }

            $attributes = [
                'organization_id' => $connection->organization_id,
                'kind' => ShopifyLink::REFUND,
                'shopify_id' => (string) $refund['id'],
            ];
            if (ShopifyLink::query()->where($attributes)->exists()) {
                continue;
            }

            if ($this->isHistory($connection, $refund['created_at'] ?? null)) {
                // Its restock is already in the stock Inventoros took from Shopify.
                ShopifyLink::query()->create($attributes + ['shopify_order_id' => (string) $payload['id'], 'state' => 'history']);

                continue;
            }

            $returned = array_values(array_filter(
                (array) ($refund['refund_line_items'] ?? []),
                fn ($item) => is_array($item) && in_array($item['restock_type'] ?? null, OrderPayload::RETURNED, true),
            ));

            $items = $this->allocate($order, $returned, $lines, $this->returnableQuantities($order), 'line_item_id');

            if ($items === []) {
                // Money only, or items cancelled before shipping (applied as an edit).
                ShopifyLink::query()->create($attributes + ['shopify_order_id' => (string) $payload['id'], 'state' => 'no_return']);

                continue;
            }

            try {
                $return = $this->returns->create($connection->organization_id, $member, [
                    'order_id' => $order->id,
                    'type' => 'return',
                    'reason' => 'Shopify refund '.$refund['id'],
                    'notes' => 'Restocked by a refund in Shopify.',
                    'items' => array_map(fn (array $i) => $i + ['condition' => 'used', 'restock' => true], $items),
                ]);
                $this->returns->approve($return, $member);
                $this->returns->receive($return, $member);
                $this->returns->complete($return, $member);

                ShopifyLink::query()->create($attributes + ['shopify_order_id' => (string) $payload['id'], 'local_id' => $return->id, 'state' => 'received']);
            } catch (BusinessRuleException|AuthorizationException|\RuntimeException $e) {
                $notes[] = 'Shopify refund '.$refund['id'].' was not restocked: '.$e->getMessage();
            } catch (ValidationException $e) {
                $notes[] = 'Shopify refund '.$refund['id'].' was not restocked: '.implode(' ', $e->validator->errors()->all());
            }
        }

        return $notes;
    }

    /**
     * Units of each order line that can still come back.
     *
     * @return array<int, int> order item id => quantity
     */
    private function returnableQuantities(Order $order): array
    {
        $returned = $this->returns->returnedQuantities($order);

        return $order->items()->get(['id', 'quantity'])
            ->mapWithKeys(fn ($item) => [$item->id => max(0, (int) $item->quantity - (int) $returned->get($item->id, 0))])
            ->all();
    }

    // ------------------------------------------------------------------
    // Line matching
    // ------------------------------------------------------------------

    /**
     * Spread Shopify line quantities over the order's lines for the same
     * product or variant, within what each line still allows.
     *
     * @param  array<int, mixed>  $shopifyLines  entries with a line item id under $idKey and a quantity
     * @param  array<string, array{product_id: int, product_variant_id: int|null}>  $lines  line item id => target
     * @param  array<int, int>  $capacity  order item id => units still allowed
     * @return array<int, array{order_item_id: int, quantity: int}>
     */
    private function allocate(Order $order, array $shopifyLines, array $lines, array $capacity, string $idKey): array
    {
        $orderItems = $order->items()->orderBy('id')->get(['id', 'product_id', 'product_variant_id']);
        $allocated = [];

        foreach ($shopifyLines as $shopifyLine) {
            if (! is_array($shopifyLine)) {
                continue;
            }
            $target = $lines[(string) ($shopifyLine[$idKey] ?? '')] ?? null;
            $quantity = (int) ($shopifyLine['quantity'] ?? 0);
            if ($target === null || $quantity <= 0) {
                continue;
            }

            foreach ($orderItems as $orderItem) {
                $variantId = $orderItem->product_variant_id === null ? null : (int) $orderItem->product_variant_id;
                if ((int) $orderItem->product_id !== $target['product_id'] || $variantId !== $target['product_variant_id']) {
                    continue;
                }
                $take = min($quantity, $capacity[$orderItem->id] ?? 0);
                if ($take > 0) {
                    $allocated[$orderItem->id] = ($allocated[$orderItem->id] ?? 0) + $take;
                    $capacity[$orderItem->id] -= $take;
                    $quantity -= $take;
                }
                if ($quantity === 0) {
                    break;
                }
            }
        }

        return array_map(fn ($id, $qty) => ['order_item_id' => (int) $id, 'quantity' => $qty], array_keys($allocated), $allocated);
    }

    /**
     * The Inventoros product or variant behind each Shopify line item.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array{product_id: int, product_variant_id: int|null}>
     */
    private function lineTargets(int $organizationId, array $payload): array
    {
        $targets = [];
        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            $sku = is_array($line) ? trim((string) ($line['sku'] ?? '')) : '';
            if ($sku !== '' && ($target = $this->findBySku($organizationId, $sku)) !== null) {
                $targets[(string) ($line['id'] ?? '')] = $target;
            }
        }

        return $targets;
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
        $quantities = OrderPayload::orderedQuantities($payload);

        foreach ((array) ($payload['line_items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $quantity = $quantities[(string) ($line['id'] ?? '')] ?? 0;
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
            $ordered = max(1, (int) ($line['quantity'] ?? $quantity));

            // Discounts and taxes are for the line as ordered; scale them to
            // the units still on it.
            $discount = '0.00';
            foreach ((array) ($line['discount_allocations'] ?? []) as $allocation) {
                $discount = Money::add($discount, self::shopMoney((array) $allocation, 'amount'));
            }
            $tax = '0.00';
            foreach ((array) ($line['tax_lines'] ?? []) as $taxLine) {
                $tax = Money::add($tax, self::shopMoney((array) $taxLine, 'price'));
            }
            if ($quantity !== $ordered) {
                $discount = Money::round(bcdiv(bcmul($discount, (string) $quantity, 6), (string) $ordered, 6));
                $tax = Money::round(bcdiv(bcmul($tax, (string) $quantity, 6), (string) $ordered, 6));
            }
            // Never discount below zero (Shopify can allocate rounding cents oddly).
            $discount = Money::min($discount, $gross);

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
     * Whether something that happened at $time is already reflected in the
     * stock Inventoros took from Shopify (before orders_since).
     */
    private function isHistory(ShopifyConnection $connection, mixed $time): bool
    {
        $at = self::time($time);

        return $at !== null && $connection->orders_since !== null && $at->lt($connection->orders_since);
    }

    private static function targetKey(int $productId, ?int $variantId): string
    {
        return $variantId === null ? "p{$productId}" : "v{$variantId}";
    }

    // ------------------------------------------------------------------
    // Order data
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, string>  $unmatched
     * @return array<string, mixed>
     */
    private function orderData(ShopifyConnection $connection, array $payload, array $items, array $unmatched): array
    {
        $name = (string) ($payload['name'] ?? '#'.$payload['id']);
        $notes = ["Shopify order {$name}."];
        if (filled($payload['note'] ?? null)) {
            $notes[] = 'Customer note: '.$payload['note'];
        }
        if ($unmatched !== []) {
            $notes[] = 'Not imported (no matching SKU): '.implode(', ', $unmatched).'.';
        }

        $paid = in_array($payload['financial_status'] ?? null, ['paid', 'partially_refunded', 'refunded'], true);

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
            'shipping' => $this->shipping($payload),
            'warehouse_id' => $this->orderWarehouse($connection, $payload),
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
     * The warehouse the order ships from: the one mapped to the Shopify
     * location its fulfilment orders are assigned to, else the default.
     *
     * @param  array<string, mixed>  $payload
     */
    private function orderWarehouse(ShopifyConnection $connection, array $payload): ?int
    {
        $map = $connection->locationMap();
        $mapped = array_values(array_filter($map, fn ($e) => $e['warehouse_id'] !== null));

        if ($mapped === []) {
            return $connection->warehouse_id;
        }
        if (count($map) === 1) {
            return $mapped[0]['warehouse_id'];
        }

        try {
            foreach ((new ShopifyClient($connection))->assignedLocations((string) $payload['id']) as $locationId) {
                if (($warehouse = $connection->warehouseForLocation($locationId)) !== null) {
                    return $warehouse;
                }
            }
        } catch (ShopifyException $e) {
            Log::info('Shopify: could not read the order\'s assigned location; using the default warehouse', ['error' => $e->getMessage()]);
        }

        return $connection->warehouse_id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipping(array $payload): string
    {
        $shipping = '0.00';
        foreach ((array) ($payload['shipping_lines'] ?? []) as $line) {
            $line = (array) $line;
            $shipping = Money::add($shipping, self::shopMoney($line, array_key_exists('discounted_price', $line) ? 'discounted_price' : 'price'));
        }

        return $shipping;
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

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

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

    private function finish(ShopifyOrder $record, string $status, ?string $message, ?int $orderId = null, ?Carbon $updatedAt = null): ShopifyOrder
    {
        $record->forceFill([
            'status' => $status,
            'message' => $message,
            'order_id' => $orderId ?? $record->order_id,
        ]);

        return $this->stamp($record, $updatedAt);
    }

    private function stamp(ShopifyOrder $record, ?Carbon $updatedAt): ShopifyOrder
    {
        if ($updatedAt !== null && ($record->shopify_updated_at === null || $updatedAt->gt($record->shopify_updated_at))) {
            $record->shopify_updated_at = $updatedAt;
        }
        $record->save();

        return $record;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
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
