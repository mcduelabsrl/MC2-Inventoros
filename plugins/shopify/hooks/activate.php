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
 */

use Illuminate\Database\Schema\Blueprint;
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
