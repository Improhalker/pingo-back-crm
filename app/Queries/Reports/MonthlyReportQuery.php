<?php

namespace App\Queries\Reports;

use App\Support\FinanceFlow;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Monthly report (card 11, ADR-033), one row per local month:
 * - cash (income, expenses, result): effective receipts/payments by the local month of the instant, the same
 *   rule and sums as the dashboard, so one month always matches it;
 * - sold: revenue totals by sale date; due expenses: expense instalments by due date (an expense has no
 *   contract date). These are not cash and are labelled with their own base;
 * - cash by category for the whole period.
 * Every sum is done by MySQL DECIMAL and returned as exact strings; no row lists are loaded.
 */
final class MonthlyReportQuery
{
    public const MAX_MONTHS = 12;

    public function __construct(private ReportScope $scope) {}

    /** @param string $from first month (YYYY-MM) @param string $to last month (YYYY-MM), at most 12 months */
    public function get(string $from, string $to): array
    {
        $timezone = $this->scope->timezone();
        $months = [];
        for ($month = CarbonImmutable::createFromFormat('!Y-m', $from, $timezone); $month->format('Y-m') <= $to; $month = $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m', $from, $timezone)->startOfMonth();
        $end = CarbonImmutable::createFromFormat('!Y-m', $to, $timezone)->startOfMonth()->addMonth();

        return $this->scope->read(function () use ($months, $start, $end) {
            $income = $this->cashByMonth(FinanceFlow::revenue(), $months);
            $expenses = $this->cashByMonth(FinanceFlow::expense(), $months);
            $sold = $this->civilByMonth(
                DB::table('revenues')->where('organization_id', $this->scope->organizationId())->whereNull('deleted_at'),
                'sale_date', 'total_amount', $start, $end,
            );
            $due = $this->civilByMonth(
                DB::table('payables')->join('expenses', fn ($j) => $j->on('expenses.id', '=', 'payables.expense_id')->on('expenses.organization_id', '=', 'payables.organization_id'))
                    ->where('payables.organization_id', $this->scope->organizationId())->whereNull('expenses.deleted_at'),
                'payables.due_date', 'payables.amount', $start, $end,
            );

            $rows = [];
            $totals = ['income' => '0.00', 'expenses' => '0.00', 'result' => '0.00', 'sold' => '0.00', 'expenses_due' => '0.00'];
            $previous = null;
            foreach ($months as $month) {
                $row = [
                    'month' => $month,
                    'income' => $income[$month] ?? '0.00',
                    'expenses' => $expenses[$month] ?? '0.00',
                ];
                $row['result'] = Money::sub($row['income'], $row['expenses']);
                // Change of the cash result against the previous month of the period (null for the first one).
                $row['result_change'] = $previous === null ? null : Money::sub($row['result'], $previous);
                $row['sold'] = $sold[$month] ?? '0.00';
                $row['expenses_due'] = $due[$month] ?? '0.00';
                foreach (array_keys($totals) as $key) {
                    $totals[$key] = Money::add($totals[$key], $row[$key]);
                }
                $previous = $row['result'];
                $rows[] = $row;
            }

            return [
                ...$this->scope->header([
                    'from' => $months[0], 'to' => end($months),
                    'starts_on' => $start->toDateString(), 'ends_on' => $end->subDay()->toDateString(),
                ], 'CASH'),
                'months' => $rows,
                'totals' => $totals,
                'categories' => [
                    'income' => $this->cashByCategory(FinanceFlow::revenue(), $start, $end, $totals['income']),
                    'expenses' => $this->cashByCategory(FinanceFlow::expense(), $start, $end, $totals['expenses']),
                ],
            ];
        });
    }

    /** Effective settlements per local month: one CASE bucket per month, bounds converted to UTC. */
    private function cashByMonth(FinanceFlow $flow, array $months): array
    {
        $instant = $flow->instant;
        $cases = [];
        $bindings = [];
        foreach ($months as $month) {
            $first = "{$month}-01";
            $last = CarbonImmutable::createFromFormat('!Y-m-d', $first)->endOfMonth()->toDateString();
            $cases[] = "WHEN {$instant} >= ? AND {$instant} < ? THEN ?";
            array_push($bindings, $this->scope->dayStartsAt($first), $this->scope->dayEndsBefore($last), $month);
        }
        $lastMonth = end($months);

        return DB::table($flow->settlementTable)
            ->where('organization_id', $this->scope->organizationId())
            ->whereNull('superseded_at')
            ->where($instant, '>=', $this->scope->dayStartsAt("{$months[0]}-01"))
            ->where($instant, '<', $this->scope->dayEndsBefore(CarbonImmutable::createFromFormat('!Y-m-d', "{$lastMonth}-01")->endOfMonth()->toDateString()))
            ->selectRaw('CASE '.implode(' ', $cases).' END AS bucket, SUM(amount) AS total', $bindings)
            ->groupBy('bucket')->get()
            ->mapWithKeys(fn ($row) => [$row->bucket => Money::normalize((string) $row->total)])->all();
    }

    /** Civil dates (no timezone conversion) grouped by month. */
    private function civilByMonth(Builder $query, string $date, string $amount, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $query->where($date, '>=', $start->toDateString())->where($date, '<', $end->toDateString())
            ->selectRaw("DATE_FORMAT({$date}, '%Y-%m') AS bucket, SUM({$amount}) AS total")
            ->groupBy('bucket')->get()
            ->mapWithKeys(fn ($row) => [$row->bucket => Money::normalize((string) $row->total)])->all();
    }

    /** Effective settlements of the period per category of their entry, largest first. */
    private function cashByCategory(FinanceFlow $flow, CarbonImmutable $start, CarbonImmutable $end, string $total): array
    {
        $s = $flow->settlementTable;
        $o = $flow->obligationTable;
        $e = $flow->entryTable;

        return DB::table($s)
            ->join($o, fn ($j) => $j->on("{$o}.id", '=', "{$s}.{$flow->obligationKey}")->on("{$o}.organization_id", '=', "{$s}.organization_id"))
            ->join($e, fn ($j) => $j->on("{$e}.id", '=', "{$o}.{$flow->entryKey}")->on("{$e}.organization_id", '=', "{$o}.organization_id"))
            ->join('categories', fn ($j) => $j->on('categories.id', '=', "{$e}.category_id")->on('categories.organization_id', '=', "{$e}.organization_id"))
            ->where("{$s}.organization_id", $this->scope->organizationId())
            ->whereNull("{$s}.superseded_at")
            ->where("{$s}.{$flow->instant}", '>=', $start->utc()->format('Y-m-d H:i:s.u'))
            ->where("{$s}.{$flow->instant}", '<', $end->utc()->format('Y-m-d H:i:s.u'))
            ->groupBy('categories.id', 'categories.name', 'categories.archived_at')
            ->selectRaw('categories.id, categories.name, categories.archived_at, SUM('.$s.'.amount) AS total, COUNT(*) AS movements')
            ->orderByDesc('total')->orderBy('categories.name')->orderBy('categories.id')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'name' => $row->name,
                'archived' => $row->archived_at !== null,
                'amount' => Money::normalize((string) $row->total),
                'movements' => (int) $row->movements,
                // Share of the period total in basis points (1/100 of a percent), integer arithmetic only.
                'share_bp' => Money::isPositive($total) ? (int) bcdiv(bcmul(Money::normalize((string) $row->total), '10000', 2), $total, 0) : 0,
            ])->all();
    }
}
