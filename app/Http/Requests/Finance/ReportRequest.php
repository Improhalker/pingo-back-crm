<?php

namespace App\Http\Requests\Finance;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters of the reports (card 11). Periods are civil dates/months of the company; the server converts them
 * to UTC bounds. Long periods are refused instead of truncated, so totals never cover less than shown.
 */
abstract class ReportRequest extends FinanceRequest
{
    public const METHODS = ['CASH', 'PIX', 'BOLETO', 'CARD', 'TRANSFER', 'OTHER'];

    protected function dateRule(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'];
    }

    protected function pageRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'method' => ['sometimes', 'nullable', Rule::in(self::METHODS)],
            'organization_id' => ['prohibited'],
            'timezone' => ['prohibited'],
        ];
    }

    /** Adds an error when both dates are present and the inclusive period has more than $maxDays days. */
    protected function limitDays(Validator $validator, string $fromField, string $toField, int $maxDays, string $message): void
    {
        $from = $this->input($fromField);
        $to = $this->input($toField);
        if ($validator->errors()->hasAny([$fromField, $toField]) || ! is_string($from) || ! is_string($to)) {
            return;
        }
        $days = CarbonImmutable::createFromFormat('!Y-m-d', $from)->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $to)) + 1;
        if ($days > $maxDays) {
            $validator->errors()->add($toField, $message);
        }
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', 25);
    }
}
