<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;

/**
 * Company detail for platform administration: registration data plus who has access (name, e-mail,
 * role). Access-management data only; tenant business and financial data are never included.
 */
class PlatformOrganizationDetailResource extends PlatformOrganizationResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'members' => $this->users->map(fn ($user) => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'joined_at' => $user->pivot->created_at?->toIso8601String(),
                // True when deleting this company would also delete the account (no other access).
                'only_in_this_organization' => $user->organizations_count === 1 && ! $user->platform_administrator_exists,
                // Legacy link to more than one company: login is blocked until platform:legacy-memberships resolves it (ADR-028).
                'has_other_organizations' => $user->organizations_count > 1,
            ])->values()->all(),
        ];
    }
}
