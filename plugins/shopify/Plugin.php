<?php

/**
 * Shopify integration for Inventoros.
 *
 *  - Orders: Shopify sends orders/create, orders/updated and orders/cancelled
 *    webhooks to /webhooks/plugins/shopify/{token}. OrderImporter reconciles
 *    each: new orders are created (matched line by line on SKU), and edits,
 *    cancellations, fulfilments (shipments) and refunds with restock
 *    (returns) are applied. Every ten minutes OrderRecovery reads back the
 *    orders Shopify changed, as a backstop for missed webhooks.
 *  - Stock: every change of on-hand stock in Inventoros (stock_changed, from
 *    any surface) queues a push that sets the SKU's "available" quantity at
 *    each mapped Shopify location, from the mapped warehouse. A full sync
 *    runs nightly and on demand.
 *  - Catalogue: Shopify products can be imported into Inventoros, and
 *    Inventoros products published to Shopify (CatalogueSync).
 *  - Settings: /p/shopify, for users with the shopify.manage permission.
 *
 * See README.md for setting up the Shopify app.
 */

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Inventoros\Plugins\Shopify\Http\SettingsController;
use Inventoros\Plugins\Shopify\Http\StockSyncController;
use Inventoros\Plugins\Shopify\Jobs\ProcessStockOutbox;
use Inventoros\Plugins\Shopify\Jobs\ProcessWebhookEvent;
use Inventoros\Plugins\Shopify\Jobs\ReconcileInventoryLevels;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\Models\ShopifyWebhookEvent;
use Inventoros\Plugins\Shopify\Stock\OutboundPlanner;
use Inventoros\Plugins\Shopify\Stock\StockConsole;
use Inventoros\Plugins\Shopify\Stock\StockStatus;
use Inventoros\Plugins\Shopify\Http\WebhookController;
use Inventoros\Plugins\Shopify\Jobs\FullInventorySync;
use Inventoros\Plugins\Shopify\Jobs\PushInventory;
use Inventoros\Plugins\Shopify\Jobs\RecoverOrders;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\PushGate;

require_once __DIR__.'/src/autoload.php';

return function (string $slug, array $manifest): void {
    // An update copied over an active install: add the new columns once.
    $schemaKey = 'shopify-plugin-schema-'.($manifest['version'] ?? '0');
    if (Cache::get($schemaKey) !== true) {
        try {
            // activate.php is idempotent: it only adds what is missing.
            if (Schema::hasTable('shopify_connections')) {
                require __DIR__.'/hooks/activate.php';
            }
            Cache::forever($schemaKey, true);
        } catch (Throwable $e) {
            report($e);
        }
    }

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
            Route::post('recover', [SettingsController::class, 'recover'])->name('recover');
            Route::post('catalogue/import', [SettingsController::class, 'importCatalogue'])->name('catalogue.import');
            Route::post('catalogue/publish', [SettingsController::class, 'publishCatalogue'])->name('catalogue.publish');
            Route::post('orders/{shopifyOrder}/retry', [SettingsController::class, 'retry'])->whereNumber('shopifyOrder')->name('orders.retry');
            Route::delete('connection', [SettingsController::class, 'disconnect'])->name('disconnect');
        });

    // Decisions on stock sync issues, from the stock-by-warehouse screen.
    Route::middleware(['web', 'auth', 'permission:manage_stock'])
        ->prefix('p/shopify/actions/stock')
        ->name('plg.shopify.stock.')
        ->group(function () {
            Route::post('issues/{issue}/accept-shopify', [StockSyncController::class, 'acceptShopify'])->whereNumber('issue')->name('issues.accept');
            Route::post('issues/{issue}/keep-inventoros', [StockSyncController::class, 'keepInventoros'])->whereNumber('issue')->name('issues.keep');
            Route::post('issues/{issue}/realign-order', [StockSyncController::class, 'realignOrder'])->whereNumber('issue')->name('issues.realign');
            Route::post('operations/{operation}/retry', [StockSyncController::class, 'retry'])->whereNumber('operation')->name('operations.retry');
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
    $pushes = fn (int $organizationId): bool => (bool) ShopifyConnection::forOrganization($organizationId)?->legacyPushAllowed();

    $queuePush = function (?Product $product, ?ProductVariant $variant) use ($pushes): void {
        if ($product === null || PushGate::isPaused() || ! $pushes((int) $product->organization_id)) {
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

    // Stock per warehouse (2.0): every local operation on variant stock
    // becomes tracked Shopify operations in the outbox, sent in order with
    // compare-and-swap and idempotency keys (see Stock/OutboxProcessor).
    add_action('variant_stock_changed', function ($operation): void {
        try {
            $connection = ShopifyConnection::forOrganization((int) $operation->organization_id);
            if ($connection === null || ! $connection->isTwoWay()) {
                return;
            }
            // Read it again: meta may have been completed in the same
            // transaction (e.g. where a Shopify refund restocked).
            $fresh = \App\Models\Inventory\VariantStockOperation::with('movements')->find($operation->id);
            if ($fresh !== null && (new OutboundPlanner($connection))->plan($fresh) !== []) {
                ProcessStockOutbox::dispatch((int) $connection->organization_id);
            }
        } catch (Throwable $e) {
            // Never let the integration break a stock movement; the periodic
            // check finds what was not sent.
            report($e);
        }
    });

    add_filter('variant_stock_channel_status', fn ($statuses, $organizationId, $variantIds) => StockStatus::statuses($statuses, (int) $organizationId, (array) $variantIds));
    add_filter('variant_stock_channel_issues', fn ($issues, $organizationId, $variantId) => StockStatus::issues($issues, (int) $organizationId, $variantId === null ? null : (int) $variantId));

    // A new product or a changed SKU may now match a Shopify variant.
    add_action('product_created', fn ($product) => $queuePush($product, null));
    add_action('product_updated', fn ($product) => $product->wasChanged('sku') ? $queuePush($product, null) : null);
    add_action('variant_created', fn ($variant) => $queuePush($variant->product, $variant));
    add_action('variant_updated', fn ($variant) => $variant->wasChanged('sku') ? $queuePush($variant->product, $variant) : null);

    // ========================================
    // QUEUE WORKERS
    // ========================================

    // Long-running queue workers load plugins when they start. One started
    // before the plugin was activated cannot run its jobs (they fail at
    // once), and one started before it was deactivated would keep running
    // them. Ask every worker to restart after its current job; the process
    // manager (Docker, systemd, Supervisor) starts it again with the plugin
    // as it is now. The scheduler starts a fresh process every minute.
    $restartWorkers = function (): void {
        try {
            Artisan::call('queue:restart');
        } catch (Throwable $e) {
            report($e);
        }
    };
    add_action('plugin_activated_shopify', $restartWorkers);
    add_action('plugin_deactivated_shopify', $restartWorkers);

    // ========================================
    // SCHEDULE
    // ========================================

    // Backstop for missed webhooks, every ten minutes; and a nightly full
    // stock sync, so Shopify never drifts for long. Each store is its own job.
    if (app()->runningInConsole()) {
        Schedule::call(function () {
            ShopifyConnection::query()->where('import_orders', true)->whereNotNull('shop_name')->pluck('organization_id')
                ->each(fn ($organizationId) => RecoverOrders::dispatch((int) $organizationId));
        })->everyTenMinutes()->name('shopify:recover-orders')->withoutOverlapping();

        // 1.x only: the nightly push of available quantities. A store set up
        // per warehouse never pushes totals (FullInventorySync refuses).
        Schedule::call(function () {
            ShopifyConnection::query()->where('push_inventory', true)->whereNotNull('location_map')
                ->where(fn ($q) => $q->whereNull('stock_sync_mode')->orWhere('stock_sync_mode', ShopifyConnection::STOCK_LEGACY))
                ->pluck('organization_id')
                ->each(fn ($organizationId) => FullInventorySync::dispatch((int) $organizationId));
        })->dailyAt('02:40')->name('shopify:full-stock-sync')->withoutOverlapping();

        // Stock per warehouse: send what waits in the outbox (retries
        // included), check levels whose check was put off, and pick up
        // webhook deliveries that failed or waited.
        Schedule::call(function () {
            ShopifyConnection::query()->where('stock_sync_mode', ShopifyConnection::STOCK_TWO_WAY)->pluck('organization_id')
                ->each(function ($organizationId) {
                    if (ShopifyStockOperation::query()->where('organization_id', $organizationId)
                        ->whereIn('status', [ShopifyStockOperation::PENDING, ShopifyStockOperation::SENDING])->exists()) {
                        ProcessStockOutbox::dispatch((int) $organizationId);
                    }
                });
        })->everyMinute()->name('shopify:stock-outbox')->withoutOverlapping();

        Schedule::call(function () {
            ShopifyConnection::query()->where('stock_sync_mode', ShopifyConnection::STOCK_TWO_WAY)->pluck('organization_id')
                ->each(fn ($organizationId) => ReconcileInventoryLevels::dispatch((int) $organizationId));
        })->everyTwoMinutes()->name('shopify:stock-checks')->withoutOverlapping();

        Schedule::call(function () {
            ShopifyWebhookEvent::query()
                ->whereIn('status', [ShopifyWebhookEvent::RECEIVED, ShopifyWebhookEvent::DEFERRED, ShopifyWebhookEvent::FAILED])
                ->where('updated_at', '<', now()->subMinutes(10))
                ->where('attempts', '<', 10)
                ->orderBy('id')->limit(200)->pluck('id')
                ->each(fn ($id) => ProcessWebhookEvent::dispatch((int) $id));
        })->everyTenMinutes()->name('shopify:webhook-retries')->withoutOverlapping();

        // Nightly read-only check of every level: catches what no webhook
        // reported. It reads Shopify; it never pushes local totals.
        Schedule::call(function () {
            ShopifyConnection::query()->where('stock_sync_mode', ShopifyConnection::STOCK_TWO_WAY)->pluck('organization_id')
                ->each(fn ($organizationId) => \Inventoros\Plugins\Shopify\Jobs\FullStockReconcile::dispatch((int) $organizationId));
        })->dailyAt('02:40')->name('shopify:stock-reconcile')->withoutOverlapping();

        StockConsole::register();
    }
};
