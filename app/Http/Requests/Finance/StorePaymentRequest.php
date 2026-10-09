<?php

namespace App\Http\Requests\Finance;

use App\Models\Payable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/** Explicit settlement with a valid ISO instant; the amount is rechecked under lock by RegisterPayment. */
class StorePaymentRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            ...$this->serverOwned(),
            'amount' => $this->money(),
            'method' => ['required', Rule::in(Payable::METHODS)],
            'paid_at' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $instant = self::parseInstant($value);
                if (! $instant) {
                    $fail('Informe o instante do pagamento com data, hora e fuso válidos.');
                } elseif ($instant->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
                    $fail('O pagamento não pode estar no futuro.');
                } elseif ($instant->lessThan(CarbonImmutable::parse('2000-01-01T00:00:00Z'))) {
                    $fail('Informe um pagamento a partir de 2000.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return ['method.required' => 'Escolha a forma do pagamento.', 'method.in' => 'Escolha uma forma válida.'];
    }

    public static function parseInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value, $parts)) {
            return null;
        }
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59) {
            return null;
        }
        $offset = $parts[7];
        if ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 14 || (int) substr($offset, 4, 2) > 59
            || ((int) substr($offset, 1, 2) === 14 && substr($offset, 4, 2) !== '00'))) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public function payload(): array
    {
        $data = $this->validated();

        return [
            'amount' => bcadd($data['amount'], '0', 2),
            'method' => $data['method'],
            'paid_at' => self::parseInstant($data['paid_at'])->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
