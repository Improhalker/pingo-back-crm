<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DestroyOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::forUser($this->user())->allows('platform.manage');
    }

    /** The exact company name must be typed; it is compared by the action against the locked row. */
    public function rules(): array
    {
        return ['confirmation_name' => ['required', 'string', 'max:120']];
    }

    public function attributes(): array
    {
        return ['confirmation_name' => 'nome da empresa'];
    }
}
