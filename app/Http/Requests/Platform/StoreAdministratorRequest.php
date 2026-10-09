<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreAdministratorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('platform.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => PlatformRules::normalizeEmail($this->input('email'))]);
        }
    }

    /** Always a new account with the ADMIN role; linking existing accounts is a separate future flow. */
    public function rules(): array
    {
        return [
            'name' => PlatformRules::personName(),
            'email' => PlatformRules::newEmail(),
            'password' => PlatformRules::newPassword(),
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'email' => 'e-mail', 'password' => 'senha'];
    }

    public function messages(): array
    {
        return ['email.unique' => 'Este e-mail já está em uso.'] + PlatformRules::passwordMessages('password');
    }
}
