<?php

namespace App\Http\Requests\Finance;

class IndexRevenuesRequest extends FinanceRequest
{
    public function rules(): array
    {
        return $this->listRules(['status' => ['sometimes', 'nullable', 'in:pending,overdue,received']]);
    }
}
