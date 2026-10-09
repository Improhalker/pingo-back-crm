<?php

namespace App\Internal\Kanban\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Rejects any payload field that is not declared in rules(). Access is enforced by route middleware. */
abstract class KanbanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add((string) $field, 'Campo não permitido.');
            }
        });
    }
}
