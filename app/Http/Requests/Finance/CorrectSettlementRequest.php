<?php

namespace App\Http\Requests\Finance;

use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Correction of one receipt/payment (card 09, ADR-031): the full corrected values, a mandatory reason and
 * the entry version the person reviewed. The original row is never edited; CorrectSettlement supersedes it.
 */
class CorrectSettlementRequest extends FinanceRequest
{
    private function instantField(): string
    {
        return $this->route('receipt') !== null ? 'received_at' : 'paid_at';
    }

    public function rules(): array
    {
        $field = $this->instantField();
        $noun = $field === 'received_at' ? 'recebimento' : 'pagamento';

        return [
            ...$this->serverOwned(),
            'corrects_id' => ['prohibited'], 'superseded_at' => ['prohibited'], 'status' => ['prohibited'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:4294967294'],
            'amount' => $this->money(),
            'method' => ['required', Rule::in(Receivable::METHODS)],
            $field => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($noun) {
                $instant = self::instant($value);
                if (! $instant) {
                    $fail("Informe a data e a hora do {$noun}.");
                } elseif ($instant->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
                    $fail("O {$noun} não pode estar no futuro.");
                } elseif ($instant->lessThan(CarbonImmutable::parse('2000-01-01T00:00:00Z'))) {
                    $fail("Informe um {$noun} a partir de 2000.");
                }
            }],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_version.required' => 'Recarregue o lançamento antes de corrigir.',
            'method.required' => 'Escolha a forma correta.',
            'method.in' => 'Escolha uma forma válida.',
            'reason.required' => 'Explique o motivo da correção.',
            'reason.min' => 'Explique o motivo da correção com ao menos 5 caracteres.',
            'reason.max' => 'Use no máximo 500 caracteres no motivo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /** Canonical payload for the Action and the idempotency hash. */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'expected_version' => (int) $data['expected_version'],
            'amount' => bcadd($data['amount'], '0', 2),
            'method' => $data['method'],
            'instant' => self::instant($data[$this->instantField()])->format('Y-m-d\TH:i:s.uP'),
            'reason' => $data['reason'],
        ];
    }
}
