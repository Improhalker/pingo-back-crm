<?php

namespace App\Queries;

use App\Models\Payable;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Receivable;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only cash dashboard. DECIMAL sums, civil days in the company timezone and one InnoDB snapshot. */
final class DashboardQuery
{
    public function __construct(private OrganizationContext $context) {}

    public function get(?string $month = null): array
    {
        $timezone = $this->context->organization()->timezone;
        $now = CarbonImmutable::now($timezone);
        $month ??= $now->format('Y-m');
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone)->startOfMonth();
        $end = $start->addMonth()->startOfMonth();
        $today = $now->toDateString();
        $horizon = $now->addDays(30)->toDateString();

        // MySQL's default can be changed outside this project. Pin the NEXT transaction's isolation;
        // do not change the connection/session default. Tests may already hold a REPEATABLE READ transaction.
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction(function () use ($now, $month, $timezone, $start, $end, $today, $horizon) {
            $incoming = $this->daily(Receipt::owned()->effective()->toBase(), 'received_at', $start, $end);
            $outgoing = $this->daily(Payment::owned()->effective()->toBase(), 'paid_at', $start, $end);
            $income = $expenses = '0.00';
            $series = [];
            for ($day = $start; $day < $end; $day = $day->addDay()->startOfDay()) {
                $date = $day->toDateString();
                $received = $incoming[$date] ?? '0.00';
                $paid = $outgoing[$date] ?? '0.00';
                $income = Money::add($income, $received);
                $expenses = Money::add($expenses, $paid);
                $series[] = ['date' => $date, 'income' => $received, 'expenses' => $paid];
            }

            $totals = $this->outstanding()->selectRaw(
                "COALESCE(SUM(CASE WHEN kind = 'RECEIVABLE' THEN remaining_amount ELSE 0 END), 0) AS receivable,
                 COALESCE(SUM(CASE WHEN kind = 'PAYABLE' THEN remaining_amount ELSE 0 END), 0) AS payable,
                 COALESCE(SUM(CASE WHEN kind = 'RECEIVABLE' AND due_date < ? THEN remaining_amount ELSE 0 END), 0) AS overdue_receivable,
                 COALESCE(SUM(CASE WHEN kind = 'PAYABLE' AND due_date < ? THEN remaining_amount ELSE 0 END), 0) AS overdue_payable,
                 COUNT(CASE WHEN due_date < ? THEN 1 END) AS overdue_count,
                 COUNT(CASE WHEN due_date >= ? AND due_date <= ? THEN 1 END) AS upcoming_count",
                [$today, $today, $today, $today, $horizon],
            )->first();

            return [
                'period' => ['month' => $month, 'starts_on' => $start->toDateString(), 'ends_on' => $end->subDay()->toDateString(), 'timezone' => $timezone],
                'as_of' => $now->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'today' => $today,
                'monthly' => ['income' => $income, 'expenses' => $expenses, 'result' => Money::sub($income, $expenses)],
                'outstanding' => [
                    'receivable' => Money::normalize((string) $totals->receivable),
                    'payable' => Money::normalize((string) $totals->payable),
                    'overdue_receivable' => Money::normalize((string) $totals->overdue_receivable),
                    'overdue_payable' => Money::normalize((string) $totals->overdue_payable),
                ],
                'daily' => $series,
                'due' => [
                    'upcoming_until' => $horizon,
                    'overdue' => ['total' => (int) $totals->overdue_count, 'items' => $this->dueItems($today, $horizon, true)],
                    'upcoming' => ['total' => (int) $totals->upcoming_count, 'items' => $this->dueItems($today, $horizon, false)],
                ],
            ];
        });
    }

    /** At most 31 buckets, calculated by the database. No timezone tables or raw movement lists needed. */
    private function daily(Builder $query, string $instant, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cases = [];
        $bindings = [];
        for ($day = $start; $day < $end; $day = $day->addDay()->startOfDay()) {
            $cases[] = "WHEN {$instant} >= ? AND {$instant} < ? THEN ?";
            array_push($bindings, $day->utc()->format('Y-m-d H:i:s.u'), $day->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.u'), $day->toDateString());
        }

        return $query->where($instant, '>=', $start->utc()->format('Y-m-d H:i:s.u'))
            ->where($instant, '<', $end->utc()->format('Y-m-d H:i:s.u'))
            ->selectRaw('CASE '.implode(' ', $cases).' END AS civil_day, SUM(amount) AS total', $bindings)
            ->groupBy('civil_day')->get()->mapWithKeys(fn ($row) => [$row->civil_day => Money::normalize((string) $row->total)])->all();
    }

    /** Aggregate each obligation's settlements first: multiple receipts/payments never duplicate principal. */
    private function outstanding(): Builder
    {
        $receipts = Receipt::owned()->effective()->toBase()->selectRaw('receivable_id, SUM(amount) AS amount')->groupBy('receivable_id');
        $payments = Payment::owned()->effective()->toBase()->selectRaw('payable_id, SUM(amount) AS amount')->groupBy('payable_id');
        $receivables = Receivable::owned()->toBase()
            ->join('revenues', fn ($j) => $j->on('revenues.id', '=', 'receivables.revenue_id')->on('revenues.organization_id', '=', 'receivables.organization_id'))
            ->join('customers', fn ($j) => $j->on('customers.id', '=', 'revenues.customer_id')->on('customers.organization_id', '=', 'revenues.organization_id'))
            ->leftJoinSub($receipts, 'settled', 'settled.receivable_id', '=', 'receivables.id')
            ->selectRaw("'RECEIVABLE' AS kind, revenues.id AS source_id, receivables.id AS obligation_id, revenues.description, customers.name AS counterpart, receivables.due_date, GREATEST(receivables.amount - COALESCE(settled.amount, 0), 0) AS remaining_amount");
        $receivables->whereNull('revenues.deleted_at');
        $payables = Payable::owned()->toBase()
            ->join('expenses', fn ($j) => $j->on('expenses.id', '=', 'payables.expense_id')->on('expenses.organization_id', '=', 'payables.organization_id'))
            ->join('categories', fn ($j) => $j->on('categories.id', '=', 'expenses.category_id')->on('categories.organization_id', '=', 'expenses.organization_id'))
            ->leftJoinSub($payments, 'settled', 'settled.payable_id', '=', 'payables.id')
            ->selectRaw("'PAYABLE' AS kind, expenses.id AS source_id, payables.id AS obligation_id, expenses.description, categories.name AS counterpart, payables.due_date, GREATEST(payables.amount - COALESCE(settled.amount, 0), 0) AS remaining_amount");

        $payables->whereNull('expenses.deleted_at');

        return DB::query()->fromSub($receivables->unionAll($payables), 'obligations')->where('remaining_amount', '>', 0);
    }

    private function dueItems(string $today, string $horizon, bool $overdue): array
    {
        return $this->outstanding()
            ->when($overdue, fn ($q) => $q->where('due_date', '<', $today), fn ($q) => $q->whereBetween('due_date', [$today, $horizon]))
            ->orderBy('due_date')->orderBy('kind')->orderBy('obligation_id')->limit(6)->get()
            ->map(fn ($row) => [
                'kind' => $row->kind,
                'source_id' => (string) $row->source_id,
                'obligation_id' => (string) $row->obligation_id,
                'description' => $row->description,
                'counterpart' => $row->counterpart,
                'due_date' => $row->due_date,
                'remaining_amount' => Money::normalize((string) $row->remaining_amount),
            ])->all();
    }
}
