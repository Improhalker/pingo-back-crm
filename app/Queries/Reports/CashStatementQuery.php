<?php

namespace App\Queries\Reports;

use App\Queries\Installments;
use App\Support\FinanceFlow;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cash statement (card 11, ADR-033): receipts and payments actually registered, in chronological order, by
 * the local day of the settlement instant. Only effective rows count in the totals, the same rule as the
 * dashboard; corrected rows can be listed for reference. Each row joins exactly one obligation, entry and
 * category, so the joins never multiply amounts. Deterministic and parameterised: reusable by future
 * read-only Pingo queries without a request.
 */
final class CashStatementQuery
{
    public const MAX_DAYS = 366;

    public function __construct(private ReportScope $scope, private Installments $installments) {}

    /**
     * Filters: type income|expense; status effective (default)|corrected|all; order asc (default)|desc.
     *
     * @param  array{from: string, to: string, type?: ?string, category_id?: ?int, method?: ?string, status?: ?string, order?: ?string}  $filters
     */
    public function get(array $filters, int $page = 1, int $perPage = 25): array
    {
        return $this->scope->read(function () use ($filters, $page, $perPage) {
            $rows = $this->filtered($filters);
            $totals = (clone $rows)->selectRaw(
                "COALESCE(SUM(CASE WHEN kind = 'INCOME' AND superseded_at IS NULL THEN amount ELSE 0 END), 0) AS income,
                 COALESCE(SUM(CASE WHEN kind = 'EXPENSE' AND superseded_at IS NULL THEN amount ELSE 0 END), 0) AS expenses,
                 COUNT(CASE WHEN superseded_at IS NULL THEN 1 END) AS effective_count,
                 COUNT(*) AS total_count",
            )->first();
            $total = (int) $totals->total_count;
            $direction = ($filters['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            $items = $rows->select('*')
                ->orderBy('occurred_at', $direction)->orderBy('kind', $direction)->orderBy('settlement_id', $direction)
                ->offset(($page - 1) * $perPage)->limit($perPage)->get()
                ->map(fn ($row) => $this->present($row))->all();
            $income = Money::normalize((string) $totals->income);
            $expenses = Money::normalize((string) $totals->expenses);

            return [
                ...$this->scope->header(['starts_on' => $filters['from'], 'ends_on' => $filters['to']], 'CASH'),
                'summary' => [
                    'income' => $income,
                    'expenses' => $expenses,
                    'result' => Money::sub($income, $expenses),
                    'effective_count' => (int) $totals->effective_count,
                ],
                'data' => $items,
                'meta' => ReportScope::meta($page, $perPage, $total),
            ];
        });
    }

    private function filtered(array $filters): Builder
    {
        $type = $filters['type'] ?? null;
        $parts = [];
        if ($type !== 'expense') {
            $parts[] = $this->movements(FinanceFlow::revenue(), $filters['from'], $filters['to']);
        }
        if ($type !== 'income') {
            $parts[] = $this->movements(FinanceFlow::expense(), $filters['from'], $filters['to']);
        }
        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }
        $status = $filters['status'] ?? 'effective';

        return DB::query()->fromSub($union, 'movements')
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['method'] ?? null, fn (Builder $q, $method) => $q->where('method', $method))
            ->when($status === 'effective', fn (Builder $q) => $q->whereNull('superseded_at'))
            ->when($status === 'corrected', fn (Builder $q) => $q->whereNotNull('superseded_at'));
    }

    /** Settlements of one flow whose instant falls in the local civil period [from, to]. */
    private function movements(FinanceFlow $flow, string $from, string $to): Builder
    {
        $s = $flow->settlementTable;
        $o = $flow->obligationTable;
        $e = $flow->entryTable;
        $query = DB::table($s)
            ->join($o, fn ($j) => $j->on("{$o}.id", '=', "{$s}.{$flow->obligationKey}")->on("{$o}.organization_id", '=', "{$s}.organization_id"))
            ->join($e, fn ($j) => $j->on("{$e}.id", '=', "{$o}.{$flow->entryKey}")->on("{$e}.organization_id", '=', "{$o}.organization_id"))
            ->join('categories', fn ($j) => $j->on('categories.id', '=', "{$e}.category_id")->on('categories.organization_id', '=', "{$e}.organization_id"))
            ->joinSub($this->installments->countsPerEntry($flow), 'counts', 'counts.entry_id', '=', "{$e}.id")
            ->where("{$s}.organization_id", $this->scope->organizationId())
            ->where("{$s}.{$flow->instant}", '>=', $this->scope->dayStartsAt($from))
            ->where("{$s}.{$flow->instant}", '<', $this->scope->dayEndsBefore($to));
        if ($flow->revenue) {
            $query->join('customers', fn ($j) => $j->on('customers.id', '=', 'revenues.customer_id')->on('customers.organization_id', '=', 'revenues.organization_id'));
        }
        $kind = $flow->revenue ? 'INCOME' : 'EXPENSE';
        $counterpart = $flow->revenue ? 'customers.name' : 'NULL';

        return $query->selectRaw("'{$kind}' AS kind, {$s}.id AS settlement_id, {$e}.id AS entry_id, {$o}.id AS installment_id,
            {$o}.sequence, {$o}.is_down_payment, counts.instalments_count, counts.has_down_payment, {$e}.description,
            {$counterpart} AS counterpart, categories.id AS category_id, categories.name AS category_name, {$s}.method,
            {$s}.{$flow->instant} AS occurred_at, {$s}.amount, {$s}.corrects_id, {$s}.superseded_at");
    }

    private function present(object $row): array
    {
        return [
            'kind' => $row->kind,
            'id' => (string) $row->settlement_id,
            'entry_id' => (string) $row->entry_id,
            'installment_id' => (string) $row->installment_id,
            'installment' => Installments::position($row),
            'description' => $row->description,
            'counterpart' => $row->counterpart,
            'category' => ['id' => (string) $row->category_id, 'name' => $row->category_name],
            'method' => $row->method,
            'occurred_at' => ReportScope::iso((string) $row->occurred_at),
            'local_date' => $this->scope->localDate((string) $row->occurred_at),
            'amount' => Money::normalize((string) $row->amount),
            'status' => $row->superseded_at === null ? 'EFFECTIVE' : 'CORRECTED',
            'is_correction' => $row->corrects_id !== null,
        ];
    }
}
