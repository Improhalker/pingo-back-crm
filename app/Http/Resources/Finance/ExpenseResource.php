<?php

namespace App\Http\Resources\Finance;

use App\Queries\Installments;
use App\Support\FinanceFlow;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** ExpenseQuery projection with its `schedule`. Only effective payments count as paid; money stays strings. */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::now(app(OrganizationContext::class)->organization()->timezone)->toDateString();
        $schedule = $this->schedule ?? [];
        $summary = Installments::summary(FinanceFlow::expense(), $schedule, $today);

        return [
            'id' => (string) $this->id,
            'version' => (int) $this->version,
            'description' => $this->description,
            'category' => ['id' => (string) $this->category_id, 'name' => $this->category_name, 'archived' => $this->category_archived_at !== null],
            'total_amount' => Money::normalize((string) $this->total_amount),
            'notes' => $this->notes,
            'paid_amount' => $summary['settled'],
            'remaining_amount' => $summary['remaining'],
            'status' => $summary['status'],
            // Next open instalment (or the last one when settled), for clients written for one obligation.
            'payable' => $summary['next'],
            'installments' => $schedule,
            'payments' => $this->when($this->relationLoaded('paymentList'), fn () => SettlementData::list($this->paymentList, 'paid_at', $schedule)),
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
