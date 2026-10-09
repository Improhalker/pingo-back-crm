<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Platform\AddOrganizationAdministrator;
use App\Actions\Platform\DeleteOrganization;
use App\Actions\Platform\ProvisionOrganization;
use App\Actions\Platform\SetOrganizationSuspension;
use App\Actions\Platform\UpdateOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\DestroyOrganizationRequest;
use App\Http\Requests\Platform\IndexOrganizationsRequest;
use App\Http\Requests\Platform\StoreAdministratorRequest;
use App\Http\Requests\Platform\StoreOrganizationRequest;
use App\Http\Requests\Platform\UpdateOrganizationRequest;
use App\Http\Resources\Platform\PlatformOrganizationDetailResource;
use App\Http\Resources\Platform\PlatformOrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function index(IndexOrganizationsRequest $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->validated('search', ''));
        $status = $request->validated('status');

        // Stable order with an ID tie-breaker; the list carries only registration data and a member count.
        $organizations = Organization::query()
            ->withCount('users')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->when($status === 'active', fn ($query) => $query->whereNull('suspended_at'))
            ->when($status === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) $request->validated('per_page', 25))
            ->withQueryString();

        return PlatformOrganizationResource::collection($organizations);
    }

    public function show(Organization $organization): PlatformOrganizationDetailResource
    {
        Gate::authorize('platform.manage');

        return new PlatformOrganizationDetailResource($this->withMembers($organization));
    }

    public function store(StoreOrganizationRequest $request, ProvisionOrganization $provision): JsonResponse
    {
        $data = $request->validated();
        [$organization, $administrator] = $provision(
            $request->user(),
            $data['organization']['name'],
            $data['organization']['timezone'],
            $data['administrator']['name'],
            $data['administrator']['email'],
            $data['administrator']['password'],
        );
        $organization->setRelation('firstAdministrator', $administrator);

        return (new PlatformOrganizationResource($organization))->response()->setStatusCode(201);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganization $update): PlatformOrganizationDetailResource
    {
        $update($request->user(), $organization, $request->validated());

        return new PlatformOrganizationDetailResource($this->withMembers($organization));
    }

    public function suspend(Request $request, Organization $organization, SetOrganizationSuspension $suspension): PlatformOrganizationDetailResource
    {
        Gate::authorize('platform.manage');
        $suspension($request->user(), $organization, true);

        return new PlatformOrganizationDetailResource($this->withMembers($organization));
    }

    public function reactivate(Request $request, Organization $organization, SetOrganizationSuspension $suspension): PlatformOrganizationDetailResource
    {
        Gate::authorize('platform.manage');
        $suspension($request->user(), $organization, false);

        return new PlatformOrganizationDetailResource($this->withMembers($organization));
    }

    public function destroy(DestroyOrganizationRequest $request, Organization $organization, DeleteOrganization $delete): JsonResponse
    {
        $result = $delete($request->user(), $organization, $request->validated('confirmation_name'));

        return response()->json(['data' => ['id' => (string) $organization->id, 'removed_accounts' => $result['removed_accounts']]]);
    }

    public function storeAdministrator(StoreAdministratorRequest $request, Organization $organization, AddOrganizationAdministrator $add): JsonResponse
    {
        $data = $request->validated();
        $add($request->user(), $organization, $data['name'], $data['email'], $data['password']);

        return (new PlatformOrganizationDetailResource($this->withMembers($organization)))->response()->setStatusCode(201);
    }

    private function withMembers(Organization $organization): Organization
    {
        return $organization->refresh()->loadCount('users')->load(['users' => fn ($query) => $query
            ->withCount('organizations')
            ->withExists('platformAdministrator')
            ->orderBy('organization_user.created_at')
            ->orderBy('users.id')]);
    }
}
