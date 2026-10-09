<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    use ChecksOrganizationCapability;

    public function viewDashboard(User $user, Organization $organization): Response
    {
        if (! app(OrganizationContext::class)->organization()->is($organization)) {
            return Response::denyAsNotFound();
        }

        return $this->capability($user, 'dashboard.view', 'Seu papel não permite consultar o resumo financeiro.');
    }

    public function viewReports(User $user, Organization $organization): Response
    {
        if (! app(OrganizationContext::class)->organization()->is($organization)) {
            return Response::denyAsNotFound();
        }

        return $this->capability($user, 'report.view', 'Seu papel não permite consultar os relatórios financeiros.');
    }

    public function view(User $user, Organization $organization): bool
    {
        return app(OrganizationContext::class)->organization()->is($organization)
            && $user->organizations()->where('organizations.id', $organization->id)->exists();
    }
}
