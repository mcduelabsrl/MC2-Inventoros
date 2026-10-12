# Shopify plugin

Connects one Shopify store to each Inventoros organization:

- **Orders, Shopify to Inventoros.** Every new Shopify order becomes an Inventoros order (source `shopify`) and takes its items out of stock. Cancelling the order in Shopify cancels it in Inventoros and puts the stock back.
- **Stock, Inventoros to Shopify.** Every change of on-hand stock in Inventoros (adjustments, orders, receipts, transfers, returns, audits, imports, from any screen or API) sets the item's **available** quantity at one Shopify location.

Products are matched by **SKU**: a Shopify variant's SKU must equal an Inventoros variant SKU, or the SKU of an Inventoros product sold without variants.

## Setting up

### 1. Create the app in Shopify

Shopify no longer lets you create custom apps in the store admin. Create one in the Dev Dashboard instead:

1. Open the [Shopify Dev Dashboard](https://dev.shopify.com/dashboard) with the account that owns the store, then choose **Create app** and **Start from Dev Dashboard**. Name it, for example, "Inventoros".
2. Under **Versions**, create a version with these **access scopes** and release it:
   `read_orders`, `read_products`, `read_inventory`, `write_inventory`, `read_locations`
3. **Install** the app on your store. The plugin authenticates with Shopify's client credentials grant, which only works for stores in the same Shopify organization as the app.
4. Under **Settings**, copy the **Client ID** and **Client secret**.

### 2. Connect Inventoros

1. Make sure Inventoros is reachable from the internet over **https**, and that `APP_URL` in `.env` is that public address. Shopify delivers webhooks only to public https URLs.
2. Make sure the scheduler (cron) or a queue worker is running. Orders and stock pushes are queued jobs (see the main README).
3. Activate the plugin under **Admin > Plugins**, or run `php artisan plugin:activate shopify`.
4. Open **Shopify** in the sidebar (or **Settings > Shopify**). Enter the store address (`my-store.myshopify.com`), the client ID and the client secret, then choose **Save and test connection**.
5. Choose the **Shopify location** whose stock Inventoros controls. If you have several Inventoros warehouses, you can also choose the one Shopify orders ship from. Save again.
6. Choose **Register webhooks in Shopify**. This subscribes `orders/create` and `orders/cancelled` to this install's webhook address.
7. Choose **Sync all stock now** once, so every matched SKU in Shopify gets Inventoros's quantity. The summary lists SKUs that exist on only one side, so you can fix mismatches.

Only users with the **Manage Shopify** permission (`shopify.manage`, which administrators hold) can see the page. Orders and stock movements are recorded as the user who last saved the settings. That user must stay a member of the organization and keep the right to create orders.

## How it behaves

### Orders

- Lines are priced with Shopify's figures in the shop's currency: unit price, the line's share of discounts, and line taxes. Shipping is the order's shipping charge after shipping discounts. Shopify's own total is kept in the order's metadata (`metadata.shopify`), together with a link to the order in the Shopify admin.
- A paid order arrives as **processing**, and any other order as **pending**. If the organization requires order approval, the order waits for it like any other.
- Lines whose SKU matches nothing (gift cards, services, products you do not stock in Inventoros) are left out and listed in the order's notes. An order with no matching line is **skipped**.
- If Inventoros does not have enough stock, the import **fails** and nothing is booked. Fix the stock or the SKU, then choose **Retry** next to the order on the Shopify page.
- A webhook delivered twice, or a retry that meets a late webhook, never creates two orders.
- Cancelling in Shopify cancels the Inventoros order and returns its stock, unless the order has already shipped.

### Stock

- Inventoros is the source of truth. It takes stock out when an order is created, before it ships, so its on-hand figure is what Shopify calls *available*. Quantities are set, not adjusted, so a change made to stock in Shopify's admin is overwritten by the next push.
- Pushes are queued and deduplicated: a burst of movements on one product sends one update with the latest figure. Shopify outages and rate limits are retried with back-off.
- If an item is not yet stocked at the chosen Shopify location, the plugin activates it there and pushes again. Variants that Shopify does not track are reported and left alone.
- Negative stock in Inventoros is pushed to Shopify as 0.
- The quantity pushed is the product's total on-hand stock across all warehouses.

### Not handled (yet)

- Order edits (`orders/updated`), refunds that restock, and fulfilments made in Shopify. Handle those in Inventoros by hand.
- Creating products in Inventoros from the Shopify catalogue, or the other way round. Create products on both sides with the same SKUs.
- Several Shopify locations mapped to several Inventoros warehouses.
- Shopify retries a failed webhook up to 8 times over about 4 hours, then gives up and may remove the subscription. If Inventoros was offline for longer, choose **Register webhooks in Shopify** again (it only creates what is missing) and enter orders placed during the outage by hand.

## Data and removal

The plugin creates three tables: `shopify_connections` (credentials, encrypted with `APP_KEY`), `shopify_orders` (each Shopify order received and its result) and `shopify_inventory_items` (the Shopify item behind each SKU). **Disconnect** deletes the organization's connection. Deleting the plugin drops all three tables. Orders it imported stay, as ordinary orders.

## Development

- PHP: `Plugin.php` registers the hooks, the page and the routes. The classes are in `src/` (namespace `Inventoros\Plugins\Shopify`).
- UI: `ui/src/` is built to `dist/` with `npm run build:plugin:shopify` from the repository root. Bump `version` in `plugin.json` whenever the bundle changes.
- Tests: `php artisan test tests/Feature/Plugins/ShopifyPluginTest.php`. Shopify is faked with `Http::fake()`.
