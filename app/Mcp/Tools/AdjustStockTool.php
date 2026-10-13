<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\InsufficientStockException;
use App\Mcp\Concerns\AuthenticatesMcpRequest;
use App\Models\Inventory\Product;
use App\Models\Inventory\ProductLocation;
use App\Models\Inventory\ProductVariant;
use App\Models\Inventory\StockAdjustmentRequest;
use App\Models\Inventory\StockOwner;
use App\Models\Warehouse;
use App\Services\ApprovalService;
use App\Services\Inventory\VariantStockService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdjustStockTool extends Tool
{
    use AuthenticatesMcpRequest;

    protected string $name = 'adjust_stock';

    protected string $description = 'Adjust the on-hand stock of a product, or of one of its variants (product_variant_id) in one warehouse (warehouse_id, optional owner owner_id), by a positive or negative integer, recording the reason. Products sold by variant need product_variant_id; with stock kept per warehouse, a variant without warehouse_id goes to the default warehouse. WARNING: this writes to inventory. Always confirm the product id and quantity with the user before invoking. Use type "manual" for plain corrections, "count" for cycle-count adjustments, "damage" for write-offs, "return" for customer returns, "transfer" for inter-warehouse moves. When the organization requires approval for this adjustment, nothing changes yet: the result has status "pending_approval" and stock moves once an approver approves it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->required()->description('Product id within the caller\'s organization.'),
            'quantity' => $schema->integer()->required()->description('Signed delta. Positive adds stock, negative removes it.'),
            'type' => $schema->string()->required()->enum(['manual', 'count', 'damage', 'return', 'transfer'])->description('Reason category.'),
            'reason' => $schema->string()->description('Short human label (e.g. "Cycle count Q2", max 255 chars).'),
            'notes' => $schema->string()->description('Free-text notes for the audit log.'),
            'location_id' => $schema->integer()->description('Location bin to apply the delta to. Required when the user is restricted to assigned warehouses.'),
            'product_variant_id' => $schema->integer()->description('Variant of the product to adjust (required for products sold by variant).'),
            'warehouse_id' => $schema->integer()->description('Warehouse of the variant\'s stock (stock kept per warehouse). Defaults to the default warehouse.'),
            'owner_id' => $schema->integer()->description('Owner of the units (stock kept per warehouse). Omit when not known: the units stay "to be assigned".'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->authorize(['manage_stock']);

        $validated = $request->validate([
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'type' => ['required', 'string', 'in:manual,count,damage,return,transfer'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'location_id' => ['nullable', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        $product = Product::query()
            ->forOrganization($this->organizationId())
            ->find($validated['product_id']);

        if (! $product) {
            return Response::error('Product not found in this organization.');
        }

        $locationId = isset($validated['location_id']) ? (int) $validated['location_id'] : null;

        $variant = null;
        if (isset($validated['product_variant_id'])) {
            $variant = ProductVariant::query()->where('organization_id', $this->organizationId())
                ->where('product_id', $product->id)->find($validated['product_variant_id']);
            if ($variant === null) {
                return Response::error('Variant not found on this product.');
            }
        } elseif ($product->has_variants && $product->variants()->exists()) {
            return Response::error('This product is sold by variant: name the variant (product_variant_id).');
        }

        $warehouseId = isset($validated['warehouse_id']) ? (int) $validated['warehouse_id'] : null;
        if ($warehouseId !== null && ! Warehouse::withoutGlobalScopes()->where('organization_id', $this->organizationId())->whereKey($warehouseId)->exists()) {
            return Response::error('Warehouse not found in this organization.');
        }
        $ownerId = isset($validated['owner_id']) ? (int) $validated['owner_id'] : null;
        if ($ownerId !== null && ! StockOwner::query()->where('organization_id', $this->organizationId())->whereKey($ownerId)->exists()) {
            return Response::error('Owner not found in this organization.');
        }

        if ($locationId !== null && ! ProductLocation::query()->where('organization_id', $this->organizationId())->whereKey($locationId)->exists()) {
            return Response::error('Location not found in this organization.');
        }

        // A restricted user may only adjust a bin in one of their warehouses.
        $this->warehouseAccess()->authorizeLocation($this->user(), $locationId);

        // The units the change can draw on: the variant's, or the product's
        // own stock (for a product sold by variant its own column is unused).
        $onHand = (int) ($variant?->stock ?? $product->stock);
        if ($validated['quantity'] < 0 && abs($validated['quantity']) > $onHand) {
            return Response::error("Cannot remove {$validated['quantity']} units; only {$onHand} on hand.");
        }

        $approvals = app(ApprovalService::class);

        try {
            $adjustment = $approvals->submitStockAdjustment(
                user: $this->user(),
                product: $product,
                variant: $variant,
                quantity: (int) $validated['quantity'],
                type: $validated['type'],
                reason: $validated['reason'] ?? null,
                notes: $validated['notes'] ?? null,
                locationId: $variant === null ? $locationId : null,
                warehouseId: $warehouseId,
                stockOwnerId: $ownerId,
            );
        } catch (InsufficientStockException $e) {
            return Response::error($e->getMessage());
        }

        if ($adjustment instanceof StockAdjustmentRequest) {
            return Response::json([
                'message' => 'This adjustment needs approval. It was sent to an approver; stock has not changed yet.',
                'status' => 'pending_approval',
                'request' => $approvals->describe(ApprovalService::STOCK_ADJUSTMENT, $adjustment),
            ]);
        }

        $product->refresh();
        $variant?->refresh();

        return Response::json([
            'message' => 'Stock adjusted.',
            'adjustment' => [
                'id' => $adjustment->id,
                'type' => $adjustment->type,
                'quantity' => $adjustment->adjustment_quantity ?? $validated['quantity'],
                'reason' => $adjustment->reason,
            ],
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'stock' => $product->stock,
                'total_stock' => $product->total_stock,
            ],
            'variant' => $variant === null ? null : [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'stock' => $variant->stock,
                'by_warehouse' => app(VariantStockService::class)->totalsByWarehouse($variant),
            ],
        ]);
    }
}
