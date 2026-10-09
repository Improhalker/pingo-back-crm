<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Password policy for accounts provisioned by the platform (first step; revisit with password recovery).
 * At least 12 characters with letters and numbers; at most 72 bytes because bcrypt ignores the rest.
 * No external breached-password lookup is performed.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public const MAX_BYTES = 72;

    public const DESCRIPTION = 'Use pelo menos 12 caracteres, com letras e números.';

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            Password::min(self::MIN_LENGTH)->letters()->numbers(),
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && strlen($value) > self::MAX_BYTES) {
                    $fail('A senha deve ter no máximo 72 bytes (cerca de 72 caracteres simples).');
                }
            },
        ];
    }
}
