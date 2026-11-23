<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Financial\ManageFinancialRecurrence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ChangeFinancialRecurrenceRequest;
use App\Http\Requests\Finance\StoreFinancialRecurrenceRequest;
use App\Models\Expense;
use App\Models\FinancialRecurrence;
use App\Models\Revenue;
use App\Support\Idempotency;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

final class FinancialRecurrenceController extends Controller
{
    public function __construct(private OrganizationContext $context) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', FinancialRecurrence::class);
        $filters = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'kind' => ['sometimes', 'in:REVENUE,EXPENSE']]);
        $rules = FinancialRecurrence::owned()->with('latestRate')
            ->when($filters['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $rules->getCollection()->map(fn (FinancialRecurrence $series) => $this->present($series)),
            'meta' => $this->pageMeta($rules),
        ]);
    }

    public function show(Request $request, int $recurrence): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $series = FinancialRecurrence::owned()->findOrFail($recurrence);
        Gate::authorize('view', $series);
        $flow = $series->flow();
        $entries = $flow->entryClass::owned()->withTrashed()->where('financial_recurrence_id', $series->id)
            ->orderByDesc('recurrence_month')->paginate(12);
        $due = $flow->obligationClass::owned()->whereIn($flow->entryKey, $entries->getCollection()->pluck('id'))
            ->pluck('due_date', $flow->entryKey);

        return response()->json(['data' => [
            ...$this->present($series),
            'occurrences' => $entries->getCollection()->map(fn ($entry) => [
                'id' => (string) $entry->id,
                'month' => substr((string) $entry->recurrence_month, 0, 7),
                'amount' => (string) $entry->total_amount,
                'due_date' => CarbonImmutable::parse($due->get($entry->id))->toDateString(),
                'deleted' => $entry->trashed(),
                'amount_overridden' => (bool) $entry->recurrence_amount_overridden,
            ]),
            'occurrence_meta' => $this->pageMeta($entries),
        ]]);
    }

    public function store(StoreFinancialRecurrenceRequest $request, ManageFinancialRecurrence $manage, Idempotency $idempotency): JsonResponse
    {
        $data = $request->payload();
        Gate::authorize('create', $data['kind'] === 'REVENUE' ? Revenue::class : Expense::class);

        return $idempotency->run($request, $request->user(), 'recurrence.create', $data,
            fn () => [201, ['data' => $this->present($manage->create($request->user(), $data))]]);
    }

    public function update(ChangeFinancialRecurrenceRequest $request, int $recurrence, ManageFinancialRecurrence $manage, Idempotency $idempotency): JsonResponse
    {
        $series = FinancialRecurrence::owned()->findOrFail($recurrence);
        Gate::authorize('update', $series);
        $data = $request->payload();

        return $idempotency->run($request, $request->user(), 'recurrence.update', ['id' => $recurrence, ...$data],
            fn () => [200, ['data' => $this->present($manage->change($request->user(), $recurrence, $data))]]);
    }

    private function present(FinancialRecurrence $series): array
    {
        $today = CarbonImmutable::now($this->context->organization()->timezone)->toDateString();
        $current = CarbonImmutable::parse($today)->startOfMonth();
        $month = max($current, $series->starts_on);
        if ($series->dueIn($month)->toDateString() < $today) {
            $month = $month->addMonth();
        }
        $ended = $series->ends_on && $series->ends_on < $current;
        $status = $ended ? 'ENDED' : $series->status;
        $withinTerm = $month->year <= 2100 && (! $series->ends_on || $month <= $series->ends_on);
        $rate = $series->latestRate;

        return [
            'id' => (string) $series->id,
            'kind' => $series->kind,
            'description' => $series->description,
            'status' => $status,
            'starts_month' => $series->starts_on->format('Y-m'),
            'ends_month' => $series->ends_on?->format('Y-m'),
            'due_day' => (int) $series->due_day,
            'reminder_days' => $series->reminder_days === null ? null : (int) $series->reminder_days,
            'amount' => (string) $rate->amount,
            'effective_month' => $rate->effective_month->format('Y-m'),
            'next_due_date' => $status === 'ACTIVE' && $withinTerm ? $series->dueIn($month)->toDateString() : null,
            'version' => (int) $series->version,
        ];
    }

    private function pageMeta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
