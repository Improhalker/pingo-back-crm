<?php

namespace App\Http\Requests\Finance;

final class ChangeFinancialRecurrenceRequest extends FinanceRequest
{
    public function rules(): array
    {
        $adjust = $this->input('action') === 'adjust';

        return [
            ...$this->serverOwned(),
            'action' => ['required', 'in:adjust,pause,resume,end'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:4294967294'],
            'amount' => $adjust ? $this->money() : ['prohibited'],
            'effective_month' => $adjust ? ['bail', 'required', 'date_format:Y-m', 'before_or_equal:2100-12'] : ['prohibited'],
        ];
    }

    public function payload(): array
    {
        $data = $this->validated();

        return [
            'action' => $data['action'],
            'expected_version' => (int) $data['expected_version'],
            ...($data['action'] === 'adjust' ? [
                'amount' => bcadd($data['amount'], '0', 2),
                'effective_month' => $data['effective_month'].'-01',
            ] : []),
        ];
    }
}
