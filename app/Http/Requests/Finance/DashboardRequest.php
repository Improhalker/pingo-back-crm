<?php

namespace App\Http\Requests\Finance;

class DashboardRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'required', 'string', 'regex:/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/'],
            'organization_id' => ['prohibited'],
            'timezone' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['month.regex' => 'Informe um mês entre 2000-01 e 2100-12, no formato ano-mês.'];
    }
}
