<?php

namespace App\Queries\Reports;

use App\Queries\Installments;
use App\Support\FinanceFlow;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Forecast (card 11, ADR-033): instalments still open, by due date, with what was already settled and what
 * remains. A sale not yet received appears here and never in the cash statement; a partial receipt splits
 * between both. Effective settlements are summed per obligation before any join (no duplicated principal);
 * deleted entries are excluded. Without filters the totals equal the dashboard's "a receber/a pagar".
 */
final class OpenObligationsQuery
{
    public const MAX_DAYS = 366;

    public function __construct(private ReportScope $scope, private Installments $installments) {}

    /**
     * Filters: type receivable|payable; status pending|overdue|partial; due period optional.
     *
     * @param  array{due_from?: ?string, due_to?: ?string, type?: ?string, category_id?: ?int, method?: ?string, status?: ?string}  $filters
     */
    public function get(array $filters, int $page = 1, int $perPage = 25): array
    {
        return $this->scope->read(function () use ($filters, $page, $perPage) {
            $today = $this->scope->now()->toDateString();
            $rows = $this->filtered($filters, $today);
            $totals = (clone $rows)->selectRaw(
                "COALESCE(SUM(CASE WHEN kind = 'RECEIVABLE' THEN remaining_amount ELSE 0 END), 0) AS receivable,
                 COALESCE(SUM(CASE WHEN kind = 'PAYABLE' THEN remaining_amount ELSE 0 END), 0) AS payable,
                 COALESCE(SUM(CASE WHEN kind = 'RECEIVABLE' AND due_date < ? THEN remaining_amount ELSE 0 END), 0) AS overdue_receivable,
                 COALESCE(SUM(CASE WHEN kind = 'PAYABLE' AND due_date < ? THEN remaining_amount ELSE 0 END), 0) AS overdue_payable,
                 COUNT(*) AS total_count",
                [$today, $today],
            )->first();
            $items = $rows->select('*')
                ->orderBy('due_date')->orderBy('kind')->orderBy('obligation_id')
                ->offset(($page - 1) * $perPage)->limit($perPage)->get()
                ->map(fn ($row) => $this->present($row, $today))->all();
            $receivable = Money::normalize((string) $totals->receivable);
            $payable = Money::normalize((string) $totals->payable);

            return [
                ...$this->scope->header(['due_from' => $filters['due_from'] ?? null, 'due_to' => $filters['due_to'] ?? null], 'DUE_DATE'),
                'summary' => [
                    'receivable' => $receivable,
                    'payable' => $payable,
                    'difference' => Money::sub($receivable, $payable),
                    'overdue_receivable' => Money::normalize((string) $totals->overdue_receivable),
                    'overdue_payable' => Money::normalize((string) $totals->overdue_payable),
                ],
                'data' => $items,
                'meta' => ReportScope::meta($page, $perPage, (int) $totals->total_count),
            ];
        });
    }

    private function filtered(array $filters, string $today): Builder
    {
        $type = $filters['type'] ?? null;
        $parts = [];
        if ($type !== 'payable') {
            $parts[] = $this->open(FinanceFlow::revenue());
        }
        if ($type !== 'receivable') {
            $parts[] = $this->open(FinanceFlow::expense());
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }
        $status = $filters['status'] ?? null;

        return DB::query()->fromSub($union, 'obligations')
            ->where('remaining_amount', '>', 0)
            ->when($filters['due_from'] ?? null, fn (Builder $q, $date) => $q->where('due_date', '>=', $date))
            ->when($filters['due_to'] ?? null, fn (Builder $q, $date) => $q->where('due_date', '<=', $date))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['method'] ?? null, fn (Builder $q, $method) => $q->where('expected_method', $method))
            ->when($status === 'pending', fn (Builder $q) => $q->where('due_date', '>=', $today))
            ->when($status === 'overdue', fn (Builder $q) => $q->where('due_date', '<', $today))
            ->when($status === 'partial', fn (Builder $q) => $q->where('settled_amount', '>', 0));
    }

    /** Every instalment of one flow with its effective settled amount, organization-scoped, entries not deleted. */
    private function open(FinanceFlow $flow): Builder
    {
        $o = $flow->obligationTable;
        $e = $flow->entryTable;
        $query = DB::table($o)
            ->join($e, fn ($j) => $j->on("{$e}.id", '=', "{$o}.{$flow->entryKey}")->on("{$e}.organization_id", '=', "{$o}.organization_id"))
            ->join('categories', fn ($j) => $j->on('categories.id', '=', "{$e}.category_id")->on('categories.organization_id', '=', "{$e}.organization_id"))
            ->joinSub($this->installments->countsPerEntry($flow), 'counts', 'counts.entry_id', '=', "{$e}.id")
            ->leftJoinSub($this->installments->settledPerObligation($flow), 'settled', 'settled.obligation_id', '=', "{$o}.id")
            ->where("{$o}.organization_id", $this->scope->organizationId())
            ->whereNull("{$e}.deleted_at");
        if ($flow->revenue) {
            $query->join('customers', fn ($j) => $j->on('customers.id', '=', 'revenues.customer_id')->on('customers.organization_id', '=', 'revenues.organization_id'));
        }
        $kind = $flow->revenue ? 'RECEIVABLE' : 'PAYABLE';
        $counterpart = $flow->revenue ? 'customers.name' : 'NULL';

        return $query->selectRaw("'{$kind}' AS kind, {$e}.id AS entry_id, {$o}.id AS obligation_id, {$o}.sequence, {$o}.is_down_payment,
            counts.instalments_count, counts.has_down_payment, {$e}.description, {$counterpart} AS counterpart,
            categories.id AS category_id, categories.name AS category_name, {$o}.due_date, {$o}.expected_method, {$o}.amount,
            COALESCE(settled.settled_amount, 0) AS settled_amount,
            {$o}.amount - COALESCE(settled.settled_amount, 0) AS remaining_amount");
    }

    private function present(object $row, string $today): array
    {
        $remaining = Money::normalize((string) $row->remaining_amount);
        $dueDate = substr((string) $row->due_date, 0, 10);

        return [
            'kind' => $row->kind,
            'id' => (string) $row->obligation_id,
            'entry_id' => (string) $row->entry_id,
            'installment' => Installments::position($row),
            'description' => $row->description,
            'counterpart' => $row->counterpart,
            'category' => ['id' => (string) $row->category_id, 'name' => $row->category_name],
            'due_date' => $dueDate,
            'expected_method' => $row->expected_method,
            'amount' => Money::normalize((string) $row->amount),
            'settled_amount' => Money::normalize((string) $row->settled_amount),
            'remaining_amount' => $remaining,
            'status' => FinanceFlow::for($row->kind === 'RECEIVABLE')->statusFor($remaining, $dueDate, $today),
        ];
    }
}
