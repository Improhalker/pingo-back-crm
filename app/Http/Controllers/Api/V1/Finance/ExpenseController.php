<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Actions\Financial\ChangeFinancialEntry;
use App\Actions\Financial\CorrectSettlement;
use App\Actions\Financial\CreateExpense;
use App\Actions\Financial\RegisterPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CorrectSettlementRequest;
use App\Http\Requests\Finance\DeleteFinancialEntryRequest;
use App\Http\Requests\Finance\IndexExpensesRequest;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\StorePaymentRequest;
use App\Http\Requests\Finance\UpdateFinancialEntryRequest;
use App\Http\Resources\Finance\ExpenseResource;
use App\Models\Expense;
use App\Models\Payable;
use App\Models\Payment;
use App\Queries\ExpenseQuery;
use App\Queries\Installments;
use App\Support\FinanceFlow;
use App\Support\Idempotency;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** Expenses, their instalments and payments. Financial writes require an Idempotency-Key. */
class ExpenseController extends Controller
{
    public function __construct(private ExpenseQuery $expenses, private Installments $installments) {}

    public function index(IndexExpensesRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Expense::class);
        $page = $this->expenses
            ->filtered(trim((string) $request->validated('search', '')), $request->validated('status'), $request->validated('due_from'), $request->validated('due_to'))
            ->paginate((int) $request->validated('per_page', 25))
            ->withQueryString();

        // One extra query loads the instalments of the whole page.
        $schedules = $this->installments->forEntries(FinanceFlow::expense(), $page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(fn (Expense $item) => $item->schedule = $schedules[$item->id] ?? []);

        return ExpenseResource::collection($page);
    }

    public function show(int $expense): ExpenseResource
    {
        return new ExpenseResource($this->detail($expense));
    }

    public function store(StoreExpenseRequest $request, CreateExpense $create, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('create', Expense::class);
        $payload = $request->payload();
        if (array_filter(array_column($payload['installments'], 'settlement'))) {
            // Paying at creation (at once or a down payment) also needs the settlement capability.
            Gate::authorize('settle', (new Payable)->forceFill(['organization_id' => app(OrganizationContext::class)->organization()->id]));
        }

        return $idempotency->run($request, $request->user(), 'expense.create', $payload, function () use ($request, $create, $payload) {
            $expense = $create($request->user(), $payload);

            return [201, ['data' => (new ExpenseResource($this->detail($expense->id, authorize: false)))->resolve($request)]];
        });
    }

    public function update(UpdateFinancialEntryRequest $request, int $expense, ChangeFinancialEntry $change, Idempotency $idempotency): JsonResponse
    {
        $payload = $request->payload();

        return $idempotency->run($request, $request->user(), 'expense.update', ['id' => $expense, ...$payload], function () use ($request, $expense, $change, $payload) {
            $change->run($request->user(), false, $expense, $payload);

            return [200, ['data' => (new ExpenseResource($this->detail($expense, authorize: false)))->resolve($request)]];
        });
    }

    public function destroy(DeleteFinancialEntryRequest $request, int $expense, ChangeFinancialEntry $change, Idempotency $idempotency): JsonResponse
    {
        Gate::authorize('delete', Expense::owned()->withTrashed()->findOrFail($expense));
        $payload = ['expected_version' => (int) $request->validated('expected_version')];

        return $idempotency->run($request, $request->user(), 'expense.delete', ['id' => $expense, ...$payload], function () use ($request, $expense, $change, $payload) {
            $change->run($request->user(), false, $expense, $payload, delete: true);

            return [200, ['data' => ['id' => (string) $expense, 'deleted' => true]]];
        });
    }

    public function storePayment(StorePaymentRequest $request, int $payable, RegisterPayment $register, Idempotency $idempotency): JsonResponse
    {
        $model = Payable::owned()->findOrFail($payable);
        Gate::authorize('settle', $model);
        $payload = $request->payload();

        return $idempotency->run($request, $request->user(), 'payment.create', ['payable_id' => $model->id, ...$payload], function () use ($request, $register, $model, $payload) {
            $register($request->user(), $model->id, $payload);

            return [201, ['data' => (new ExpenseResource($this->detail($model->expense_id, authorize: false)))->resolve($request)]];
        });
    }

    private function detail(int $id, bool $authorize = true): Expense
    {
        $expense = $this->expenses->base()->where('expenses.id', $id)->firstOrFail();
        if ($authorize) {
            Gate::authorize('view', $expense);
        }
        $expense->schedule = $this->installments->forEntries(FinanceFlow::expense(), [$expense->id])[$expense->id] ?? [];
        // Every payment of every instalment, corrected ones included (they are the evidence of a correction).
        $expense->setRelation('paymentList', Payment::owned()
            ->with('author:id,name')
            ->whereIn('payable_id', array_column($expense->schedule, 'id'))
            ->orderBy('paid_at')->orderBy('id')
            ->get());

        return $expense;
    }

    /** Card 09: corrects one payment by superseding it with a linked replacement (ADR-031). */
    public function correctPayment(CorrectSettlementRequest $request, int $payment, CorrectSettlement $correct, Idempotency $idempotency): JsonResponse
    {
        $original = Payment::owned()->findOrFail($payment);
        Gate::authorize('correct', Payable::owned()->findOrFail($original->payable_id));
        $payload = $request->payload();

        return $idempotency->run($request, $request->user(), 'payment.correct', ['payment_id' => $original->id, ...$payload], function () use ($request, $correct, $original, $payload) {
            $correct(FinanceFlow::expense(), $request->user(), $original->id, $payload);
            $expenseId = (int) Payable::owned()->whereKey($original->payable_id)->value('expense_id');

            return [201, ['data' => (new ExpenseResource($this->detail($expenseId, authorize: false)))->resolve($request)]];
        });
    }
}
