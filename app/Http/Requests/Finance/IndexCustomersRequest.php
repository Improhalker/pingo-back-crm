<?php

namespace App\Http\Requests\Finance;

class IndexCustomersRequest extends FinanceRequest
{
    public function rules(): array
    {
        return $this->listRules(['status' => ['sometimes', 'nullable', 'in:active,archived,all']]);
    }
}
