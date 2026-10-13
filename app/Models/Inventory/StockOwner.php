<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A company that can own units of stock (e.g. MC2, Saxone), independent of
 * the warehouse the units are in.
 *
 * Not organization-scoped by a global scope: callers filter on
 * organization_id, like StockAdjustment.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $code
 * @property bool $is_active
 */
class StockOwner extends Model
{
    protected $fillable = ['organization_id', 'name', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
