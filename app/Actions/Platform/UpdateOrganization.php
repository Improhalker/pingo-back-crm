<?php

namespace App\Actions\Platform;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Updates registration data (name, timezone) of a client company. Never touches members or suspension. */
final class UpdateOrganization
{
    /** @param array{name?: string, timezone?: string} $data */
    public function __invoke(User $actor, Organization $organization, array $data): Organization
    {
        $organization->fill(array_intersect_key($data, array_flip(['name', 'timezone'])));
        $changed = array_keys($organization->getDirty());
        $organization->save();

        if ($changed !== []) {
            Log::info('platform.organization_updated', [
                'actor_user_id' => $actor->id,
                'organization_id' => $organization->id,
                'fields' => $changed,
            ]);
        }

        return $organization;
    }
}
