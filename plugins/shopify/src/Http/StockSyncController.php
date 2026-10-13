<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Http;

use App\Models\Order\Order;
use App\Services\Inventory\VariantStockService;
use App\Models\Inventory\VariantStockOperation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inventoros\Plugins\Shopify\Jobs\ProcessStockOutbox;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\Models\ShopifyOrder;
use Inventoros\Plugins\Shopify\Models\ShopifyStockIssue;
use Inventoros\Plugins\Shopify\Models\ShopifyStockOperation;
use Inventoros\Plugins\Shopify\OrderImporter;
use Inventoros\Plugins\Shopify\ShopifyException;
use Inventoros\Plugins\Shopify\Stock\InboundReconciler;
use Inventoros\Plugins\Shopify\Stock\LevelBook;

/**
 * The operator's decisions on stock sync issues.
 */
class StockSyncController
{
    public function acceptShopify(Request $request, int $issue): RedirectResponse
    {
        [$connection, $record] = $this->issue($request, $issue);

        try {
            (new InboundReconciler($connection))->acceptShopify($record, $request->user()->id);
        } catch (ShopifyException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Inventoros now shows Shopify\'s figures for this item.');
    }

    public function keepInventoros(Request $request, int $issue): RedirectResponse
    {
        [$connection, $record] = $this->issue($request, $issue);

        try {
            (new InboundReconciler($connection))->keepInventoros($record, $request->user()->id);
        } catch (ShopifyException $e) {
            return back()->with('error', $e->getMessage());
        }

        ProcessStockOutbox::dispatch((int) $connection->organization_id);

        return back()->with('success', 'Inventoros\'s figure is being sent to Shopify. It shows as pending until Shopify confirms it.');
    }

    public function realignOrder(Request $request, int $issue, OrderImporter $importer): RedirectResponse
    {
        [$connection, $record] = $this->issue($request, $issue);

        $orders = $record->order_id !== null
            ? Order::withoutGlobalScopes()->whereKey($record->order_id)->get()
            : Order::withoutGlobalScopes()->where('organization_id', $connection->organization_id)->where('source', 'shopify')
                ->whereIn('status', ['pending', 'processing'])
                ->whereHas('items', fn ($q) => $q->where('product_variant_id', $record->product_variant_id))->get();

        $changed = 0;
        foreach ($orders as $order) {
            $plan = $importer->allocationPlan($connection, (string) $order->external_id);
            if ($plan !== null && app(VariantStockService::class)->realignOrder($order, $plan, [
                'source' => VariantStockOperation::SOURCE_EXTERNAL,
                'user_id' => $request->user()->id,
                'reason' => 'Re-aligned with Shopify by '.$request->user()->name,
            ])) {
                $changed++;
            }
        }

        $record->forceFill(['status' => 'resolved', 'resolution' => 'realigned', 'resolved_by' => $request->user()->id, 'resolved_at' => now()])->save();

        return back()->with('success', $changed === 0 ? 'The orders already matched Shopify.' : "Re-aligned {$changed} order(s) with Shopify.");
    }

    public function retry(Request $request, int $operation): RedirectResponse
    {
        $connection = $this->connection($request);
        $record = ShopifyStockOperation::query()->where('organization_id', $connection->organization_id)->findOrFail($operation);

        if (! in_array($record->status, [ShopifyStockOperation::FAILED, ShopifyStockOperation::CONFLICT], true)) {
            return back()->with('error', 'Only a failed operation can be retried.');
        }

        $record->forceFill(['status' => ShopifyStockOperation::PENDING, 'next_attempt_at' => null, 'attempts' => 0])->save();
        ShopifyStockIssue::query()->where('stock_operation_id', $record->id)->where('status', 'open')
            ->update(['status' => 'resolved', 'resolution' => 'retried', 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        (new LevelBook($connection))->refreshFor($record);
        ProcessStockOutbox::dispatch((int) $connection->organization_id);

        return back()->with('success', 'The operation is being sent to Shopify again.');
    }

    /**
     * @return array{0: ShopifyConnection, 1: ShopifyStockIssue}
     */
    private function issue(Request $request, int $issue): array
    {
        $connection = $this->connection($request);

        return [$connection, ShopifyStockIssue::query()->where('organization_id', $connection->organization_id)->where('status', 'open')->findOrFail($issue)];
    }

    private function connection(Request $request): ShopifyConnection
    {
        $connection = ShopifyConnection::forOrganization((int) $request->user()->organization_id);
        abort_if($connection === null, 404);

        return $connection;
    }
}
