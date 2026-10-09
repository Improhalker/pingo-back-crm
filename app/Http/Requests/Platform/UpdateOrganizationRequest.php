<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('platform.manage');
    }

    /** Only registration data. Suspension and members have their own explicit operations. */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', ...PlatformRules::organizationName()],
            'timezone' => ['sometimes', ...PlatformRules::timezone()],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome da empresa', 'timezone' => 'fuso horário'];
    }
}
