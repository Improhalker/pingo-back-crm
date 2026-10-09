<?php

namespace App\Http\Requests\Finance;

use App\Queries\Reports\OpenObligationsQuery;
use Illuminate\Validation\Validator;

class OpenObligationsRequest extends ReportRequest
{
    public function rules(): array
    {
        return [
            ...$this->pageRules(),
            // Optional due-date period: without it every open instalment is listed, overdue ones included.
            'due_from' => ['sometimes', 'nullable', ...$this->dateRule()],
            'due_to' => ['sometimes', 'nullable', ...$this->dateRule(), ...($this->filled('due_from') ? ['after_or_equal:due_from'] : [])],
            'type' => ['sometimes', 'nullable', 'in:receivable,payable'],
            'status' => ['sometimes', 'nullable', 'in:pending,overdue,partial'],
        ];
    }

    public function messages(): array
    {
        return ['due_to.after_or_equal' => 'O fim do período não pode ser anterior ao início.'];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->limitDays($validator, 'due_from', 'due_to', OpenObligationsQuery::MAX_DAYS,
            'Escolha um período de até '.OpenObligationsQuery::MAX_DAYS.' dias.')];
    }

    public function filters(): array
    {
        return [
            'due_from' => $this->validated('due_from'),
            'due_to' => $this->validated('due_to'),
            'type' => $this->validated('type'),
            'category_id' => $this->validated('category_id') !== null ? (int) $this->validated('category_id') : null,
            'method' => $this->validated('method'),
            'status' => $this->validated('status'),
        ];
    }
}
