<?php

namespace App\Http\Requests\Finance;

/** Create and update share the same fields; optional fields may be cleared with null. */
class CustomerRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            ...$this->serverOwned(),
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s.]+$/'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:254'],
            'document' => ['nullable', 'string', 'max:30', 'regex:/^[0-9A-Za-z.\-\/\s]+$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do cliente.',
            'name.min' => 'O nome precisa ter ao menos 2 caracteres.',
            'phone.regex' => 'Use apenas números, espaços, +, parênteses ou hífen no telefone.',
            'email.email' => 'Informe um e-mail válido.',
            'document.regex' => 'Use apenas letras, números, ponto, barra ou hífen no documento.',
        ];
    }
}
