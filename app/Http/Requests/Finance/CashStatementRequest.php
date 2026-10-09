<?php

namespace App\Http\Requests\Finance;

use App\Queries\Reports\CashStatementQuery;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class CashStatementRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...$this->pageRules(),
            'from' => ['sometimes', 'required', ...$this->dateRule()],
            'to' => ['sometimes', 'required', ...$this->dateRule(), ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'type' => ['sometimes', 'nullable', 'in:income,expense'],
            'status' => ['sometimes', 'nullable', 'in:effective,corrected,all'],
            'order' => ['sometimes', 'nullable', 'in:asc,desc'],
        ];
    }

    public function messages(): array
    {
        return ['to.after_or_equal' => 'O fim do período não pode ser anterior ao início.'];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->limitDays($validator, 'from', 'to', CashStatementQuery::MAX_DAYS,
            'Escolha um período de até '.CashStatementQuery::MAX_DAYS.' dias.')];
    }

    /** Defaults to the current local month; a single bound completes the other within the same month. */
    public function filters(): array
    {
        $now = CarbonImmutable::now($this->organizationTimezone());
        $from = $this->validated('from');
        $to = $this->validated('to');
        $from ??= $to !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $to)->startOfMonth()->toDateString() : $now->startOfMonth()->toDateString();
        $to ??= CarbonImmutable::createFromFormat('!Y-m-d', $from)->endOfMonth()->toDateString();

        return [
            'from' => $from,
            'to' => $to,
            'type' => $this->validated('type'),
            'category_id' => $this->validated('category_id') !== null ? (int) $this->validated('category_id') : null,
            'method' => $this->validated('method'),
            'status' => $this->validated('status') ?? 'effective',
            'order' => $this->validated('order') ?? 'asc',
        ];
    }
}
