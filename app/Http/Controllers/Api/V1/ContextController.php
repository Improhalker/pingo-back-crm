<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationContextResource;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\Gate;

class ContextController extends Controller
{
    public function __invoke(OrganizationContext $context): OrganizationContextResource
    {
        $organization = $context->organization();
        Gate::authorize('view', $organization);

        return new OrganizationContextResource($organization);
    }
}
