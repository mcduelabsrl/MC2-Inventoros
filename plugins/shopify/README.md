# Shopify plugin

Connects one Shopify store to each Inventoros organization.

- **Orders, from Shopify to Inventoros.**
  - Every new Shopify order becomes an Inventoros order (source `shopify`) and takes its items out of stock.
  - Later changes in Shopify follow it:
    - order edits change the order's lines;
    - fulfilments become shipments;
    - refunds that restock returned goods become returns;
    - cancelling the order cancels it and puts the stock back.
  - Every ten minutes Inventoros reads back the orders Shopify changed, so nothing is lost when a webhook is.
- **Stock, from Inventoros to Shopify.**
  - Every change of on-hand stock in Inventoros sets the item's **available** quantity at each mapped Shopify location. This covers adjustments, orders, receipts, transfers, returns, audits and imports, from any screen or API.
  - Each location can be fed by its own warehouse.
- **Catalogue, both ways.**
  - Import Shopify's products into Inventoros (with options, variants and opening stock).
  - Publish Inventoros products that Shopify does not have yet.

Products are matched by **SKU**: a Shopify variant's SKU must equal an Inventoros variant SKU, or the SKU of an Inventoros product sold without variants.

## Setting up

### 1. Create the app in Shopify

Shopify no longer lets you create custom apps in the store admin. Create one in the Dev Dashboard instead:

1. Open the [Shopify Dev Dashboard](https://dev.shopify.com/dashboard) with the account that owns the store, then choose **Create app** and **Start from Dev Dashboard**. Name it, for example, "Inventoros".
2. Under **Versions**, create a version with these **access scopes** and release it:
   `read_orders`, `read_products`, `write_products`, `read_inventory`, `write_inventory`, `read_locations`, `read_markets_home`, `read_assigned_fulfillment_orders`, `read_merchant_managed_fulfillment_orders`
3. Under **API access**, allow **protected customer data** with the name, email and address fields. Without them, Shopify blanks the customer's details and orders arrive as "Shopify customer".
4. **Install** the app on your store. The plugin authenticates with Shopify's client credentials grant, which only works for stores in the same Shopify organization as the app.
5. Under **Settings**, copy the **Client ID** and **Client secret**.

### 2. Connect Inventoros

1. Make sure Inventoros is reachable from the internet over **https**, and that `APP_URL` in `.env` is that public address. Shopify delivers webhooks only to public https URLs.
2. Make sure the scheduler (cron) runs, and ideally a queue worker. Orders, stock pushes and recovery are queued or scheduled jobs (see the main README).
3. Activate the plugin under **Admin > Plugins**, or run `php artisan plugin:activate shopify`.
4. Open **Shopify** in the sidebar (or **Settings > Shopify**). Enter the store address (`my-store.myshopify.com`), the client ID and the client secret, then choose **Save and test connection**.
5. Under **Shopify locations**, choose what each location shows:
   - **All warehouses (total stock)**: the simple setup, for a store with one location.
   - **One Inventoros warehouse**: with several locations, each gets its own warehouse.
   - **Not synced**: you manage that location's stock in Shopify.

   Also choose the **default warehouse** for orders whose location is not mapped. Save again.
6. Choose **Register webhooks in Shopify**. This subscribes `orders/create`, `orders/updated` and `orders/cancelled` to this install's webhook address.
7. Bring the two sides together, once:
   - If Inventoros is empty, choose **Import products** under **Catalogue**, with "also import Shopify's available stock".
   - If Shopify is empty, choose **Publish products** (as drafts, so you can review them).
   - If both already have products with the same SKUs, choose **Sync all stock now**. The summary lists SKUs that exist on only one side.

Only users with the **Manage Shopify** permission (`shopify.manage`, which administrators hold) can see the page. Orders, shipments, returns and stock movements are recorded as the user who last saved the settings. That user must stay a member of the organization and keep the right to create orders.

## How it behaves

### Orders

Each order webhook carries the whole order. The plugin compares that order with what it already applied, and applies the difference through the same services the Inventoros screens use.

- **New order.**
  - Lines are priced with Shopify's figures in the shop's currency: unit price, the line's share of discounts, and line taxes. Shipping is the order's shipping charge after shipping discounts.
  - Shopify's own total is kept in the order's metadata (`metadata.shopify`), with a link to the order in the Shopify admin.
  - A paid order arrives as **processing**, and any other order as **pending**. If the organization requires order approval, the order waits for it like any other.
  - With several locations mapped, the order ships from (and takes stock from) the warehouse of the location Shopify assigned it to.
- **Lines with no matching SKU** (gift cards, services, products you do not stock in Inventoros) are left out and listed in the order's notes. An order with no matching line is **skipped**.
- **Not enough stock.** The import **fails** and nothing is booked. Fix the stock or the SKU, then choose **Retry** next to the order on the Shopify page.
- **Edits.** Adding, removing or changing the quantity of items in Shopify changes the Inventoros order's lines, and the stock follows. Refunds that cancel items before they ship count as edits. Lines cannot change once the order has shipments or returns: the change is then reported next to the order, for you to handle by hand.
- **Fulfilments.** Each one becomes a shipment, with Shopify's carrier and tracking number, marked shipped. When Shopify reports it delivered, the shipment becomes delivered too, and the order follows. The customer is not emailed again: Shopify already did that.
- **Refunds.**
  - A refund that **restocks returned items** becomes an Inventoros return, which is approved, received into stock and completed.
  - A refund that does not restock changes nothing in stock.
- **Cancellation.** Cancelling in Shopify cancels the Inventoros order and returns its stock, unless goods have already shipped. A cancellation that arrives before the order itself stops the order from being imported.
- **Repeats and stale data.** Each order, fulfilment and refund is applied once, however often Shopify sends it. A webhook older than the state already applied is ignored.

### Order recovery

- **Every ten minutes**, Inventoros reads back from Shopify the orders that changed since the last run, and applies them as if their webhooks had arrived.
  - It only imports orders created after the store was connected, so connecting never pulls in old orders.
  - It also registers again any order webhook Shopify removed. Shopify removes a subscription after its deliveries fail for about four hours.
- **Recover orders** on the Shopify page imports the missing orders created since a date you choose, within the 60 days of orders Shopify gives apps.

### Stock

- **Inventoros is the source of truth.**
  - It takes stock out when an order is created, before it ships, so its on-hand figure is what Shopify calls *available*.
  - Quantities are set, not adjusted, so a change made to stock in Shopify's admin is overwritten by the next push.
- **What each location gets.**
  - A location fed by a warehouse gets the product's stock in that warehouse's bins.
  - A location fed by all warehouses gets the total.
  - Variants have no per-warehouse breakdown in Inventoros, so a variant's whole stock goes to the first mapped location, and other locations are left alone for it.
- **How pushes run.**
  - Pushes are queued and deduplicated: a burst of movements on one product sends one update with the latest figures.
  - Shopify outages and rate limits are retried with back-off.
  - A full stock sync also runs every night.
- **Special cases.**
  - If an item is not yet stocked at a mapped location, the plugin activates it there and pushes again.
  - Variants that Shopify does not track are reported and left alone.
  - Negative stock in Inventoros is pushed to Shopify as 0.

### Catalogue

Both directions only create products that are missing on the other side. A product with any SKU already on the other side is skipped, and the summary lists it. Nothing that exists is ever changed or deleted.

- **Import from Shopify.**
  - A single-variant Shopify product becomes a simple Inventoros product with the variant's SKU.
  - A product with options becomes an Inventoros product with the same options and variants. Its own SKU is the Shopify handle in capitals.
  - Prices, barcodes, unit costs and descriptions come along. Variants without a SKU are left out.
  - With "also import stock", Shopify's available quantities at the mapped locations are booked as opening stock, into a bin of each mapped warehouse. No stock is pushed back to Shopify during the import, since Shopify already has those figures.
- **Publish to Shopify.**
  - Each active Inventoros product whose SKUs Shopify does not have is created in Shopify, as a draft or as active.
  - It gets its options, variants, prices and barcodes, inventory tracking, and its stock at each mapped location.

## Data and removal

The plugin creates four tables:

- `shopify_connections`: the connection settings, with credentials encrypted with `APP_KEY`;
- `shopify_orders`: each Shopify order received and its result;
- `shopify_inventory_items`: the Shopify item behind each SKU;
- `shopify_links`: the fulfilments and refunds already applied.

**Disconnect** deletes the organization's connection. Deleting the plugin drops all four tables. Orders, shipments, returns and products it created stay, as ordinary records.

Updating from 1.0.0: copy the new files over the plugin. The new columns are added on the next request (or deactivate and activate the plugin). Existing connections keep their location, fed by the total stock.

## Development

- PHP: `Plugin.php` registers the hooks, the page, the routes and the schedule. The classes are in `src/` (namespace `Inventoros\Plugins\Shopify`):
  - `OrderImporter` reconciles orders;
  - `OrderPayload` reads GraphQL orders into the webhook shape;
  - `OrderRecovery` is the scheduled backstop;
  - `InventorySync` and `StockLevels` push stock;
  - `CatalogueSync` copies the catalogue;
  - `ShopifyClient` talks to the Admin GraphQL API.
- UI: `ui/src/` is built to `dist/` with `npm run build:plugin:shopify` from the repository root. Bump `version` in `plugin.json` whenever the bundle changes.
- Tests: `php artisan test tests/Feature/Plugins`. Shopify is faked with `Http::fake()` (see `tests/Feature/Plugins/Concerns/InteractsWithShopify.php`).
