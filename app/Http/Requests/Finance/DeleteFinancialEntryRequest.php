<?php

namespace App\Http\Requests\Finance;

class DeleteFinancialEntryRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            ...$this->serverOwned(),
            'expected_version' => ['required', 'integer', 'min:1', 'max:4294967294'],
        ];
    }
}
