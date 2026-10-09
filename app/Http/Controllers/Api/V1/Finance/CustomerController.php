<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CustomerRequest;
use App\Http\Requests\Finance\IndexCustomersRequest;
use App\Http\Resources\Finance\CustomerResource;
use App\Models\Customer;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Customers of the authorized organization. Lookups by ID outside it answer 404. */
class CustomerController extends Controller
{
    public function index(IndexCustomersRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Customer::class);
        $search = trim((string) $request->validated('search', ''));
        $status = $request->validated('status') ?? 'active';

        $customers = Customer::owned()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('document', 'like', $like));
            })
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')->orderBy('id')
            ->paginate((int) $request->validated('per_page', 25))
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function show(int $customer): CustomerResource
    {
        $model = Customer::owned()->findOrFail($customer);
        Gate::authorize('view', $model);

        return new CustomerResource($model);
    }

    public function store(CustomerRequest $request, OrganizationContext $context): JsonResponse
    {
        Gate::authorize('create', Customer::class);
        $customer = new Customer($request->safe()->only(['name', 'phone', 'email', 'document', 'notes']));
        $customer->organization_id = $context->organization()->id;
        $customer->save();

        return (new CustomerResource($customer))->response()->setStatusCode(201);
    }

    public function update(CustomerRequest $request, int $customer): CustomerResource
    {
        $model = Customer::owned()->findOrFail($customer);
        Gate::authorize('update', $model);
        $model->fill($request->safe()->only(['name', 'phone', 'email', 'document', 'notes']))->save();

        return new CustomerResource($model);
    }

    /** Archiving keeps the customer on existing revenues; it only leaves the list of new entries. */
    public function archive(int $customer): CustomerResource
    {
        return $this->setArchived($customer, true);
    }

    public function unarchive(int $customer): CustomerResource
    {
        return $this->setArchived($customer, false);
    }

    private function setArchived(int $id, bool $archived): CustomerResource
    {
        $model = Customer::owned()->findOrFail($id);
        Gate::authorize('update', $model);
        $model->forceFill(['archived_at' => $archived ? ($model->archived_at ?? now('UTC')) : null])->save();

        return new CustomerResource($model);
    }
}
