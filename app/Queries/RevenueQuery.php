<?php

namespace App\Queries;

use App\Models\Revenue;
use App\Support\FinanceFlow;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Revenues with their schedule totals. Instalments and effective receipts are aggregated per revenue in a
 * subquery, so the joins never multiply amounts; every relation is restricted to the authorized organization.
 */
final class RevenueQuery
{
    public function __construct(private OrganizationContext $context, private Installments $installments) {}

    public function base(): Builder
    {
        return Revenue::owned()
            ->joinSub($this->installments->totalsPerEntry(FinanceFlow::revenue()), 'totals', 'totals.entry_id', '=', 'revenues.id')
            ->join('customers', function ($join) {
                $join->on('customers.id', '=', 'revenues.customer_id')->on('customers.organization_id', '=', 'revenues.organization_id');
            })
            ->join('categories', function ($join) {
                $join->on('categories.id', '=', 'revenues.category_id')->on('categories.organization_id', '=', 'revenues.organization_id');
            })
            ->select('revenues.*')
            ->addSelect([
                'customers.name AS customer_name',
                'customers.archived_at AS customer_archived_at',
                'categories.name AS category_name',
                'categories.archived_at AS category_archived_at',
                DB::raw('totals.instalments_count AS instalments_count'),
            ]);
    }

    /** status: pending | overdue | received. Overdue when any open instalment is past the company's day. */
    public function filtered(?string $search, ?string $status): Builder
    {
        $today = CarbonImmutable::now($this->context->organization()->timezone)->toDateString();
        $remaining = 'totals.scheduled_amount - totals.settled_amount';

        return $this->base()
            ->when($search !== null && $search !== '', function (Builder $query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $inner) => $inner->where('revenues.description', 'like', $like)->orWhere('customers.name', 'like', $like));
            })
            ->when($status === 'received', fn (Builder $query) => $query->whereRaw("{$remaining} <= 0"))
            ->when($status === 'pending', fn (Builder $query) => $query->whereRaw("{$remaining} > 0")->where('totals.next_due_date', '>=', $today))
            ->when($status === 'overdue', fn (Builder $query) => $query->whereRaw("{$remaining} > 0")->where('totals.next_due_date', '<', $today))
            ->orderByDesc('revenues.sale_date')->orderByDesc('revenues.id');
    }
}
