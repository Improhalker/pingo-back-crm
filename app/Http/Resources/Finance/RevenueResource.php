<?php

namespace App\Http\Resources\Finance;

use App\Queries\Installments;
use App\Support\FinanceFlow;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Revenue loaded through RevenueQuery with its `schedule` (instalments) attached by the controller. Money as
 * canonical strings; sale/due/reminder dates as civil YYYY-MM-DD in the organization timezone; instants as
 * ISO 8601 UTC. Totals count effective receipts only. `receipts` (with corrections) only on the detail.
 */
class RevenueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = CarbonImmutable::now(app(OrganizationContext::class)->organization()->timezone)->toDateString();
        $schedule = $this->schedule ?? [];
        $summary = Installments::summary(FinanceFlow::revenue(), $schedule, $today);

        return [
            'id' => (string) $this->id,
            'version' => (int) $this->version,
            'description' => $this->description,
            'customer' => ['id' => (string) $this->customer_id, 'name' => $this->customer_name, 'archived' => $this->customer_archived_at !== null],
            'category' => ['id' => (string) $this->category_id, 'name' => $this->category_name, 'archived' => $this->category_archived_at !== null],
            'total_amount' => Money::normalize((string) $this->total_amount),
            'sale_date' => $this->sale_date->toDateString(),
            'notes' => $this->notes,
            'received_amount' => $summary['settled'],
            'remaining_amount' => $summary['remaining'],
            'status' => $summary['status'],
            // Next open instalment (or the last one when settled), for clients written for one obligation.
            'receivable' => $summary['next'],
            'installments' => $schedule,
            'receipts' => $this->when($this->relationLoaded('receiptList'), fn () => SettlementData::list($this->receiptList, 'received_at', $schedule)),
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }
}
