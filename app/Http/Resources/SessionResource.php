<?php

namespace App\Http\Resources;

use App\Support\CompanyMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $membership = CompanyMembership::resolve($this->resource);
        $organization = $membership['organization'];

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // Informational for the interface only; every platform operation is authorized by the backend.
            'is_platform_administrator' => $membership['status'] === CompanyMembership::PLATFORM,
            // ok | none | multiple | suspended | platform. The context is still checked on every request.
            'access' => $membership['status'],
            // The single company this account works in, resolved by the server (ADR-028).
            'organization' => $organization ? $this->organizationData($organization) : null,
            'organizations' => $this->organizations->map(fn ($org) => $this->organizationData($org))->values()->all(),
        ];
    }

    private function organizationData($organization): array
    {
        return [
            'id' => (string) $organization->id, 'name' => $organization->name,
            'timezone' => $organization->timezone, 'role' => $organization->pivot->role,
            'suspended' => $organization->isSuspended(),
        ];
    }
}
