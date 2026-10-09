<?php

namespace App\Queries;

use App\Support\FinanceFlow;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Internal reminders (card 10, ADR-032): open instalments whose reminder date has arrived in the company's
 * calendar, plus the ones arriving in the next days. Only the remaining amount is shown; settled instalments
 * and deleted entries never appear. A reminder never settles anything: the person reviews and registers.
 */
final class ReminderQuery
{
    public const UPCOMING_DAYS = 7;

    public const LIMIT = 50;

    public function __construct(private OrganizationContext $context, private Installments $installments) {}

    public function get(): array
    {
        $timezone = $this->context->organization()->timezone;
        $today = CarbonImmutable::now($timezone)->toDateString();
        $until = CarbonImmutable::now($timezone)->addDays(self::UPCOMING_DAYS)->toDateString();

        return [
            'today' => $today,
            'upcoming_until' => $until,
            'due' => $this->section(fn (Builder $q) => $q->where('reminder_date', '<=', $today), $today),
            'upcoming' => $this->section(fn (Builder $q) => $q->where('reminder_date', '>', $today)->where('reminder_date', '<=', $until), $today),
        ];
    }

    private function section(\Closure $filter, string $today): array
    {
        $base = $filter(DB::query()->fromSub($this->open(FinanceFlow::revenue())->unionAll($this->open(FinanceFlow::expense())), 'reminders'));
        $total = (clone $base)->count();
        $items = $base->orderBy('reminder_date')->orderBy('due_date')->orderBy('kind')->orderBy('installment_id')->limit(self::LIMIT)->get()
            ->map(function ($row) use ($today) {
                $remaining = Money::normalize((string) $row->remaining_amount);
                $flow = FinanceFlow::for($row->kind === 'RECEIVABLE');

                return [
                    'kind' => $row->kind,
                    'entry_id' => (string) $row->entry_id,
                    'installment_id' => (string) $row->installment_id,
                    'sequence' => (int) $row->sequence,
                    'instalments_count' => (int) $row->instalments_count,
                    // Position among the instalments proper (the down payment is not counted as one).
                    'number' => (int) $row->sequence - (int) $row->has_down_payment,
                    'instalments_total' => (int) $row->instalments_count - (int) $row->has_down_payment,
                    'is_down_payment' => (bool) $row->is_down_payment,
                    'description' => $row->description,
                    'counterpart' => $row->counterpart,
                    'reminder_date' => substr((string) $row->reminder_date, 0, 10),
                    'due_date' => substr((string) $row->due_date, 0, 10),
                    'expected_method' => $row->expected_method,
                    'amount' => Money::normalize((string) $row->amount),
                    'remaining_amount' => $remaining,
                    'status' => $flow->statusFor($remaining, substr((string) $row->due_date, 0, 10), $today),
                ];
            })->all();

        return ['total' => $total, 'items' => $items];
    }

    /** Open instalments with a reminder of one flow, with their entry and counterpart, organization-scoped. */
    private function open(FinanceFlow $flow): Builder
    {
        $o = $flow->obligationTable;
        $e = $flow->entryTable;
        $organizationId = $this->context->organization()->id;
        $counts = $this->installments->countsPerEntry($flow);
        $query = DB::table($o)
            ->join($e, fn ($j) => $j->on("{$e}.id", '=', "{$o}.{$flow->entryKey}")->on("{$e}.organization_id", '=', "{$o}.organization_id"))
            ->joinSub($counts, 'counts', 'counts.entry_id', '=', "{$o}.{$flow->entryKey}")
            ->leftJoinSub($this->installments->settledPerObligation($flow), 'settled', 'settled.obligation_id', '=', "{$o}.id")
            ->where("{$o}.organization_id", $organizationId)
            ->whereNotNull("{$o}.reminder_date")
            ->whereNull("{$e}.deleted_at")
            ->whereRaw("{$o}.amount - COALESCE(settled.settled_amount, 0) > 0");
        if ($flow->revenue) {
            $query->join('customers', fn ($j) => $j->on('customers.id', '=', 'revenues.customer_id')->on('customers.organization_id', '=', 'revenues.organization_id'));
            $counterpart = 'customers.name';
        } else {
            $query->join('categories', fn ($j) => $j->on('categories.id', '=', 'expenses.category_id')->on('categories.organization_id', '=', 'expenses.organization_id'));
            $counterpart = 'categories.name';
        }
        $kind = $flow->revenue ? 'RECEIVABLE' : 'PAYABLE';

        return $query->selectRaw("'{$kind}' AS kind, {$e}.id AS entry_id, {$o}.id AS installment_id, {$o}.sequence, counts.instalments_count, counts.has_down_payment,
            {$o}.is_down_payment, {$e}.description, {$counterpart} AS counterpart, {$o}.reminder_date, {$o}.due_date, {$o}.expected_method,
            {$o}.amount, {$o}.amount - COALESCE(settled.settled_amount, 0) AS remaining_amount");
    }
}
