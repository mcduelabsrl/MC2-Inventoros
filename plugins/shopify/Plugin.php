<?php

/**
 * Shopify integration for Inventoros.
 *
 *  - Orders: Shopify sends orders/create and orders/cancelled webhooks to
 *    /webhooks/plugins/shopify/{token}; each order becomes an Inventoros
 *    order (source "shopify") through OrderService, matched line by line on
 *    SKU, and a cancellation in Shopify cancels it and returns the stock.
 *  - Stock: every change of on-hand stock in Inventoros (stock_changed, from
 *    any surface) queues a push that sets the SKU's "available" quantity at
 *    one Shopify location. A full sync from the settings page pushes all.
 *  - Settings: /p/shopify, for users with the shopify.manage permission.
 *
 * See README.md for setting up the Shopify app.
 */

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Support\Facades\Route;
use Inventoros\Plugins\Shopify\Http\SettingsController;
use Inventoros\Plugins\Shopify\Http\WebhookController;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;

require_once __DIR__.'/src/autoload.php';

return function (string $slug, array $manifest): void {
    register_permission('shopify.manage', 'Manage Shopify', 'Connect a Shopify store, change its sync settings and retry order imports', 'Shopify');

    // ========================================
    // SETTINGS PAGE
    // ========================================

    register_page('plg.shopify.settings', 'Plugin::shopify/Settings', [
        'uri' => '/p/shopify',
        'title' => 'Shopify',
        'permission' => 'shopify.manage',
        'props' => fn ($request, $user) => SettingsController::props($request, $user),
    ]);

    register_menu_item([
        'label' => 'Shopify',
        'label_key' => 'plugins.shopify.nav.title',
        'route' => 'plg.shopify.settings',
        'permission' => 'shopify.manage',
        'position' => 900,
    ]);

    add_page_component('settings.index', 'sections', [
        'plugin' => 'shopify',
        'component' => 'ShopifySettingsCard',
        'permission' => 'shopify.manage',
        'data' => fn () => ['url' => route('plg.shopify.settings')],
    ]);

    Route::middleware(['web', 'auth', 'permission:shopify.manage'])
        ->prefix('p/shopify/actions')
        ->name('plg.shopify.')
        ->group(function () {
            Route::post('settings', [SettingsController::class, 'save'])->name('save');
            Route::post('webhooks', [SettingsController::class, 'registerWebhooks'])->name('webhooks');
            Route::post('sync', [SettingsController::class, 'sync'])->name('sync');
            Route::post('orders/{shopifyOrder}/retry', [SettingsController::class, 'retry'])->whereNumber('shopifyOrder')->name('orders.retry');
            Route::delete('connection', [SettingsController::class, 'disconnect'])->name('disconnect');
        });

    // Outside the web group: no session or CSRF; the HMAC signature
    // authenticates Shopify.
    Route::post('webhooks/plugins/shopify/{token}', WebhookController::class)
        ->where('token', '[A-Za-z0-9]{20,64}')
        ->middleware('throttle:600,1')
        ->name('plg.shopify.webhooks.receive');

    // ========================================
    // STOCK SYNC
    // ========================================

    // Asked on every stock change: one indexed lookup. Not memoized, because
    // queue workers run for days and would never see a new connection.
    $pushes = fn (int $organizationId): bool => (bool) ShopifyConnection::forOrganization($organizationId)?->canPushInventory();

    $queuePush = function (?Product $product, ?ProductVariant $variant) use ($pushes): void {
        if ($product === null || ! $pushes((int) $product->organization_id)) {
            return;
        }

        try {
            PushInventory::dispatch((int) $product->organization_id, (int) $product->id, $variant?->id);
        } catch (Throwable $e) {
            // Never let the integration break a stock movement.
            report($e);
        }
    };

    add_action('stock_changed', fn ($product, $variant) => $queuePush($product, $variant));

    // A new product or a changed SKU may now match a Shopify variant.
    add_action('product_created', fn ($product) => $queuePush($product, null));
    add_action('product_updated', fn ($product) => $product->wasChanged('sku') ? $queuePush($product, null) : null);
    add_action('variant_created', fn ($variant) => $queuePush($variant->product, $variant));
    add_action('variant_updated', fn ($variant) => $variant->wasChanged('sku') ? $queuePush($variant->product, $variant) : null);
};
