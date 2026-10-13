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

**Variants without a SKU** are matched by their Shopify variant id instead. The catalogue import gives them the SKU `SHOPIFY-<variant id>` in Inventoros, and orders, stock and recovery recognise it. The same happens to a variant that shares its SKU with another Shopify variant already imported. An order line whose SKU no longer exists (changed after the order) is also matched through its variant, as long as that variant still exists in Shopify.

## Setting up

### 1. Create the app in Shopify

Shopify no longer lets you create custom apps in the store admin. Create one in the Dev Dashboard instead:

1. Open the [Shopify Dev Dashboard](https://dev.shopify.com/dashboard) with the account that owns the store, then choose **Create app** and **Start from Dev Dashboard**. Name it, for example, "Inventoros".
2. Under **Versions**, create a version and paste this list into its **Scopes** field, exactly as written (comma-separated, no spaces):

   ```text
   read_orders,read_products,read_inventory,write_inventory,read_locations,write_products,read_markets_home,read_assigned_fulfillment_orders,read_merchant_managed_fulfillment_orders,write_merchant_managed_fulfillment_orders,read_inventory_transfers,write_inventory_transfers,read_inventory_shipments,write_inventory_shipments_received_items
   ```

   - **Required:** the first five, for orders, stock and locations.
   - **Optional**, one feature each:
     - `write_products` publishes Inventoros products to Shopify;
     - `read_markets_home` imports the catalogue (Shopify needs it to read variant prices);
     - the two `fulfillment_orders` scopes let an order ship from the warehouse of its assigned location, when several locations are mapped.

   Then **release** the version. The Dev Dashboard's scope picker shows friendlier names, so typing the list is the reliable way. If Shopify asks to approve new scopes in the store admin, approve them.
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

**"Access denied" or "missing these access scopes".** The page names the scopes the store has not granted. Add them to the app's version, release it, approve them in the store if asked, then choose **Save and test connection** again. Saving always fetches a fresh token: a token issued before the change keeps the old scopes for up to 24 hours.

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
- **Payments.** What Shopify received is recorded as a payment on the order (method "other", reference "Shopify #1001"), so the payment status matches Shopify's:
  - a paid order is settled at its Inventoros total;
  - a partly paid order is paid up to what Shopify received;
  - money refunded in Shopify is recorded as a refund on the order.
- **Cancellation.** Cancelling in Shopify cancels the Inventoros order and returns its stock, unless goods have already shipped. A cancellation that arrives before the order itself stops the order from being imported.
- **Repeats and stale data.** Each order, fulfilment and refund is applied once, however often Shopify sends it. A webhook older than the state already applied is ignored.

### Order recovery

- **Every ten minutes**, Inventoros reads back from Shopify the orders that changed since the last run, and applies them as if their webhooks had arrived.
  - It only imports orders created after the store was connected, so connecting never pulls in old orders.
  - It also registers again any order webhook Shopify removed. Shopify removes a subscription after its deliveries fail for about four hours.
- An order with more than 50 lines (or a refund or fulfilment with more than 50) cannot be read back in one go. Recovery leaves it for you to enter by hand, rather than import it partly. Webhooks carry whole orders and are not limited.
- **Orders already in Shopify** are not imported when you connect. To bring them in, use **Recover orders** with a start date.
  - Orders placed before Inventoros took its stock from Shopify are imported **as history**: with their lines, shipments and status, but without taking stock (and without announcing a new sale to webhooks and other plugins).
  - Inventoros takes its stock from Shopify when you connect, or when you import the catalogue with stock.
  - Refunds from that time do not create returns either, because their restock is already in those figures.
- **Recover orders** on the Shopify page imports the missing orders created since a date you choose, within the 60 days of orders Shopify gives apps (or any date, with the optional `read_all_orders` scope).

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
  - Prices, barcodes, unit costs and descriptions come along. Variants without a SKU are imported as `SHOPIFY-<variant id>`. A product with more than 100 variants is reported, for you to create by hand.
  - Importing again completes products imported earlier: their missing variants are added, with opening stock.
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


## Stock per warehouse, both ways (2.0)

For a store with more than one location whose stock lives in Inventoros per variant and warehouse (see *Stock by warehouse* in Inventoros). In this mode the 1.x push of available totals is off for good: it would pour the total of every warehouse into one location.

- **Per location, per variant.** Each mapped Shopify location shows exactly its warehouse's figures: on hand, committed, reserved, damaged, quality control, incoming. Units committed to orders taken outside Shopify are held there as *reserved*; returns waiting for inspection as *quality control*.
- **Inventoros to Shopify.** Every operation made in Inventoros becomes tracked Shopify mutations (the outbox, `shopify_stock_operations`): `inventoryAdjustQuantities`, `inventoryMoveQuantities` (state changes, so on hand never jumps) or `inventorySetQuantities` (counts), each with `changeFromQuantity` (the figure both sides last agreed on) and a fixed `@idempotent` key reused on every retry. A movement whose base figure moved meanwhile is re-based and sent again; a count is refused and becomes a conflict for the operator. Only a confirmed operation counts as synced; until then the level shows *pending*.
- **Shopify to Inventoros.** `inventory_levels/update` webhooks (and a nightly read of every level) make Inventoros read the level again and compare what each side changed since they last agreed. A change made in Shopify is applied in Inventoros as an *external* operation, never sent back; the echo of our own change is recognised. A difference in committed units waits for the orders to be processed; if it persists it becomes an issue.
- **Webhooks** are stored before processing (`shopify_webhook_events`), deduplicated on `X-Shopify-Webhook-Id`, checked for the shop domain, retried, and processed in any order (state is always re-read).
- **Orders** commit their units where Shopify assigned them (its fulfilment orders) and stay aligned when Shopify moves them. An order split across locations is consolidated in the main warehouse before its single shipment: the units leave the origin, the fulfilment order is moved, the units arrive, in an order that never shows a pair for sale twice.
- **Returns** restocked by a Shopify refund are corrected to *quality control* in the returns warehouse, with their owner; they go back on sale after inspection.
- **Issues** (conflicts, failed operations, unexplained differences) are listed on the *Stock by warehouse* screen with their decisions: use Shopify's figures, send Inventoros's, re-align the order, retry.

### Commands

```text
php artisan shopify:stock-setup [--apply]     dry run (default) or set up; writes a comparison CSV, never changes Shopify
php artisan shopify:stock-activate            turn on two-way sync after the comparison is approved
php artisan shopify:stock-pause               stop sending to Shopify (webhooks are still recorded)
php artisan shopify:stock-reconcile           read and check every level now
php artisan shopify:routing-check             how Shopify assigned the open orders (changes nothing)
php artisan shopify:transfer-plan T0001       plan for a transfer to reach the main warehouse (changes nothing)
php artisan shopify:transfer-approve ID ...   carry out an approved plan step
php artisan shopify:purge-legacy-stock-jobs   remove 1.x stock pushes waiting in the database queue
```

Order routing (location priority, minimising split shipments) is a store setting the Admin API does not expose: set it in Shopify (main location first) and check its effect with `shopify:routing-check`.
