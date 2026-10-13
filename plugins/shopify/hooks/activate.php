<?php

/**
 * Creates the Shopify plugin's tables. Safe to run more than once.
 *
 *  - shopify_connections: one Shopify store per organization, with its app
 *    credentials (encrypted) and sync settings.
 *  - shopify_orders: every Shopify order the plugin received, with the
 *    Inventoros order it became (or why it did not), so a webhook delivered
 *    twice never creates two orders and a failed import can be retried.
 *  - shopify_inventory_items: the Shopify inventory item behind each SKU,
 *    cached so a stock push costs one API call.
 *  - shopify_links: the Shopify fulfilments and refunds already turned into
 *    Inventoros shipments and returns, so each is applied once.
 *
 * Columns added after 1.0.0 are added to existing tables here too: Plugin.php
 * runs this file again when it finds the schema out of date.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! Schema::hasTable('shopify_connections')) {
    Schema::create('shopify_connections', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
        // The user orders and stock pushes are attributed to: whoever saved
        // the connection. Must stay a member of the organization.
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('shop_domain');
        $table->string('shop_name')->nullable();
        $table->string('client_id');
        $table->text('client_secret');
        $table->text('access_token')->nullable();
        $table->timestamp('token_expires_at')->nullable();
        $table->string('api_version', 10);
        $table->string('location_id')->nullable();
        $table->json('locations')->nullable();
        $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
        $table->string('webhook_token', 64)->unique();
        $table->boolean('import_orders')->default(true);
        $table->boolean('push_inventory')->default(true);
        $table->timestamp('webhooks_registered_at')->nullable();
        $table->timestamp('last_full_sync_at')->nullable();
        $table->json('last_full_sync_summary')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();
    });
}

if (! Schema::hasTable('shopify_orders')) {
    Schema::create('shopify_orders', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('shopify_order_id', 32);
        $table->string('shopify_order_name')->nullable();
        $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        // imported, failed, skipped or cancelled
        $table->string('status', 20);
        $table->text('message')->nullable();
        $table->longText('payload');
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamps();

        $table->unique(['organization_id', 'shopify_order_id']);
        $table->index(['organization_id', 'status']);
    });
}

if (! Schema::hasTable('shopify_inventory_items')) {
    Schema::create('shopify_inventory_items', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('sku');
        $table->string('inventory_item_id');
        $table->string('variant_id')->nullable();
        $table->boolean('tracked')->default(true);
        $table->integer('last_pushed_quantity')->nullable();
        $table->timestamp('last_pushed_at')->nullable();
        $table->text('last_error')->nullable();
        $table->timestamps();

        $table->unique(['organization_id', 'sku']);
    });
}

// 1.1.0: location mapping, order reconciliation, catalogue sync, recovery.
$addColumns = function (string $table, array $columns): void {
    foreach ($columns as $name => $define) {
        if (! Schema::hasColumn($table, $name)) {
            Schema::table($table, function (Blueprint $table) use ($define) {
                $define($table);
            });
        }
    }
};

$addColumns('shopify_connections', [
    // [{location_id, warehouse_id|null}]: null warehouse = total stock.
    'location_map' => fn (Blueprint $t) => $t->json('location_map')->nullable(),
    // Orders created before this are history, never imported by recovery.
    'orders_since' => fn (Blueprint $t) => $t->timestamp('orders_since')->nullable(),
    'orders_synced_at' => fn (Blueprint $t) => $t->timestamp('orders_synced_at')->nullable(),
    'orders_sync_summary' => fn (Blueprint $t) => $t->json('orders_sync_summary')->nullable(),
    'webhooks_checked_at' => fn (Blueprint $t) => $t->timestamp('webhooks_checked_at')->nullable(),
    'catalogue_import_summary' => fn (Blueprint $t) => $t->json('catalogue_import_summary')->nullable(),
    'catalogue_publish_summary' => fn (Blueprint $t) => $t->json('catalogue_publish_summary')->nullable(),
    // 1.1.1: optional scopes the store has not granted, shown on the settings page.
    'missing_scopes' => fn (Blueprint $t) => $t->json('missing_scopes')->nullable(),
]);

$addColumns('shopify_orders', [
    // Shopify's updated_at of the state last applied: older payloads are ignored.
    'shopify_updated_at' => fn (Blueprint $t) => $t->timestamp('shopify_updated_at')->nullable(),
]);

$addColumns('shopify_inventory_items', [
    // {location_id: quantity} last pushed to each mapped location.
    'last_pushed' => fn (Blueprint $t) => $t->json('last_pushed')->nullable(),
]);

if (! Schema::hasTable('shopify_links')) {
    Schema::create('shopify_links', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('shopify_order_id', 32);
        // fulfillment or refund
        $table->string('kind', 20);
        $table->string('shopify_id', 64);
        // The Shipment or ReturnOrder it became.
        $table->unsignedBigInteger('local_id')->nullable();
        $table->string('state', 20)->nullable();
        $table->timestamps();

        $table->unique(['organization_id', 'kind', 'shopify_id']);
        $table->index(['organization_id', 'shopify_order_id']);
    });
}

// Connections saved by 1.0.0 had one location and pushed the total.
DB::table('shopify_connections')
    ->whereNull('location_map')
    ->whereNotNull('location_id')
    ->orderBy('id')
    ->each(function ($row) {
        DB::table('shopify_connections')->where('id', $row->id)->update([
            'location_map' => json_encode([['location_id' => $row->location_id, 'warehouse_id' => null]]),
        ]);
    });

DB::table('shopify_connections')
    ->whereNull('orders_since')
    ->update(['orders_since' => DB::raw('created_at')]);

// 2.0.0: two-way stock per warehouse.

$addColumns('shopify_connections', [
    // legacy: 1.x push of available quantities. paused: per-warehouse stock
    // is being set up (events are kept, nothing is sent). two_way: tracked
    // operations both ways.
    'stock_sync_mode' => fn (Blueprint $t) => $t->string('stock_sync_mode', 20)->default('legacy'),
    // Shopify fulfilments, refunds and cancellations before this moment are
    // already part of the opening balances and never move stock again.
    'stock_baseline_at' => fn (Blueprint $t) => $t->timestamp('stock_baseline_at')->nullable(),
    'stock_bootstrap_summary' => fn (Blueprint $t) => $t->json('stock_bootstrap_summary')->nullable(),
    'stock_reconciled_at' => fn (Blueprint $t) => $t->timestamp('stock_reconciled_at')->nullable(),
    // 2.1: shipments made in Inventoros fulfil the Shopify order.
    'push_fulfillments' => fn (Blueprint $t) => $t->boolean('push_fulfillments')->default(true),
]);

$addColumns('shopify_links', [
    'meta' => fn (Blueprint $t) => $t->json('meta')->nullable(),
]);

if (! Schema::hasTable('shopify_webhook_events')) {
    // Every verified delivery, kept before it is processed: a delivery
    // Shopify repeats (same X-Shopify-Webhook-Id) is recognised and
    // dropped, and nothing is lost if processing fails or waits.
    Schema::create('shopify_webhook_events', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('webhook_id', 100);
        $table->string('topic', 60);
        $table->timestamp('triggered_at')->nullable();
        $table->longText('payload');
        // received, processing, processed, deferred, failed, ignored
        $table->string('status', 20)->default('received');
        $table->unsignedInteger('attempts')->default(0);
        $table->text('error')->nullable();
        $table->timestamp('processed_at')->nullable();
        $table->timestamps();

        $table->unique(['organization_id', 'webhook_id']);
        $table->index(['organization_id', 'status']);
    });
}

if (! Schema::hasTable('shopify_inventory_levels')) {
    // One row per Shopify inventory item and mapped location: what Shopify
    // reported last (s_*), and the two sides' figures the last time they
    // were known to agree (agreed_s_*, agreed_l_*). A change on one side
    // since that agreement is what gets applied to the other.
    Schema::create('shopify_inventory_levels', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('inventory_item_id', 64);
        $table->string('location_id', 64);
        $table->unsignedBigInteger('product_variant_id')->nullable();
        $table->unsignedBigInteger('warehouse_id')->nullable();
        foreach (['available', 'on_hand', 'committed', 'reserved', 'damaged', 'quality_control', 'safety_stock', 'incoming'] as $name) {
            $table->integer('s_'.$name)->default(0);
        }
        $table->json('agreed_s')->nullable();
        $table->json('agreed_l')->nullable();
        $table->timestamp('shopify_updated_at')->nullable();
        $table->timestamp('read_at')->nullable();
        // synced, pending, error, conflict, unmapped
        $table->string('sync_status', 20)->default('pending');
        $table->text('status_detail')->nullable();
        $table->timestamp('last_synced_at')->nullable();
        $table->timestamp('check_after')->nullable();
        $table->unsignedInteger('deferrals')->default(0);
        $table->timestamps();

        $table->unique(['organization_id', 'inventory_item_id', 'location_id'], 'shopify_levels_unique');
        $table->index(['organization_id', 'product_variant_id'], 'shopify_levels_variant_index');
        $table->index(['organization_id', 'sync_status']);
    });
}

if (! Schema::hasTable('shopify_stock_operations')) {
    // The outbox: each Shopify mutation Inventoros has to make, with its
    // own idempotency key (reused on every retry, so Shopify applies it
    // once) and the quantity it expects to change from (compare-and-swap).
    Schema::create('shopify_stock_operations', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        // adjust, move, set, fulfillment_order_move, transfer_edit, shipment_receive
        $table->string('kind', 30);
        // awaiting_approval, pending, sending, confirmed, failed, conflict, cancelled
        $table->string('status', 20)->default('pending');
        $table->uuid('idempotency_key');
        $table->unsignedBigInteger('variant_stock_operation_id')->nullable();
        $table->unsignedBigInteger('transfer_id')->nullable();
        $table->unsignedBigInteger('order_id')->nullable();
        $table->unsignedBigInteger('depends_on_id')->nullable();
        $table->unsignedSmallInteger('step')->default(1);
        // absolute (an operator's count: a stale figure is a conflict) or
        // delta (a movement: re-based on the current figure and retried)
        $table->string('mode', 10)->default('delta');
        $table->json('payload');
        // Quantity deltas this operation makes, per item|location|name, to
        // move the agreed figures when it is confirmed.
        $table->json('effect')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->text('last_error')->nullable();
        $table->string('adjustment_group_id')->nullable();
        $table->json('response')->nullable();
        $table->timestamp('next_attempt_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamp('confirmed_at')->nullable();
        $table->timestamps();

        $table->unique('idempotency_key');
        $table->index(['organization_id', 'status']);
        $table->index('variant_stock_operation_id');
        $table->index('transfer_id');
    });
}

if (! Schema::hasTable('shopify_stock_issues')) {
    // What needs an operator: a count refused because the stock moved, a
    // difference neither side explains, an order whose locations do not
    // match its commitments, a failed operation.
    Schema::create('shopify_stock_issues', function (Blueprint $table) {
        $table->id();
        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
        $table->string('kind', 40);
        $table->string('inventory_item_id', 64)->nullable();
        $table->string('location_id', 64)->nullable();
        $table->unsignedBigInteger('product_variant_id')->nullable();
        $table->unsignedBigInteger('warehouse_id')->nullable();
        $table->unsignedBigInteger('stock_operation_id')->nullable();
        $table->unsignedBigInteger('order_id')->nullable();
        $table->json('details')->nullable();
        $table->text('message');
        // open, resolved
        $table->string('status', 20)->default('open');
        $table->string('resolution', 40)->nullable();
        $table->unsignedBigInteger('resolved_by')->nullable();
        $table->timestamp('resolved_at')->nullable();
        $table->timestamps();

        $table->index(['organization_id', 'status']);
        $table->index(['organization_id', 'product_variant_id']);
    });
}
