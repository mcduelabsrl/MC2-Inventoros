<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-request (scoped) memo of VariantWarehouseStockSettings, so the
 * organization row is read once per request or job, not per order line.
 */
final class VariantWarehouseStockMemo
{
    /** @var array<int, VariantWarehouseStockSettings> */
    public array $settings = [];
}
