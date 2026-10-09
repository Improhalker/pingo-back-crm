<?php

namespace App\Models\Concerns;

use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-owned model. `owned()` restricts a query to the authorized organization context and fails closed
 * (LogicException) when there is none. organization_id is never mass assignable.
 */
trait BelongsToOrganization
{
    public function scopeOwned(Builder $query): Builder
    {
        $organization = app(OrganizationContext::class)->organization();

        return $query->where($this->qualifyColumn('organization_id'), $organization->id);
    }
}
