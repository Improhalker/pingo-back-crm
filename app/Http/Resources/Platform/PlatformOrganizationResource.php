<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public platform contract for a client company. Never exposes passwords, hashes or financial data. */
class PlatformOrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $administrator = $this->resource->relationLoaded('firstAdministrator') ? $this->resource->getRelation('firstAdministrator') : null;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'timezone' => $this->timezone,
            'status' => $this->isSuspended() ? 'SUSPENDED' : 'ACTIVE',
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'members_count' => $this->users_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'first_administrator' => $administrator ? [
                'id' => (string) $administrator->id,
                'name' => $administrator->name,
                'email' => $administrator->email,
            ] : null,
        ];
    }
}
