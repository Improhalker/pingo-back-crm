<?php

namespace App\Http\Requests\Finance;

use App\Models\Receivable;
use Illuminate\Validation\Rule;

final class StoreFinancialRecurrenceRequest extends FinanceRequest
{
    public function rules(): array
    {
        $isRevenue = $this->input('kind') === 'REVENUE';

        return [
            ...$this->serverOwned(),
            'kind' => ['required', 'in:REVENUE,EXPENSE'],
            'category_id' => ['required', 'integer', 'min:1'],
            'customer_id' => $isRevenue ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'description' => ['required', 'string', 'min:3', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount' => $this->money(),
            'expected_method' => ['required', Rule::in(Receivable::METHODS)],
            'due_day' => ['required', 'integer', 'min:1', 'max:31'],
            'reminder_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'starts_month' => ['bail', 'required', 'date_format:Y-m', 'before_or_equal:2100-12'],
            'ends_month' => ['bail', 'nullable', 'date_format:Y-m', 'after_or_equal:starts_month', 'before_or_equal:2100-12'],
            'settlement' => ['prohibited'],
            'installments' => ['prohibited'],
        ];
    }

    public function payload(): array
    {
        // The action checks the current month after idempotency replay has had a chance to run.
        $data = $this->validated();

        return [
            'kind' => $data['kind'],
            'category_id' => (int) $data['category_id'],
            'customer_id' => isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            'description' => $data['description'],
            'notes' => $data['notes'] ?? null,
            'expected_method' => $data['expected_method'],
            'due_day' => (int) $data['due_day'],
            'reminder_days' => isset($data['reminder_days']) ? (int) $data['reminder_days'] : null,
            'starts_on' => $data['starts_month'].'-01',
            'ends_on' => isset($data['ends_month']) ? $data['ends_month'].'-01' : null,
            'amount' => bcadd($data['amount'], '0', 2),
        ];
    }
}
