<?php

namespace App\Http\Requests\Finance;

use App\Models\Category;
use Illuminate\Validation\Rule;

class IndexCategoriesRequest extends FinanceRequest
{
    public function rules(): array
    {
        return $this->listRules([
            'status' => ['sometimes', 'nullable', 'in:active,archived,all'],
            'type' => ['sometimes', 'nullable', Rule::in(Category::TYPES)],
        ]);
    }
}
