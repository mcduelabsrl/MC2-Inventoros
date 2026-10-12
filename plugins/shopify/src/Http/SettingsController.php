<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inventoros\Plugins\Shopify\Jobs\CatalogueJob;
use Inventoros\Plugins\Shopify\Jobs\FullInventorySync;
use Inventoros\Plugins\Shopify\Jobs\RecoverOrders;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Inventoros\Plugins\Shopify\OrderImporter;
use Inventoros\Plugins\Shopify\ShopifyClient;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * The Shopify settings page (/p/shopify) and its actions. Everything is
 * scoped to the signed-in user's active organization and needs the
 * shopify.manage permission (checked by the routes).
 */
class SettingsController
{
    /**
     * Props of the settings page. Secrets never leave the server: the page
     * only learns whether a client secret is stored.
     *
     * @return array<string, mixed>
     */
    public static function props(Request $request, User $user): array
    {
        $orgId = (int) $user->organization_id;
        $connection = ShopifyConnection::forOrganization($orgId);

        return [
            'connection' => $connection === null ? null : [
                'shop_domain' => $connection->shop_domain,
                'shop_name' => $connection->shop_name,
                'client_id' => $connection->client_id,
                'has_client_secret' => filled($connection->getRawOriginal('client_secret')),
                'api_version' => $connection->api_version,
                'location_id' => $connection->location_id,
                'location_name' => $connection->locationName(),
                'location_map' => $connection->locationMap(),
                'locations' => $connection->locations ?? [],
                'warehouse_id' => $connection->warehouse_id,
                'import_orders' => $connection->import_orders,
                'push_inventory' => $connection->push_inventory,
                'connected' => filled($connection->shop_name),
                'webhooks_registered_at' => $connection->webhooks_registered_at?->toIso8601String(),
                'last_full_sync_at' => $connection->last_full_sync_at?->toIso8601String(),
                'last_full_sync_summary' => $connection->last_full_sync_summary,
                'last_error' => $connection->last_error,
                'missing_scopes' => $connection->missing_scopes ?? [],
                'webhooks_checked_at' => $connection->webhooks_checked_at?->toIso8601String(),
                'orders_since' => $connection->orders_since?->toIso8601String(),
                'orders_synced_at' => $connection->orders_synced_at?->toIso8601String(),
                'orders_sync_summary' => $connection->orders_sync_summary,
                'catalogue_import_summary' => $connection->catalogue_import_summary,
                'catalogue_publish_summary' => $connection->catalogue_publish_summary,
                'acting_user' => $connection->user_id === null ? null : User::query()->whereKey($connection->user_id)->value('name'),
            ],
            'webhookUrl' => $connection === null ? null : self::webhookUrl($connection),
            'defaultApiVersion' => ShopifyConnection::DEFAULT_API_VERSION,
            'allOrders' => $connection !== null && ! in_array('read_all_orders', (array) ($connection->missing_scopes ?? ['read_all_orders']), true),
            'requiredScopes' => ShopifyClient::REQUIRED_SCOPES,
            'optionalScopes' => ShopifyClient::OPTIONAL_SCOPES,
            'warehouses' => Warehouse::query()
                ->where('organization_id', $orgId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])
                ->all(),
            'orders' => ShopifyOrder::query()
                ->with('order:id,order_number')
                ->where('organization_id', $orgId)
                ->latest('updated_at')
                ->limit(50)
                ->get()
                ->map(fn (ShopifyOrder $o) => [
                    'id' => $o->id,
                    'shopify_order_id' => $o->shopify_order_id,
                    'name' => $o->shopify_order_name ?? '#'.$o->shopify_order_id,
                    'status' => $o->status,
                    'message' => $o->message,
                    'attempts' => $o->attempts,
                    'order_id' => $o->order_id,
                    'order_number' => $o->order?->order_number,
                    'order_url' => $o->order_id ? route('orders.show', $o->order_id) : null,
                    'retryable' => $o->isRetryable(),
                    'updated_at' => $o->updated_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    public function save(Request $request): RedirectResponse
    {
        $user = $request->user();
        $orgId = (int) $user->organization_id;
        $connection = ShopifyConnection::forOrganization($orgId);

        $validated = $request->validate([
            'shop_domain' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$connection === null ? 'required' : 'nullable', 'string', 'max:255'],
            'api_version' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'location_id' => ['nullable', 'string', 'max:255'],
            'location_map' => ['nullable', 'array', 'max:50'],
            'location_map.*.location_id' => ['required', 'string', 'max:255'],
            'location_map.*.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $orgId)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('organization_id', $orgId)],
            'import_orders' => ['boolean'],
            'push_inventory' => ['boolean'],
        ]);

        $domain = self::normalizeDomain($validated['shop_domain']);
        if ($domain === null) {
            return back()->withErrors(['shop_domain' => 'Enter the store\'s myshopify.com address, for example my-store.myshopify.com.'])->withInput();
        }

        $connection ??= new ShopifyConnection(['organization_id' => $orgId]);
        $credentialsChanged = $connection->shop_domain !== $domain
            || $connection->client_id !== $validated['client_id']
            || filled($validated['client_secret'] ?? null);

        $connection->fill([
            'user_id' => $user->id,
            'shop_domain' => $domain,
            'client_id' => trim($validated['client_id']),
            'api_version' => $validated['api_version'] ?? $connection->api_version ?? ShopifyConnection::DEFAULT_API_VERSION,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'import_orders' => (bool) ($validated['import_orders'] ?? true),
            'push_inventory' => (bool) ($validated['push_inventory'] ?? true),
        ]);
        if (filled($validated['client_secret'] ?? null)) {
            $connection->client_secret = trim($validated['client_secret']);
        }
        if ($credentialsChanged) {
            $connection->access_token = null;
            $connection->token_expires_at = null;
        }
        $connection->save();

        // Prove the credentials work and refresh the store's locations.
        try {
            $client = new ShopifyClient($connection);
            // Always a fresh token here: one issued before the app's scopes
            // changed would keep the old scopes for up to 24 hours.
            $client->refreshToken();
            $info = $client->shopInfo();
        } catch (ShopifyException $e) {
            $connection->forceFill(['shop_name' => null, 'last_error' => $e->getMessage()])->save();

            return back()->with('error', 'Settings saved, but Shopify could not be reached: '.$e->getMessage());
        }

        $locationIds = array_column($info['locations'], 'id');
        $map = $this->locationMap($request, $validated, $connection, $locationIds);
        if (is_string($map)) {
            $connection->forceFill(['shop_name' => $info['name'], 'locations' => $info['locations'], 'last_error' => null])->save();

            return back()->withErrors(['location_map' => $map])->with('error', $map);
        }

        $connection->forceFill([
            'shop_name' => $info['name'],
            'locations' => $info['locations'],
            'location_map' => $map,
            'missing_scopes' => $info['missing_optional_scopes'],
            'last_error' => null,
        ])->save();
        $location = $map[0]['location_id'] ?? null;

        $message = "Connected to {$info['name']}.";
        if ($connection->push_inventory && $location === null) {
            $message .= ' Choose the Shopify location to update, then save again.';
        }

        return back()->with('success', $message);
    }

    public function registerWebhooks(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);
        $url = self::webhookUrl($connection);

        if (! str_starts_with($url, 'https://')) {
            return back()->with('error', "Shopify only delivers webhooks over HTTPS, and this install's address is {$url}. Set APP_URL to the public https address first.");
        }

        try {
            $result = (new ShopifyClient($connection))->registerWebhooks($url);
        } catch (ShopifyException $e) {
            return back()->with('error', 'Could not register the webhooks: '.$e->getMessage());
        }

        $connection->forceFill(['webhooks_registered_at' => now()])->save();

        return back()->with('success', $result['created'] === []
            ? 'The order webhooks were already registered.'
            : 'Registered the webhooks: '.implode(', ', $result['created']).'.');
    }

    public function sync(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);

        if (! $connection->canPushInventory()) {
            return back()->with('error', 'Turn on stock sync and map a Shopify location first.');
        }

        FullInventorySync::dispatch($connection->organization_id);

        return back()->with('success', 'Stock sync started. The summary appears here when it finishes.');
    }

    public function retry(Request $request, int $shopifyOrder, OrderImporter $importer): RedirectResponse
    {
        $connection = $this->connection($request);
        $record = ShopifyOrder::query()
            ->where('organization_id', $connection->organization_id)
            ->findOrFail($shopifyOrder);

        if (! $record->isRetryable()) {
            return back()->with('error', 'Only failed or skipped orders can be imported again.');
        }

        $record = $importer->import($connection, $record->decodedPayload());

        return $record->status === ShopifyOrder::IMPORTED
            ? back()->with('success', "Imported Shopify order {$record->shopify_order_name}.")
            : back()->with('error', "Shopify order {$record->shopify_order_name} was not imported: {$record->message}");
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);
        $connection->delete();

        return back()->with('success', 'Shopify disconnected. Remove the app from the store in Shopify too, so it stops sending webhooks.');
    }

    public function recover(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);
        // Shopify shows apps the last 60 days of orders, all of them with read_all_orders.
        $allOrders = ! in_array('read_all_orders', (array) ($connection->missing_scopes ?? ['read_all_orders']), true);
        $rules = ['required', 'date', 'before_or_equal:now'];
        if (! $allOrders) {
            $rules[] = 'after:'.now()->subDays(60)->toDateString();
        }
        $validated = $request->validate(['from' => $rules], ['from.after' => 'Shopify only gives apps the last 60 days of orders. Grant the app read_all_orders to go further back.']);

        RecoverOrders::dispatch($connection->organization_id, Carbon::parse($validated['from'])->startOfDay()->toIso8601String());

        return back()->with('success', 'Order recovery started. Orders created since '.$validated['from'].' that are missing will be imported.');
    }

    public function importCatalogue(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);
        $validated = $request->validate(['with_stock' => ['boolean']]);

        CatalogueJob::dispatch($connection->organization_id, CatalogueJob::IMPORT, (bool) ($validated['with_stock'] ?? true));

        return back()->with('success', 'Catalogue import started. The summary appears here when it finishes.');
    }

    public function publishCatalogue(Request $request): RedirectResponse
    {
        $connection = $this->connection($request);
        $validated = $request->validate(['status' => ['required', 'in:draft,active']]);

        if ($connection->locationMap() === []) {
            return back()->with('error', 'Map at least one Shopify location first, so new products get their stock.');
        }

        CatalogueJob::dispatch($connection->organization_id, CatalogueJob::PUBLISH, status: strtoupper($validated['status']));

        return back()->with('success', 'Publishing started. The summary appears here when it finishes.');
    }

    /**
     * The location map to store, or an error message.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $locationIds  the store's active locations
     * @return array<int, array{location_id: string, warehouse_id: int|null}>|string
     */
    private function locationMap(Request $request, array $validated, ShopifyConnection $connection, array $locationIds): array|string
    {
        if ($request->has('location_map')) {
            $entries = (array) ($validated['location_map'] ?? []);
        } elseif (filled($validated['location_id'] ?? null)) {
            $entries = [['location_id' => $validated['location_id'], 'warehouse_id' => null]];
        } else {
            $entries = $connection->locationMap();
        }

        $map = [];
        foreach ($entries as $entry) {
            if (in_array($entry['location_id'] ?? null, $locationIds, true)) {
                $map[] = ['location_id' => (string) $entry['location_id'], 'warehouse_id' => isset($entry['warehouse_id']) ? (int) $entry['warehouse_id'] : null];
            }
        }

        if ($map === [] && count($locationIds) === 1 && ! $request->has('location_map')) {
            return [['location_id' => $locationIds[0], 'warehouse_id' => null]];
        }

        if (count($map) !== count(array_unique(array_column($map, 'location_id')))) {
            return 'Each Shopify location can be mapped once.';
        }

        if (count($map) > 1) {
            $warehouses = array_column($map, 'warehouse_id');
            if (in_array(null, $warehouses, true)) {
                return 'With more than one Shopify location, give each its own Inventoros warehouse; "all warehouses" would publish the same stock twice.';
            }
            if (count($warehouses) !== count(array_unique($warehouses))) {
                return 'Two Shopify locations cannot share one warehouse: its stock would be published twice.';
            }
        }

        return $map;
    }

    private function connection(Request $request): ShopifyConnection
    {
        $connection = ShopifyConnection::forOrganization((int) $request->user()->organization_id);
        abort_if($connection === null, 404, 'Shopify is not connected.');

        return $connection;
    }

    /**
     * The address Shopify posts to. Built from APP_URL, not from the current
     * request, so an admin browsing on a LAN address still registers the
     * public one.
     */
    public static function webhookUrl(ShopifyConnection $connection): string
    {
        return rtrim((string) config('app.url'), '/').route('plg.shopify.webhooks.receive', ['token' => $connection->webhook_token], false);
    }

    /**
     * "https://My-Store.myshopify.com/admin" -> "my-store.myshopify.com", or
     * null for anything that is not a myshopify.com store address.
     */
    public static function normalizeDomain(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];

        if (! str_contains($host, '.')) {
            $host .= '.myshopify.com';
        }

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $host) === 1 ? $host : null;
    }
}
