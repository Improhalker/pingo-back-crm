<?php

namespace App\Http\Requests\Finance;

use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * One explicit settlement. received_at is the effective instant, ISO 8601 with offset (stored in UTC);
 * the remaining amount is checked by RegisterReceipt under the obligation lock.
 */
class StoreReceiptRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            ...$this->serverOwned(),
            'amount' => $this->money(),
            'method' => ['required', Rule::in(Receivable::METHODS)],
            'received_at' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $instant = self::parseInstant($value);
                if (! $instant) {
                    $fail('Informe o instante do recebimento com data, hora e fuso.');
                } elseif ($instant->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
                    $fail('O recebimento não pode estar no futuro.');
                } elseif ($instant->lessThan(CarbonImmutable::parse('2000-01-01T00:00:00Z'))) {
                    $fail('Informe um recebimento a partir de 2000.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return ['method.required' => 'Escolha a forma do recebimento.', 'method.in' => 'Escolha uma forma válida.'];
    }

    public static function parseInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', $value)) {
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
            'received_at' => self::parseInstant($data['received_at'])->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
