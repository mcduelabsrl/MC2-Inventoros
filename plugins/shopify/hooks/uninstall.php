<?php

/**
 * Runs once when the plugin is deleted: drops everything it created,
 * including the stored Shopify credentials. Inventoros orders it imported
 * stay (they are ordinary orders with source "shopify").
 */

use Illuminate\Support\Facades\Schema;

Schema::dropIfExists('shopify_links');
Schema::dropIfExists('shopify_inventory_items');
Schema::dropIfExists('shopify_orders');
Schema::dropIfExists('shopify_connections');
