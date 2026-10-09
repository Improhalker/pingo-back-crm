<?php

namespace App\Queries;

use App\Models\Expense;
use App\Support\FinanceFlow;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Expenses with their schedule totals. Settlements and instalments are summed before joining. */
final class ExpenseQuery
{
    public function __construct(private OrganizationContext $context, private Installments $installments) {}

    public function base(): Builder
    {
        return Expense::owned()
            ->joinSub($this->installments->totalsPerEntry(FinanceFlow::expense()), 'totals', 'totals.entry_id', '=', 'expenses.id')
            ->join('categories', fn ($join) => $join->on('categories.id', '=', 'expenses.category_id')->on('categories.organization_id', '=', 'expenses.organization_id'))
            ->select('expenses.*')
            ->addSelect([
                'categories.name AS category_name',
                'categories.archived_at AS category_archived_at',
                DB::raw('totals.instalments_count AS instalments_count'),
            ]);
    }

    /** The due period matches entries with at least one instalment due in it (civil dates, inclusive). */
    public function filtered(?string $search, ?string $status, ?string $dueFrom, ?string $dueTo): Builder
    {
        $today = CarbonImmutable::now($this->context->organization()->timezone)->toDateString();
        $remaining = 'totals.scheduled_amount - totals.settled_amount';

        return $this->base()
            ->when($search !== null && $search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $inner) => $inner->where('expenses.description', 'like', $like)->orWhere('categories.name', 'like', $like));
            })
            ->when($status === 'paid', fn (Builder $query) => $query->whereRaw("{$remaining} <= 0"))
            ->when($status === 'pending', fn (Builder $query) => $query->whereRaw("{$remaining} > 0")->where('totals.next_due_date', '>=', $today))
            ->when($status === 'overdue', fn (Builder $query) => $query->whereRaw("{$remaining} > 0")->where('totals.next_due_date', '<', $today))
            ->when($dueFrom || $dueTo, fn (Builder $query) => $query->whereExists(function ($exists) use ($dueFrom, $dueTo) {
                $exists->selectRaw('1')->from('payables')
                    ->whereColumn('payables.expense_id', 'expenses.id')
                    ->whereColumn('payables.organization_id', 'expenses.organization_id')
                    ->when($dueFrom, fn ($q) => $q->where('payables.due_date', '>=', $dueFrom))
                    ->when($dueTo, fn ($q) => $q->where('payables.due_date', '<=', $dueTo));
            }))
            ->orderByRaw('COALESCE(totals.next_due_date, totals.last_due_date)')->orderByDesc('expenses.id');
    }
}
