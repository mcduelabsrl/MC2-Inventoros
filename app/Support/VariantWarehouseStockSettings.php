<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Auth\Organization;

/**
 * Whether an organization keeps variant stock per warehouse, read from the
 * `variant_warehouse_stock` key of organizations.settings.
 *
 * Off by default: an organization keeps the single variant stock number
 * until the bootstrap procedure has created its warehouses and opening
 * balances and turned this on.
 */
final class VariantWarehouseStockSettings
{
    public const KEY = 'variant_warehouse_stock';

    public function __construct(
        public readonly bool $enabled = false,
        public readonly ?int $defaultWarehouseId = null,
        public readonly ?int $returnsWarehouseId = null,
    ) {}

    public static function forOrganization(Organization|int|null $organization): self
    {
        if ($organization === null) {
            return new self;
        }

        $id = $organization instanceof Organization ? (int) $organization->getKey() : $organization;
        $memo = app(VariantWarehouseStockMemo::class);

        return $memo->settings[$id] ??= self::fromArray((array) ((
            ($organization instanceof Organization ? $organization : Organization::query()->find($organization))?->settings ?? []
        )[self::KEY] ?? []));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled: (bool) ($raw['enabled'] ?? false),
            defaultWarehouseId: isset($raw['default_warehouse_id']) ? (int) $raw['default_warehouse_id'] : null,
            returnsWarehouseId: isset($raw['returns_warehouse_id']) ? (int) $raw['returns_warehouse_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'default_warehouse_id' => $this->defaultWarehouseId,
            'returns_warehouse_id' => $this->returnsWarehouseId,
        ];
    }

    /**
     * Store settings on the organization and forget the memoised copy.
     */
    public static function save(Organization $organization, self $settings): void
    {
        $all = (array) ($organization->settings ?? []);
        $all[self::KEY] = $settings->toArray();
        $organization->forceFill(['settings' => $all])->save();

        self::forget((int) $organization->getKey());
    }

    public static function forget(?int $organizationId = null): void
    {
        $memo = app(VariantWarehouseStockMemo::class);

        if ($organizationId === null) {
            $memo->settings = [];
        } else {
            unset($memo->settings[$organizationId]);
        }
    }

    public static function enabledFor(?int $organizationId): bool
    {
        return $organizationId !== null && self::forOrganization($organizationId)->enabled;
    }
}
