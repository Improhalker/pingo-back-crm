<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('platform.manage');
    }

    protected function prepareForValidation(): void
    {
        $administrator = $this->input('administrator');
        if (is_array($administrator) && array_key_exists('email', $administrator)) {
            $administrator['email'] = PlatformRules::normalizeEmail($administrator['email']);
            $this->merge(['administrator' => $administrator]);
        }
    }

    /**
     * Only these fields are read from the payload (via validated()). The ADMIN role is decided by the
     * server; roles, permissions and platform privileges sent by the client are ignored.
     */
    public function rules(): array
    {
        return [
            'organization' => ['required', 'array'],
            'organization.name' => PlatformRules::organizationName(),
            'organization.timezone' => PlatformRules::timezone(),
            'administrator' => ['required', 'array'],
            'administrator.name' => PlatformRules::personName(),
            'administrator.email' => PlatformRules::newEmail(),
            'administrator.password' => PlatformRules::newPassword(),
        ];
    }

    public function attributes(): array
    {
        return [
            'organization.name' => 'nome da empresa',
            'organization.timezone' => 'fuso horário',
            'administrator.name' => 'nome do administrador',
            'administrator.email' => 'e-mail',
            'administrator.password' => 'senha',
        ];
    }

    public function messages(): array
    {
        return ['administrator.email.unique' => 'Este e-mail já está em uso.'] + PlatformRules::passwordMessages('administrator.password');
    }
}
