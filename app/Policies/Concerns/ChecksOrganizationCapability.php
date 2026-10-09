<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

trait ChecksOrganizationCapability
{
    /** Visible-but-forbidden actions answer 403 with a stable code; other tenants never reach here (404). */
    protected function capability(User $user, string $capability, string $denial, ?Model $resource = null): Response
    {
        return app(OrganizationAccess::class)->allows($user, $capability, $resource)
            ? Response::allow()
            : Response::deny($denial, 'ORGANIZATION_ROLE_FORBIDDEN');
    }
}
