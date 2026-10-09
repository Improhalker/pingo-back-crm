<?php

namespace App\Http\Requests\Finance;

use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Base for tenant-scoped requests. Authorization is done by Policies in the controllers. */
abstract class FinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function organizationTimezone(): string
    {
        return app(OrganizationContext::class)->organization()->timezone;
    }

    /** Server-owned fields that a client must never send. */
    protected function serverOwned(): array
    {
        return ['organization_id' => ['prohibited'], 'archived_at' => ['prohibited'], 'created_by' => ['prohibited']];
    }

    /** Money as a canonical decimal string: positive, at most two decimals, within the accepted limit. */
    protected function money(): array
    {
        return ['required', function (string $attribute, mixed $value, Closure $fail) {
            if (! Money::isValidInput($value)) {
                $fail('Informe um valor em reais com até duas casas decimais, por exemplo 1000.00.');

                return;
            }
            if (! Money::isPositive($value)) {
                $fail('O valor precisa ser maior que zero.');
            } elseif (! Money::withinLimit($value)) {
                $fail('O valor máximo aceito é R$ 999.999.999,99.');
            }
        }];
    }

    /**
     * Optional, explicit "already settled in full" block of a new revenue/expense (card 06). Only its
     * presence settles: a date alone never does. The amount is always the total and is not accepted here.
     */
    protected function settled(): bool
    {
        return $this->input('settlement') !== null;
    }

    /** @param string $instantField received_at|paid_at; $notBefore: civil date field the settlement cannot precede */
    protected function settlementRules(array $methods, string $instantField, string $noun, ?string $notBefore = null): array
    {
        return [
            'settlement' => ['nullable', "array:method,{$instantField}"],
            'settlement.method' => ['required_with:settlement', Rule::in($methods)],
            "settlement.{$instantField}" => ['required_with:settlement', 'string', function (string $attribute, mixed $value, Closure $fail) use ($noun, $notBefore) {
                $instant = self::instant($value);
                if (! $instant) {
                    $fail("Informe a data e a hora do {$noun}.");
                } elseif ($instant->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
                    $fail("O {$noun} não pode estar no futuro.");
                } elseif ($instant->lessThan(CarbonImmutable::parse('2000-01-01T00:00:00Z'))) {
                    $fail("Informe um {$noun} a partir de 2000.");
                } elseif ($notBefore !== null && is_string($date = $this->input($notBefore))
                    && $instant->setTimezone($this->organizationTimezone())->toDateString() < $date) {
                    $fail("O {$noun} não pode ser anterior à data da venda.");
                }
            }],
        ];
    }

    /**
     * Canonical settlement plus the obligation fields it implies. Documented rule: the obligation of a
     * settled entry is due on the civil date (organization timezone) of the effective settlement instant,
     * and its expected method is the effective one, so the user is never asked for a due date.
     *
     * @return array{settlement: ?array{method: string, instant: string}, due_date?: string, expected_method?: string}
     */
    protected function settlementPayload(string $instantField): array
    {
        $settlement = $this->validated('settlement');
        if (! is_array($settlement)) {
            return ['settlement' => null];
        }
        $instant = self::instant($settlement[$instantField]);

        return [
            'settlement' => ['method' => $settlement['method'], 'instant' => $instant->format('Y-m-d\TH:i:s.uP')],
            'due_date' => $instant->setTimezone($this->organizationTimezone())->toDateString(),
            'expected_method' => $settlement['method'],
        ];
    }

    /** Card 10 (ADR-032): an explicit instalment schedule replaces the single due date/settlement fields. */
    protected function hasSchedule(): bool
    {
        return $this->input('installments') !== null;
    }

    /** Field rules of a schedule; cross-line rules (sum, down payment, settled vs pending) are in scheduleChecks(). */
    protected function scheduleRules(array $methods, string $instantField, string $noun, ?string $notBefore = null): array
    {
        $civil = ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'];

        return [
            'installments' => ['nullable', 'array', 'min:2', 'max:60'],
            'installments.*' => ['array:amount,due_date,expected_method,reminder_date,down_payment,settlement'],
            'installments.*.amount' => $this->money(),
            'installments.*.down_payment' => ['sometimes', 'boolean'],
            'installments.*.due_date' => $notBefore ? [...$civil, "after_or_equal:{$notBefore}"] : $civil,
            'installments.*.expected_method' => ['nullable', Rule::in($methods)],
            'installments.*.reminder_date' => $civil,
            'installments.*.settlement' => ['nullable', "array:method,{$instantField}"],
            'installments.*.settlement.method' => ['required_with:installments.*.settlement', Rule::in($methods)],
            "installments.*.settlement.{$instantField}" => ['required_with:installments.*.settlement', 'string', $this->instantRule($noun, $notBefore)],
        ];
    }

    protected function scheduleMessages(string $noun, bool $revenue): array
    {
        return [
            'installments.min' => 'Um parcelamento precisa de pelo menos duas linhas (entrada e parcela, ou duas parcelas).',
            'installments.max' => 'Use no máximo 60 parcelas.',
            'installments.*.array' => 'Cada parcela aceita somente valor, vencimento, forma, lembrete, entrada e baixa.',
            'installments.*.due_date.date_format' => 'Use uma data válida.',
            'installments.*.due_date.after_or_equal' => $revenue ? 'O vencimento não pode ser anterior à data da venda.' : 'Informe um vencimento a partir de 2000.',
            'installments.*.due_date.before_or_equal' => 'Informe um vencimento até 2100.',
            'installments.*.reminder_date.date_format' => 'Use uma data válida para o lembrete.',
            'installments.*.reminder_date.after_or_equal' => 'Informe um lembrete a partir de 2000.',
            'installments.*.reminder_date.before_or_equal' => 'Informe um lembrete até 2100.',
            'installments.*.expected_method.in' => 'Escolha uma forma prevista válida.',
            'installments.*.settlement.array' => "Informe somente a forma e a data do {$noun}.",
            'installments.*.settlement.method.required_with' => "Escolha a forma do {$noun}.",
            'installments.*.settlement.method.in' => "Escolha uma forma de {$noun} válida.",
        ];
    }

    /** Cross-line rules of a schedule, run after the field rules pass. */
    protected function scheduleChecks(string $noun): Closure
    {
        return function ($validator) use ($noun) {
            if (! $this->hasSchedule() || $validator->errors()->isNotEmpty()) {
                return;
            }
            $sum = '0.00';
            foreach ($this->input('installments') as $index => $item) {
                $key = "installments.{$index}";
                $sum = Money::add($sum, (string) $item['amount']);
                $down = (bool) ($item['down_payment'] ?? false);
                $settled = ($item['settlement'] ?? null) !== null;
                if ($down && $index !== 0) {
                    $validator->errors()->add("{$key}.down_payment", 'A entrada precisa ser a primeira linha.');
                }
                if ($settled && ! $down) {
                    $validator->errors()->add("{$key}.settlement", "Somente a entrada pode ser marcada como {$noun} já feito no cadastro. As parcelas recebem baixa quando o dinheiro movimentar.");
                }
                if ($settled) {
                    foreach (['due_date', 'expected_method', 'reminder_date'] as $field) {
                        if (($item[$field] ?? null) !== null) {
                            $validator->errors()->add("{$key}.{$field}", 'Uma entrada já registrada usa a data e a forma efetivas e não tem lembrete.');
                        }
                    }
                } else {
                    if (($item['due_date'] ?? null) === null) {
                        $validator->errors()->add("{$key}.due_date", 'Informe o vencimento desta parcela.');
                    }
                    if (($item['expected_method'] ?? null) === null) {
                        $validator->errors()->add("{$key}.expected_method", 'Escolha a forma prevista desta parcela.');
                    }
                }
            }
            $total = (string) $this->input('total_amount');
            if (Money::compare($sum, $total) !== 0) {
                $validator->errors()->add('installments', 'A soma das parcelas (R$ '.Money::display($sum).') precisa ser igual ao total (R$ '.Money::display($total).').');
            }
        };
    }

    /**
     * Canonical list of obligations for the Actions: an explicit schedule, or the single obligation of a
     * pending/settled-at-once entry. A settled line is due on the civil date of its effective instant (ADR-029).
     *
     * @return list<array{amount: string, down_payment: bool, due_date: string, expected_method: string, reminder_date: ?string, settlement: ?array{method: string, instant: string}}>
     */
    protected function obligationsPayload(string $instantField, string $total): array
    {
        $timezone = $this->organizationTimezone();
        $line = function (array $item, string $amount) use ($instantField, $timezone): array {
            $settlement = $item['settlement'] ?? null;
            if (is_array($settlement)) {
                $instant = self::instant($settlement[$instantField]);

                return [
                    'amount' => $amount, 'down_payment' => (bool) ($item['down_payment'] ?? false),
                    'due_date' => $instant->setTimezone($timezone)->toDateString(), 'expected_method' => $settlement['method'],
                    'reminder_date' => null, 'settlement' => ['method' => $settlement['method'], 'instant' => $instant->format('Y-m-d\TH:i:s.uP')],
                ];
            }

            return [
                'amount' => $amount, 'down_payment' => (bool) ($item['down_payment'] ?? false),
                'due_date' => $item['due_date'], 'expected_method' => $item['expected_method'],
                'reminder_date' => $item['reminder_date'] ?? null, 'settlement' => null,
            ];
        };
        if ($this->hasSchedule()) {
            return array_map(fn ($item) => $line($item, bcadd((string) $item['amount'], '0', 2)), array_values($this->validated('installments')));
        }

        return [$line([
            'due_date' => $this->validated('due_date'), 'expected_method' => $this->validated('expected_method'),
            'reminder_date' => $this->validated('reminder_date'), 'settlement' => $this->validated('settlement'),
        ], $total)];
    }

    private function instantRule(string $noun, ?string $notBefore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($noun, $notBefore) {
            $instant = self::instant($value);
            if (! $instant) {
                $fail("Informe a data e a hora do {$noun}.");
            } elseif ($instant->greaterThan(CarbonImmutable::now()->addMinutes(5))) {
                $fail("O {$noun} não pode estar no futuro.");
            } elseif ($instant->lessThan(CarbonImmutable::parse('2000-01-01T00:00:00Z'))) {
                $fail("Informe um {$noun} a partir de 2000.");
            } elseif ($notBefore !== null && is_string($date = $this->input($notBefore))
                && $instant->setTimezone($this->organizationTimezone())->toDateString() < $date) {
                $fail("O {$noun} não pode ser anterior à data da venda.");
            }
        };
    }

    /** ISO 8601 instant with offset (stored in UTC), or null when malformed. */
    protected static function instant(mixed $value): ?CarbonImmutable
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

    protected function listRules(array $extra = []): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            ...$extra,
        ];
    }
}
