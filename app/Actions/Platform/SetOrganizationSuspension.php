<?php

namespace App\Actions\Platform;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Suspends or reactivates a client company. Data and memberships are preserved; while suspended,
 * ResolveOrganization refuses its context to every member. Idempotent.
 */
final class SetOrganizationSuspension
{
    public function __invoke(User $actor, Organization $organization, bool $suspended): Organization
    {
        if ($organization->isSuspended() === $suspended) {
            return $organization;
        }
        $organization->forceFill(['suspended_at' => $suspended ? now() : null])->save();

        Log::info($suspended ? 'platform.organization_suspended' : 'platform.organization_reactivated', [
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
        ]);

        return $organization;
    }
}
