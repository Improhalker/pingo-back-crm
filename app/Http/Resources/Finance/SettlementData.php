<?php

namespace App\Http\Resources\Finance;

use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Public view of receipts/payments, including corrected ones (ADR-031). A corrected row keeps its original
 * values and points to its replacement; only EFFECTIVE rows count in totals.
 */
final class SettlementData
{
    /** @param  list<array>  $schedule */
    public static function list(Collection $rows, string $instant, array $schedule): array
    {
        $sequence = collect($schedule)->mapWithKeys(fn ($item) => [$item['id'] => $item['sequence']]);
        $replacedBy = $rows->filter(fn ($row) => $row->corrects_id !== null)->mapWithKeys(fn ($row) => [(string) $row->corrects_id => (string) $row->id]);

        return $rows->map(function ($row) use ($instant, $sequence, $replacedBy) {
            $obligation = (string) ($row->receivable_id ?? $row->payable_id);

            return [
                'id' => (string) $row->id,
                'installment_id' => $obligation,
                'installment_sequence' => $sequence[$obligation] ?? null,
                'amount' => Money::normalize((string) $row->amount),
                'method' => $row->method,
                $instant => $row->{$instant}->utc()->format('Y-m-d\TH:i:s.vP'),
                'created_by' => $row->author?->name,
                'registered_at' => $row->created_at?->utc()->format('Y-m-d\TH:i:s.vP'),
                'status' => $row->superseded_at === null ? 'EFFECTIVE' : 'CORRECTED',
                'corrects_id' => $row->corrects_id === null ? null : (string) $row->corrects_id,
                'correction_reason' => $row->correction_reason,
                'superseded_at' => $row->superseded_at?->utc()->format('Y-m-d\TH:i:s.vP'),
                'replaced_by_id' => $replacedBy[(string) $row->id] ?? null,
            ];
        })->values()->all();
    }
}
