<?php

namespace App\Queries;

use App\Support\FinanceFlow;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Obligations (instalments) of revenues/expenses with their effective settled amounts (ADR-031/032).
 * Settlements are summed per obligation before any join, so several receipts/payments or instalments never
 * multiply a total, and corrected (superseded) settlements never count.
 */
final class Installments
{
    public function __construct(private OrganizationContext $context) {}

    /** Effective settlement total per obligation, restricted to the authorized organization. */
    public function settledPerObligation(FinanceFlow $flow): Builder
    {
        return DB::table($flow->settlementTable)
            ->where('organization_id', $this->context->organization()->id)
            ->whereNull('superseded_at')
            ->groupBy($flow->obligationKey)
            ->selectRaw("{$flow->obligationKey} AS obligation_id, SUM(amount) AS settled_amount");
    }

    /** Number of instalments per entry and whether it has a down payment, to label "Parcela n de m". */
    public function countsPerEntry(FinanceFlow $flow): Builder
    {
        return DB::table($flow->obligationTable)
            ->where('organization_id', $this->context->organization()->id)
            ->groupBy($flow->entryKey)
            ->selectRaw("{$flow->entryKey} AS entry_id, COUNT(*) AS instalments_count, MAX(is_down_payment) AS has_down_payment");
    }

    /** Public label data of one instalment; the down payment is not counted as an instalment proper. */
    public static function position(object $row): array
    {
        return [
            'sequence' => (int) $row->sequence,
            'number' => (int) $row->sequence - (int) $row->has_down_payment,
            'total' => (int) $row->instalments_count - (int) $row->has_down_payment,
            'is_down_payment' => (bool) $row->is_down_payment,
        ];
    }

    /**
     * One row per entry: scheduled amount, settled amount, earliest open due date (null when settled) and
     * last due date. Used by the lists to filter and sort without loading every instalment.
     */
    public function totalsPerEntry(FinanceFlow $flow): Builder
    {
        $o = $flow->obligationTable;

        return DB::table($o)
            ->leftJoinSub($this->settledPerObligation($flow), 'settled', 'settled.obligation_id', '=', "{$o}.id")
            ->where("{$o}.organization_id", $this->context->organization()->id)
            ->groupBy("{$o}.{$flow->entryKey}")
            ->selectRaw("{$o}.{$flow->entryKey} AS entry_id,
                SUM({$o}.amount) AS scheduled_amount,
                SUM(COALESCE(settled.settled_amount, 0)) AS settled_amount,
                MIN(CASE WHEN {$o}.amount - COALESCE(settled.settled_amount, 0) > 0 THEN {$o}.due_date END) AS next_due_date,
                MAX({$o}.due_date) AS last_due_date,
                COUNT(*) AS instalments_count");
    }

    /**
     * Instalments of the given entries, ordered by sequence, as public arrays keyed by entry id.
     *
     * @param  list<int>  $entryIds
     * @return Collection<int, list<array>>
     */
    public function forEntries(FinanceFlow $flow, array $entryIds): Collection
    {
        if ($entryIds === []) {
            return collect();
        }
        $o = $flow->obligationTable;
        $today = CarbonImmutable::now($this->context->organization()->timezone)->toDateString();

        return DB::table($o)
            ->leftJoinSub($this->settledPerObligation($flow), 'settled', 'settled.obligation_id', '=', "{$o}.id")
            ->where("{$o}.organization_id", $this->context->organization()->id)
            ->whereIn("{$o}.{$flow->entryKey}", $entryIds)
            ->orderBy("{$o}.{$flow->entryKey}")->orderBy("{$o}.sequence")
            ->select("{$o}.*")
            ->selectRaw('COALESCE(settled.settled_amount, 0) AS settled_amount')
            ->get()
            ->groupBy(fn ($row) => (int) $row->{$flow->entryKey})
            ->map(fn (Collection $rows) => $rows->map(fn ($row) => self::present($flow, $row, $today))->values()->all());
    }

    private static function present(FinanceFlow $flow, object $row, string $today): array
    {
        $amount = Money::normalize((string) $row->amount);
        $settled = Money::normalize(bcadd((string) $row->settled_amount, '0', 2));
        $remaining = Money::sub($amount, $settled);
        $due = substr((string) $row->due_date, 0, 10);

        return [
            'id' => (string) $row->id,
            'sequence' => (int) $row->sequence,
            'is_down_payment' => (bool) $row->is_down_payment,
            'amount' => $amount,
            'settled_amount' => $settled,
            'remaining_amount' => $remaining,
            'due_date' => $due,
            'reminder_date' => $row->reminder_date ? substr((string) $row->reminder_date, 0, 10) : null,
            'expected_method' => $row->expected_method,
            'status' => $flow->statusFor($remaining, $due, $today),
        ];
    }

    /**
     * Entry-level view of a schedule: totals, status (overdue when any open instalment is overdue) and the
     * next open instalment, kept as `receivable`/`payable` for clients written for single obligations.
     */
    public static function summary(FinanceFlow $flow, array $instalments, string $today): array
    {
        $amount = $settled = '0.00';
        foreach ($instalments as $item) {
            $amount = Money::add($amount, $item['amount']);
            $settled = Money::add($settled, $item['settled_amount']);
        }
        $remaining = Money::sub($amount, $settled);
        $open = array_values(array_filter($instalments, fn ($item) => Money::compare($item['remaining_amount'], '0.00') > 0));
        usort($open, fn ($a, $b) => [$a['due_date'], $a['sequence']] <=> [$b['due_date'], $b['sequence']]);
        $next = $open[0] ?? ($instalments === [] ? null : $instalments[array_key_last($instalments)]);

        return [
            'scheduled' => $amount,
            'settled' => $settled,
            'remaining' => $remaining,
            'status' => $next === null ? $flow->settledStatus : $flow->statusFor($remaining, $next['due_date'], $today),
            'next' => $next === null ? null : [
                'id' => $next['id'], 'amount' => $next['amount'], 'due_date' => $next['due_date'], 'expected_method' => $next['expected_method'],
            ],
        ];
    }
}
