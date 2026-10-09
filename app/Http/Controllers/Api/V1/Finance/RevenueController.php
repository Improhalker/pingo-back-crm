<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Financial\ChangeFinancialEntry;
use App\Actions\Financial\CorrectSettlement;
use App\Actions\Financial\CreateRevenue;
use App\Actions\Financial\RegisterReceipt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CorrectSettlementRequest;
use App\Http\Requests\Finance\DeleteFinancialEntryRequest;
use App\Http\Requests\Finance\IndexRevenuesRequest;
use App\Http\Requests\Finance\StoreReceiptRequest;
use App\Http\Requests\Finance\StoreRevenueRequest;
use App\Http\Requests\Finance\UpdateFinancialEntryRequest;
use App\Http\Resources\Finance\RevenueResource;
use App\Models\Receipt;
use App\Models\Receivable;
use App\Models\Revenue;
use App\Queries\Installments;
use App\Queries\RevenueQuery;
use App\Support\FinanceFlow;
use App\Support\Idempotency;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Revenues (sales), their instalments and receipts. Financial writes require an Idempotency-Key. */
class RevenueController extends Controller
{
    public function __construct(private RevenueQuery $revenues, private Installments $installments) {}

    public function index(IndexRevenuesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Revenue::class);
        $page = $this->revenues
            ->filtered(trim((string) $request->validated('search', '')), $request->validated('status'))
            ->paginate((int) $request->validated('per_page', 25))
            ->withQueryString();

        // One extra query loads the instalments of the whole page.
        $schedules = $this->installments->forEntries(FinanceFlow::revenue(), $page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Revenue $item) => $item->schedule = $schedules[$item->id] ?? []);

        return RevenueResource::collection($page);
    }

    public function show(int $revenue): RevenueResource
    {
        return new RevenueResource($this->detail($revenue));
    }

    public function store(StoreRevenueRequest $request, CreateRevenue $create, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('create', Revenue::class);
        $payload = $request->payload();
        if (array_filter(array_column($payload['installments'], 'settlement'))) {
            // Settling at creation (at once or a down payment) also needs the settlement capability.
            Gate::authorize('settle', (new Receivable)->forceFill(['organization_id' => app(OrganizationContext::class)->organization()->id]));
        }

        return $idempotency->run($request, $request->user(), 'revenue.create', $payload, function () use ($request, $create, $payload) {
            $revenue = $create($request->user(), $payload);

            return [201, ['data' => (new RevenueResource($this->detail($revenue->id, authorize: false)))->resolve($request)]];
        });
    }

    public function update(UpdateFinancialEntryRequest $request, int $revenue, ChangeFinancialEntry $change, Idempotency $idempotency): JsonResponse
    {
        $payload = $request->payload();

        return $idempotency->run($request, $request->user(), 'revenue.update', ['id' => $revenue, ...$payload], function () use ($request, $revenue, $change, $payload) {
            $change->run($request->user(), true, $revenue, $payload);

            return [200, ['data' => (new RevenueResource($this->detail($revenue, authorize: false)))->resolve($request)]];
        });
    }

    public function destroy(DeleteFinancialEntryRequest $request, int $revenue, ChangeFinancialEntry $change, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('delete', Revenue::owned()->withTrashed()->findOrFail($revenue));
        $payload = ['expected_version' => (int) $request->validated('expected_version')];

        return $idempotency->run($request, $request->user(), 'revenue.delete', ['id' => $revenue, ...$payload], function () use ($request, $revenue, $change, $payload) {
            $change->run($request->user(), true, $revenue, $payload, delete: true);

            return [200, ['data' => ['id' => (string) $revenue, 'deleted' => true]]];
        });
    }

    public function storeReceipt(StoreReceiptRequest $request, int $receivable, RegisterReceipt $register, Idempotency $idempotency): JsonResponse
    {
        $model = Receivable::owned()->findOrFail($receivable);
        Gate::authorize('settle', $model);
        $payload = $request->payload();

        return $idempotency->run($request, $request->user(), 'receipt.create', ['receivable_id' => $model->id, ...$payload], function () use ($request, $register, $model, $payload) {
            $register($request->user(), $model->id, $payload);

            return [201, ['data' => (new RevenueResource($this->detail($model->revenue_id, authorize: false)))->resolve($request)]];
        });
    }

    private function detail(int $id, bool $authorize = true): Revenue
    {
        $revenue = $this->revenues->base()->where('revenues.id', $id)->firstOrFail();
        if ($authorize) {
            Gate::authorize('view', $revenue);
        }
        $revenue->schedule = $this->installments->forEntries(FinanceFlow::revenue(), [$revenue->id])[$revenue->id] ?? [];
        // Every receipt of every instalment, corrected ones included (they are the evidence of a correction).
        $revenue->setRelation('receiptList', Receipt::owned()
            ->with('author:id,name')
            ->whereIn('receivable_id', array_column($revenue->schedule, 'id'))
            ->orderBy('received_at')->orderBy('id')
            ->get());

        return $revenue;
    }

    /** Card 09: corrects one receipt by superseding it with a linked replacement (ADR-031). */
    public function correctReceipt(CorrectSettlementRequest $request, int $receipt, CorrectSettlement $correct, Idempotency $idempotency): JsonResponse
    {
        $original = Receipt::owned()->findOrFail($receipt);
        Gate::authorize('correct', Receivable::owned()->findOrFail($original->receivable_id));
        $payload = $request->payload();

        return $idempotency->run($request, $request->user(), 'receipt.correct', ['receipt_id' => $original->id, ...$payload], function () use ($request, $correct, $original, $payload) {
            $correct(FinanceFlow::revenue(), $request->user(), $original->id, $payload);
            $revenueId = (int) Receivable::owned()->whereKey($original->receivable_id)->value('revenue_id');

            return [201, ['data' => (new RevenueResource($this->detail($revenueId, authorize: false)))->resolve($request)]];
        });
    }
}
