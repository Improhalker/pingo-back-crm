<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CategoryRequest;
use App\Http\Requests\Finance\IndexCategoriesRequest;
use App\Http\Resources\Finance\CategoryResource;
use App\Models\Category;
use App\Support\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Revenue/expense categories of the authorized organization. Archived ones stay on recorded entries. */
class CategoryController extends Controller
{
    public function index(IndexCategoriesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Category::class);
        $search = trim((string) $request->validated('search', ''));
        $status = $request->validated('status') ?? 'active';

        $categories = Category::owned()
            ->when($request->validated('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->when($status === 'active', fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('type')->orderBy('name')->orderBy('id')
            ->paginate((int) $request->validated('per_page', 25))
            ->withQueryString();

        return CategoryResource::collection($categories);
    }

    public function show(int $category): CategoryResource
    {
        $model = Category::owned()->findOrFail($category);
        Gate::authorize('view', $model);

        return new CategoryResource($model);
    }

    public function store(CategoryRequest $request, OrganizationContext $context): JsonResponse
    {
        Gate::authorize('create', Category::class);
        $category = new Category($request->safe()->only(['name']));
        $category->forceFill(['organization_id' => $context->organization()->id, 'type' => $request->validated('type')]);
        $this->saveUnique($category);

        return (new CategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(CategoryRequest $request, int $category): CategoryResource
    {
        $model = Category::owned()->findOrFail($category);
        Gate::authorize('update', $model);
        $model->fill($request->safe()->only(['name']));
        $this->saveUnique($model);

        return new CategoryResource($model);
    }

    public function archive(int $category): CategoryResource
    {
        return $this->setArchived($category, true);
    }

    public function unarchive(int $category): CategoryResource
    {
        return $this->setArchived($category, false);
    }

    private function setArchived(int $id, bool $archived): CategoryResource
    {
        $model = Category::owned()->findOrFail($id);
        Gate::authorize('update', $model);
        $model->forceFill(['archived_at' => $archived ? ($model->archived_at ?? now('UTC')) : null])->save();

        return new CategoryResource($model);
    }

    private function saveUnique(Category $category): void
    {
        try {
            $category->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['Já existe uma categoria com esse nome e tipo nesta empresa (talvez arquivada).']]);
        }
    }
}
