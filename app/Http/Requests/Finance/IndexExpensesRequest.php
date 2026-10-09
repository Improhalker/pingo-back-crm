<?php

namespace App\Http\Requests\Finance;

class IndexExpensesRequest extends FinanceRequest
{
    public function rules(): array
    {
        return $this->listRules([
            'status' => ['sometimes', 'nullable', 'in:pending,overdue,paid'],
            // Inclusive civil period refers to due dates, not payment instants.
            'due_from' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'due_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31', ...($this->filled('due_from') ? ['after_or_equal:due_from'] : [])],
        ]);
    }
}
