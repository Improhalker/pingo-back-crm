<?php

namespace App\Actions\Financial;

use App\Models\FinancialRecurrence;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GenerateRecurringEntries
{
    public function __construct(
        private OrganizationContext $context,
        private AuditTrail $audit,
        private CreateRevenue $revenue,
        private CreateExpense $expense,
    ) {}

    /** One locked series advances monotonically. Each batch is atomic with entries, obligations and audit. */
    public function run(int $id): int
    {
        return DB::transaction(function () use ($id) {
            $series = FinancialRecurrence::owned()->whereKey($id)->lockForUpdate()->firstOrFail();

            return $this->generate($series);
        }, 3);
    }

    /** Caller holds the series lock, or just created it in the same transaction. */
    public function generate(FinancialRecurrence $series): int
    {
        if ($series->status !== 'ACTIVE' || $this->context->organization()->isSuspended()) {
            return 0;
        }
        $horizon = CarbonImmutable::now($this->context->organization()->timezone)->startOfMonth()->addMonth();
        $limit = min($series->ends_on?->toDateString() ?? '2100-12-01', $horizon->toDateString());
        $cursor = $series->next_month;
        $flow = $series->flow();
        $generated = 0;

        while ($cursor->toDateString() <= $limit && $generated < 24) {
            $due = $series->dueIn($cursor);
            $amount = $series->amountIn($cursor);
            $actor = User::findOrFail($series->created_by);
            $data = [
                'category_id' => $series->category_id,
                'customer_id' => $series->customer_id,
                'description' => $series->description,
                'total_amount' => $amount,
                'sale_date' => $cursor->toDateString(),
                'notes' => $series->notes,
                'installments' => [[
                    'amount' => $amount,
                    'down_payment' => false,
                    'due_date' => $due->toDateString(),
                    'expected_method' => $series->expected_method,
                    'reminder_date' => $series->reminder_days === null ? null : $due->subDays($series->reminder_days)->toDateString(),
                    'settlement' => null,
                ]],
            ];
            $entry = $flow->revenue ? ($this->revenue)($actor, $data) : ($this->expense)($actor, $data);
            $entry->forceFill(['financial_recurrence_id' => $series->id, 'recurrence_month' => $cursor->toDateString()])->save();

            $this->audit->record($actor, 'recurrence.generated', $series, null, [
                'source' => 'monthly_rule', 'kind' => $series->kind, 'entry_id' => $entry->id,
                'month' => $cursor->format('Y-m'), 'due_date' => $due->toDateString(), 'amount' => $amount,
            ]);
            $cursor = $cursor->addMonth();
            $generated++;
        }

        if ($generated) {
            $series->forceFill(['next_month' => $cursor->toDateString()])->save();
        }

        return $generated;
    }
}
