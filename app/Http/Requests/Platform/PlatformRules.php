<?php

namespace App\Http\Requests\Platform;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Validation\Rule;

/** Field rules shared by the platform requests, so company and account data follow one contract. */
final class PlatformRules
{
    public static function organizationName(): array
    {
        return ['required', 'string', 'min:2', 'max:120'];
    }

    public static function timezone(): array
    {
        return ['required', 'string', 'timezone'];
    }

    public static function personName(): array
    {
        return ['required', 'string', 'min:2', 'max:120'];
    }

    public static function newEmail(): array
    {
        return ['required', 'string', 'email:rfc', 'max:254', Rule::unique(User::class, 'email')];
    }

    public static function newPassword(): array
    {
        return [...PasswordPolicy::rules(), 'confirmed'];
    }

    /** @return array<string, string> */
    public static function passwordMessages(string $field): array
    {
        return [
            "{$field}.confirmed" => 'A confirmação da senha não confere.',
            "{$field}.min" => PasswordPolicy::DESCRIPTION,
            "{$field}.letters" => PasswordPolicy::DESCRIPTION,
            "{$field}.numbers" => PasswordPolicy::DESCRIPTION,
        ];
    }

    public static function normalizeEmail(mixed $email): mixed
    {
        return is_string($email) ? mb_strtolower(trim($email)) : $email;
    }
}
