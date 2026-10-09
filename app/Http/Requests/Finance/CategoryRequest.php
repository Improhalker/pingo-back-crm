<?php

namespace App\Http\Requests\Finance;

use App\Models\Category;
use Illuminate\Validation\Rule;

/** The type is chosen at creation and cannot change afterwards. */
class CategoryRequest extends FinanceRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            ...$this->serverOwned(),
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'type' => $creating ? ['required', Rule::in(Category::TYPES)] : ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome da categoria.',
            'name.min' => 'O nome precisa ter ao menos 2 caracteres.',
            'type.required' => 'Escolha se a categoria é de receita ou de despesa.',
            'type.in' => 'Escolha receita ou despesa.',
            'type.prohibited' => 'O tipo da categoria não pode ser alterado depois de criada.',
        ];
    }
}
