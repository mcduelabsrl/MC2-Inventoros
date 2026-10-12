<?php

declare(strict_types=1);

namespace Inventoros\Plugins\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inventoros\Plugins\Shopify\CatalogueSync;
use Inventoros\Plugins\Shopify\Models\ShopifyConnection;
use Inventoros\Plugins\Shopify\ShopifyException;

/**
 * Import the Shopify catalogue into Inventoros, or publish Inventoros
 * products to Shopify (see CatalogueSync). Started from the settings page.
 */
class CatalogueJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const IMPORT = 'import';

    public const PUBLISH = 'publish';

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $organizationId,
        public readonly string $direction,
        public readonly bool $withStock = true,
        public readonly string $status = 'DRAFT',
    ) {}

    public function uniqueId(): string
    {
        return "shopify-catalogue-{$this->organizationId}";
    }

    public function handle(): void
    {
        $connection = ShopifyConnection::forOrganization($this->organizationId);
        if ($connection === null) {
            return;
        }

        try {
            $sync = new CatalogueSync($connection);
            $this->direction === self::PUBLISH ? $sync->publishToShopify($this->status) : $sync->importFromShopify($this->withStock);
        } catch (ShopifyException $e) {
            $connection->forceFill(['last_error' => 'Catalogue '.$this->direction.' failed: '.$e->getMessage()])->save();
        }
    }
}
