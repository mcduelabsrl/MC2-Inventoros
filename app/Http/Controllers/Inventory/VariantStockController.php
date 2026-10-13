<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockOwner;
use App\Models\Inventory\VariantStockLevel;
use App\Models\Inventory\VariantStockMovement;
use App\Models\Inventory\VariantStockTransfer;
use App\Models\Order\Order;
use App\Models\Order\OrderItemAllocation;
use App\Models\Warehouse;
use App\Services\Inventory\VariantStockService;
use App\Services\Inventory\VariantTransferService;
use App\Support\VariantWarehouseStockSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One screen for variant stock per warehouse: model, colour, size, SKU,
 * barcode, quantity in each warehouse and in total, the state of the units
 * (on hand, available, committed, unavailable, incoming), who owns them,
 * the sync status with sales channels and the anomalies; and the actions
 * on them (adjustment or count, immediate transfer, inspection of returns,
 * owner assignment, state change), plus the history of each variant.
 */
class VariantStockController extends Controller
{
    public function __construct(
        private readonly VariantStockService $stock,
        private readonly VariantTransferService $transfers,
    ) {}

    public function index(Request $request): Response
    {
        $orgId = (int) $request->user()->organization_id;
        $warehouses = $this->warehouses($orgId);
        $owners = StockOwner::forOrganization($orgId)->where('is_active', true)->orderBy('id')->get(['id', 'name', 'code']);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer'],
            'owner' => ['nullable', 'string', 'max:20'],
            'anomalies' => ['nullable', 'boolean'],
            'quality_control' => ['nullable', 'boolean'],
        ]);

        $query = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.organization_id', $orgId)
            ->whereNull('products.deleted_at')
            ->with(['product' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name', 'sku')])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $q->where(fn ($q) => $q->where('product_variants.sku', 'like', $like)
                    ->orWhere('product_variants.barcode', 'like', $like)
                    ->orWhere('product_variants.title', 'like', $like)
                    ->orWhere('products.name', 'like', $like));
            })
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->whereExists(fn ($s) => $s->from('variant_stock_levels')
                ->whereColumn('variant_stock_levels.product_variant_id', 'product_variants.id')
                ->where('variant_stock_levels.warehouse_id', $warehouseId)
                ->where(fn ($w) => $w->where('on_hand', '!=', 0)->orWhere('committed', '!=', 0)->orWhere('incoming', '!=', 0))))
            ->when($filters['owner'] ?? null, function ($q, $owner) {
                $q->whereExists(fn ($s) => $s->from('variant_stock_levels')
                    ->whereColumn('variant_stock_levels.product_variant_id', 'product_variants.id')
                    ->where('owner_key', $owner === 'unassigned' ? 0 : (int) $owner)
                    ->where('on_hand', '!=', 0));
            })
            ->when($filters['quality_control'] ?? false, fn ($q) => $q->whereExists(fn ($s) => $s->from('variant_stock_levels')
                ->whereColumn('variant_stock_levels.product_variant_id', 'product_variants.id')
                ->where('quality_control', '>', 0)))
            ->when($filters['anomalies'] ?? false, fn ($q) => $q->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->from('variant_stock_levels')
                    ->whereColumn('variant_stock_levels.product_variant_id', 'product_variants.id')
                    ->where(fn ($w) => $w->where('on_hand', '<', 0)
                        ->orWhereRaw(VariantStockLevel::availableSql().' < 0')))
                ->orWhereExists(fn ($s) => $s->from('order_item_allocations')
                    ->whereColumn('order_item_allocations.product_variant_id', 'product_variants.id')
                    ->where('shortfall_quantity', '>', 0)
                    ->whereRaw('quantity > fulfilled_quantity + released_quantity'))))
            ->orderBy('products.name')
            ->orderBy('product_variants.position')
            ->orderBy('product_variants.id');

        $variants = $query->paginate(25)->withQueryString();
        $ids = collect($variants->items())->pluck('id')->all();

        $levels = VariantStockLevel::whereIn('product_variant_id', $ids)->get()->groupBy('product_variant_id');
        $channel = (array) apply_filters('variant_stock_channel_status', [], $orgId, $ids);
        $shortfalls = OrderItemAllocation::whereIn('product_variant_id', $ids)
            ->where('shortfall_quantity', '>', 0)
            ->whereRaw('quantity > fulfilled_quantity + released_quantity')
            ->pluck('product_variant_id')->unique()->flip();

        $variants->through(fn (ProductVariant $v) => $this->row($v, $levels->get($v->id, collect()), $warehouses, $channel[$v->id] ?? [], isset($shortfalls[$v->id])));

        return Inertia::render('StockByWarehouse/Index', [
            'enabled' => VariantWarehouseStockSettings::enabledFor($orgId),
            'variants' => $variants,
            'warehouses' => $warehouses->map(fn (Warehouse $w) => $w->only(['id', 'name', 'code', 'is_default']))->values(),
            'owners' => $owners,
            'filters' => $filters,
            'issues' => array_values((array) apply_filters('variant_stock_channel_issues', [], $orgId, null)),
            'transfers' => VariantStockTransfer::where('organization_id', $orgId)
                ->with(['lines.variant:id,sku', 'fromWarehouse:id,name', 'toWarehouse:id,name', 'user:id,name', 'order:id,order_number,external_reference'])
                ->latest('id')->limit(15)->get(),
        ]);
    }

    /**
     * One variant: rows by warehouse and owner, open commitments, history.
     */
    public function show(Request $request, ProductVariant $variant): JsonResponse
    {
        $this->authorizeVariant($request, $variant);
        $orgId = (int) $request->user()->organization_id;

        $history = VariantStockMovement::where('product_variant_id', $variant->id)
            ->with(['operation:id,type,source,mode,reason,notes,user_id,order_id,transfer_id,return_order_id,created_at,meta', 'operation.user:id,name', 'warehouse:id,name', 'owner:id,name'])
            ->latest('id')->limit(200)->get()
            ->map(fn (VariantStockMovement $m) => [
                'id' => $m->id,
                'at' => $m->created_at?->toIso8601String(),
                'warehouse' => $m->warehouse?->name,
                'owner' => $m->owner?->name,
                'bucket' => $m->bucket,
                'delta' => $m->delta,
                'before' => $m->before,
                'after' => $m->after,
                'type' => $m->operation?->type,
                'source' => $m->operation?->source,
                'reason' => $m->operation?->reason,
                'user' => $m->operation?->user?->name,
                'order_id' => $m->operation?->order_id,
                'transfer_id' => $m->operation?->transfer_id,
            ]);

        $allocations = OrderItemAllocation::where('product_variant_id', $variant->id)
            ->whereRaw('quantity > fulfilled_quantity + released_quantity')
            ->with(['warehouse:id,name', 'owner:id,name'])
            ->get()
            ->map(fn (OrderItemAllocation $a) => [
                'order_id' => $a->order_id,
                'order' => Order::withoutGlobalScopes()->whereKey($a->order_id)->first(['order_number', 'external_reference']),
                'warehouse' => $a->warehouse?->name,
                'owner' => $a->owner?->name,
                'open' => $a->open_quantity,
                'shortfall' => $a->shortfall_quantity,
            ]);

        return response()->json([
            'levels' => VariantStockLevel::where('product_variant_id', $variant->id)
                ->with(['warehouse:id,name', 'owner:id,name'])->get(),
            'allocations' => $allocations,
            'history' => $history,
            'issues' => array_values((array) apply_filters('variant_stock_channel_issues', [], $orgId, $variant->id)),
        ]);
    }

    /**
     * A movement (+/- units) or a count (the units really there).
     */
    public function adjust(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'mode' => ['required', Rule::in(['delta', 'count'])],
            'quantity' => ['required', 'integer', 'between:-100000,100000'],
            'expected_on_hand' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->attempt(function () use ($variant, $data, $request) {
            $context = ['user_id' => $request->user()->id, 'reason' => $data['reason']];
            $owner = $this->owner($request, $data['owner_id'] ?? null);
            $warehouse = $this->warehouse($request, (int) $data['warehouse_id']);

            if ($data['mode'] === 'count') {
                $this->stock->setCount($variant, $warehouse, $owner, (int) $data['quantity'], $context + ['expected_on_hand' => $data['expected_on_hand'] ?? null]);
            } else {
                $this->stock->adjust($variant, $warehouse, $owner, (int) $data['quantity'], $context);
            }

            return __('Stock updated.');
        });
    }

    /**
     * Step 1 of a transfer: the units are set aside at the origin. They
     * leave with step 2 (ship) and enter the destination with step 3
     * (receive).
     */
    public function transfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_variant_id' => ['required', 'integer'],
            'lines.*.owner_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        return $this->attempt(function () use ($request, $data) {
            foreach ($data['lines'] as $line) {
                $this->authorizeVariant($request, ProductVariant::findOrFail($line['product_variant_id']));
                $this->owner($request, $line['owner_id'] ?? null);
            }
            $transfer = $this->transfers->prepare(
                (int) $request->user()->organization_id,
                $this->warehouse($request, (int) $data['from_warehouse_id']),
                $this->warehouse($request, (int) $data['to_warehouse_id']),
                $data['lines'],
                $request->user(),
                $data['notes'] ?? null,
            );

            return __('Transfer :reference prepared: the units are set aside until it is shipped.', ['reference' => $transfer->reference]);
        });
    }

    /**
     * Step 2: the units leave the origin and are incoming at the destination.
     */
    public function shipTransfer(Request $request, VariantStockTransfer $transfer): RedirectResponse
    {
        $this->authorizeTransfer($request, $transfer);
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->attempt(function () use ($request, $transfer, $data) {
            $this->transfers->ship($transfer, $request->user(), $data['carrier'] ?? null, $data['tracking_number'] ?? null);

            return __('Transfer :reference shipped.', ['reference' => $transfer->reference]);
        });
    }

    /**
     * Step 3: per line, units accepted and refused. Partial receipts are
     * allowed.
     */
    public function receiveTransfer(Request $request, VariantStockTransfer $transfer): RedirectResponse
    {
        $this->authorizeTransfer($request, $transfer);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.accepted' => ['required', 'integer', 'min:0'],
            'lines.*.rejected' => ['required', 'integer', 'min:0'],
        ]);

        return $this->attempt(function () use ($request, $transfer, $data) {
            $receipt = [];
            foreach ($data['lines'] as $line) {
                $receipt[(int) $line['line_id']] = ['accepted' => (int) $line['accepted'], 'rejected' => (int) $line['rejected']];
            }
            $done = $this->transfers->receive($transfer, $receipt, $request->user());

            return $done->status === VariantStockTransfer::COMPLETED
                ? __('Transfer :reference received.', ['reference' => $transfer->reference])
                : __('Transfer :reference partly received.', ['reference' => $transfer->reference]);
        });
    }

    /**
     * Cancel a transfer not shipped yet: the units are free again.
     */
    public function cancelTransfer(Request $request, VariantStockTransfer $transfer): RedirectResponse
    {
        $this->authorizeTransfer($request, $transfer);

        return $this->attempt(function () use ($request, $transfer) {
            $this->transfers->cancel($transfer, $request->user());

            return __('Transfer :reference cancelled.', ['reference' => $transfer->reference]);
        });
    }

    /**
     * Inspection of returned units: back on sale, or kept unavailable.
     */
    public function inspect(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', Rule::in(['available', 'damaged', 'reserved'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(function () use ($request, $variant, $data) {
            $this->stock->inspect($variant, $this->warehouse($request, (int) $data['warehouse_id']), $this->owner($request, $data['owner_id'] ?? null),
                (int) $data['quantity'], $data['outcome'], $data['reason'] ?? null, ['user_id' => $request->user()->id]);

            return __('Inspection recorded.');
        });
    }

    /**
     * Mark units unavailable (damaged, reserved) or available again.
     */
    public function state(Request $request, ProductVariant $variant): RedirectResponse
    {
        $this->authorizeVariant($request, $variant);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'owner_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'from' => ['required', Rule::in(['available', 'damaged', 'reserved', 'quality_control'])],
            'to' => ['required', Rule::in(['available', 'damaged', 'reserved', 'quality_control']), 'different:from'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->attempt(function () use ($request, $variant, $data) {
            $this->stock->changeState($variant, $this->warehouse($request, (int) $data['warehouse_id']), $this->owner($request, $data['owner_id'] ?? null),
                $data['from'], $data['to'], (int) $data['quantity'], ['user_id' => $request->user()->id, 'reason' => $data['reason'], 'to_state' => $data['to']]);

            return __('Stock state updated.');
        });
    }

    /**
     * Assign units to an owner (also for several variants at once, each
     * line with its own verifiable quantity).
     */
    public function owners(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_variant_id' => ['required', 'integer'],
            'lines.*.warehouse_id' => ['required', 'integer'],
            'lines.*.from_owner_id' => ['nullable', 'integer'],
            'lines.*.to_owner_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return $this->attempt(function () use ($request, $data) {
            DB::transaction(function () use ($request, $data) {
                foreach ($data['lines'] as $line) {
                    $variant = ProductVariant::findOrFail($line['product_variant_id']);
                    $this->authorizeVariant($request, $variant);
                    $this->stock->changeOwner($variant, $this->warehouse($request, (int) $line['warehouse_id']),
                        $this->owner($request, $line['from_owner_id'] ?? null), $this->owner($request, $line['to_owner_id'] ?? null),
                        (int) $line['quantity'], ['user_id' => $request->user()->id, 'reason' => 'Owner assigned']);
                }
            });

            return trans_choice('{1} Owner assigned.|[2,*] Owners assigned for :count lines.', count($data['lines']), ['count' => count($data['lines'])]);
        });
    }

    /**
     * Gather an order's committed units in the shipping warehouse.
     */
    public function consolidate(Request $request, Order $order): RedirectResponse
    {
        abort_unless((int) $order->organization_id === (int) $request->user()->organization_id, 404);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'allocation_ids' => ['nullable', 'array'],
            'allocation_ids.*' => ['integer'],
        ]);

        return $this->attempt(function () use ($request, $order, $data) {
            $target = isset($data['warehouse_id'])
                ? $this->warehouse($request, (int) $data['warehouse_id'])
                : $this->stock->defaultWarehouseId((int) $order->organization_id);

            $done = $this->transfers->consolidate($order, (int) $target, $request->user(), $data['allocation_ids'] ?? null);

            return $done === []
                ? __('Everything is already in the shipping warehouse.')
                : __('Moved to the shipping warehouse: :references.', ['references' => collect($done)->pluck('reference')->implode(', ')]);
        });
    }

    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, VariantStockLevel>  $levels
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  array<int, array<string, mixed>>  $channel
     * @return array<string, mixed>
     */
    private function row(ProductVariant $variant, Collection $levels, Collection $warehouses, array $channel, bool $shortfall): array
    {
        $byWarehouse = [];
        $total = array_fill_keys(['on_hand', 'available', 'committed', 'unavailable', 'quality_control', 'damaged', 'reserved', 'incoming'], 0);
        $anomalies = [];

        foreach ($warehouses as $warehouse) {
            $rows = $levels->where('warehouse_id', $warehouse->id);
            $sum = [
                'on_hand' => (int) $rows->sum('on_hand'),
                'committed' => (int) $rows->sum('committed'),
                'quality_control' => (int) $rows->sum('quality_control'),
                'damaged' => (int) $rows->sum('damaged'),
                'reserved' => (int) $rows->sum('reserved'),
                'incoming' => (int) $rows->sum('incoming'),
            ];
            $sum['unavailable'] = $sum['quality_control'] + $sum['damaged'] + $sum['reserved'];
            $sum['available'] = $sum['on_hand'] - $sum['committed'] - $sum['unavailable'];

            $sum['owners'] = $rows->filter(fn ($l) => $l->on_hand !== 0 || $l->committed !== 0 || $l->unavailable !== 0)
                ->map(fn (VariantStockLevel $l) => [
                    'owner_id' => $l->stock_owner_id,
                    'on_hand' => $l->on_hand,
                    'available' => $l->available,
                    'committed' => $l->committed,
                    'unavailable' => $l->unavailable,
                ])->values();
            $sum['channel'] = $channel[$warehouse->id] ?? null;

            foreach ($total as $k => $v) {
                $total[$k] = $v + $sum[$k];
            }

            if ($sum['on_hand'] < 0 || $sum['available'] < 0) {
                $anomalies[] = ['kind' => 'negative', 'warehouse_id' => $warehouse->id];
            }
            // An owner's split no longer explains its units: more units
            // committed or unsellable than it has, or a negative count.
            if ($rows->contains(fn (VariantStockLevel $l) => $l->on_hand < 0 || ($l->stock_owner_id !== null && $l->committed + $l->unavailable > $l->on_hand))) {
                $anomalies[] = ['kind' => 'ownership', 'warehouse_id' => $warehouse->id];
            }
            if (in_array($sum['channel']['status'] ?? null, ['error', 'conflict'], true)) {
                $anomalies[] = ['kind' => 'sync', 'warehouse_id' => $warehouse->id];
            }

            $byWarehouse[$warehouse->id] = $sum;
        }

        if ($shortfall) {
            $anomalies[] = ['kind' => 'shortfall', 'warehouse_id' => null];
        }

        $options = (array) ($variant->option_values ?? []);

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'model' => $variant->product?->name,
            'options' => $options,
            'title' => $variant->title,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'warehouses' => $byWarehouse,
            'total' => $total,
            'unassigned' => (int) $levels->whereNull('stock_owner_id')->sum('on_hand'),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * @return Collection<int, Warehouse>
     */
    private function warehouses(int $orgId): Collection
    {
        return $this->stock->warehousesInPriority($orgId);
    }

    private function authorizeVariant(Request $request, ProductVariant $variant): void
    {
        abort_unless((int) $variant->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function authorizeTransfer(Request $request, VariantStockTransfer $transfer): void
    {
        abort_unless((int) $transfer->organization_id === (int) $request->user()->organization_id, 404);
    }

    private function warehouse(Request $request, int $warehouseId): int
    {
        $ok = Warehouse::withoutGlobalScopes()->whereKey($warehouseId)->where('organization_id', $request->user()->organization_id)->exists();
        abort_unless($ok, 422, 'Unknown warehouse.');

        return $warehouseId;
    }

    private function owner(Request $request, mixed $ownerId): ?int
    {
        if ($ownerId === null || $ownerId === '' || $ownerId === 'unassigned') {
            return null;
        }
        $ok = StockOwner::whereKey((int) $ownerId)->where('organization_id', $request->user()->organization_id)->exists();
        abort_unless($ok, 422, 'Unknown owner.');

        return (int) $ownerId;
    }

    private function attempt(\Closure $action): RedirectResponse
    {
        try {
            return back()->with('success', $action());
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
