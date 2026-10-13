<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Stock;

use App\Enums\OrderStatus;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Order\Order;
use App\Services\Inventory\VariantStockService;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\OrderImporter;

/**
 * Checks, on the store's real open orders, whether Shopify assigns them the
 * way MC2-first wants: everything from the main warehouse when it has the
 * pairs, the second warehouse only for what is missing, one shipment from
 * the main warehouse after consolidation.
 *
 * Shopify's order routing (location priority, "minimize split shipments")
 * is a store setting the API cannot read: this report shows its effect on
 * concrete orders and what to do for each. It changes nothing.
 */
class RoutingCheck
{
    public function __construct(private readonly ShopifyConnection $connection) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function run(): array
    {
        $stock = app(VariantStockService::class);
        $importer = app(OrderImporter::class);
        $main = $stock->defaultWarehouseId((int) $this->connection->organization_id);
        $rows = [];

        $orders = Order::withoutGlobalScopes()
            ->where('organization_id', $this->connection->organization_id)
            ->where('source', 'shopify')
            ->whereIn('status', [OrderStatus::PENDING->value, OrderStatus::PROCESSING->value])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $plan = $importer->allocationPlan($this->connection, (string) $order->external_id);
            if ($plan === null) {
                $rows[] = ['order' => $order->external_reference, 'verdict' => 'unknown', 'advice' => 'Fulfilment orders are not readable (scopes).'];

                continue;
            }

            $warehouses = [];
            $atSecondButMainHasStock = [];
            foreach ($plan as $variantId => $byWarehouse) {
                foreach ($byWarehouse as $warehouseId => $qty) {
                    $warehouses[$warehouseId] = true;
                    if ($warehouseId !== $main) {
                        $freeAtMain = $this->freeAt((int) $variantId, (int) $main);
                        if ($freeAtMain >= $qty) {
                            $atSecondButMainHasStock[] = $variantId;
                        }
                    }
                }
            }

            $consolidation = $main !== null ? $stock->consolidationPlan($order, $main) : [];

            [$verdict, $advice] = match (true) {
                $warehouses === [] => ['nothing_open', 'Nothing left to ship in Shopify.'],
                array_keys($warehouses) === [$main] => ['ok', 'Assigned to the main warehouse only.'],
                $atSecondButMainHasStock !== [] => ['check_routing', 'Shopify assigned pairs to the second warehouse although the main warehouse has them free: check the location priority in Shopify (main warehouse first). Either move the fulfilment to the main warehouse (if those pairs are really there) or consolidate.'],
                count($warehouses) > 1 => ['split', 'Pairs in both warehouses: consolidate at the main warehouse before shipping (one shipment).'],
                default => ['second_only', 'Assigned to the second warehouse: the pairs are only there. Consolidate at the main warehouse before shipping.'],
            };

            $rows[] = [
                'order' => $order->external_reference,
                'order_id' => $order->id,
                'verdict' => $verdict,
                'advice' => $advice,
                'shopify_assignment' => $plan,
                'to_consolidate' => array_map(fn ($p) => ['sku' => $p['sku'], 'from_warehouse_id' => $p['warehouse_id'], 'quantity' => $p['quantity']], $consolidation),
            ];
        }

        return $rows;
    }

    private function freeAt(int $variantId, int $warehouseId): int
    {
        $levels = VariantStockLevel::where('product_variant_id', $variantId)->where('warehouse_id', $warehouseId)->get();

        return (int) $levels->sum(fn ($l) => $l->available);
    }
}
