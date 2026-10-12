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
