<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use App\Models\Inventory\Product;
use App\Models\Inventory\ProductVariant;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\InventorySync;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Push one product's (or variant's) stock to Shopify.
 *
 * Unique until it starts, so a burst of stock changes to the same product
 * queues one push; it reads the stock when it runs, so the last value wins.
 */
class PushInventory implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $productId,
        public readonly ?int $variantId = null,
    ) {}

    public function uniqueId(): string
    {
        return "shopify-push-{$this->organizationId}-{$this->productId}-".($this->variantId ?? 'p');
    }

    public function handle(): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null || ! $connection->legacyPushAllowed()) {
            return;
        }

        $product = Product::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $this->organizationId)
            ->find($this->productId);
        if ($product === null) {
            return;
        }

        $variant = null;
        if ($this->variantId !== null) {
            $variant = ProductVariant::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $this->organizationId)
                ->where('product_id', $product->id)
                ->find($this->variantId);
            if ($variant === null) {
                return;
            }
        }

        try {
            InventorySync::for($connection)->push($product, $variant);
        } catch (ShopifyException $e) {
            if ($e->transient) {
                throw $e; // retried with back-off
            }
            $connection->forceFill(['last_error' => $e->getMessage()])->save();
        }
    }
}
