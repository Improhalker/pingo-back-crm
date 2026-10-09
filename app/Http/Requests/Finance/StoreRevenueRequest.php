<?php

namespace App\Http\Requests\Finance;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * A sale and its single expected obligation (this version). Dates are civil dates (YYYY-MM-DD) in the
 * organization timezone. References are resolved only inside the authorized organization; the Action
 * re-checks them under lock.
 */
class StoreRevenueRequest extends FinanceRequest
{
    public function rules(): array
    {
        $today = CarbonImmutable::now($this->organizationTimezone())->toDateString();

        return [
            ...$this->serverOwned(),
            'customer_id' => ['required', 'integer', 'min:1', function (string $attribute, mixed $value, Closure $fail) {
                $customer = Customer::owned()->whereKey($value)->first();
                if (! $customer) {
                    $fail('Escolha um cliente desta empresa.');
                } elseif ($customer->isArchived()) {
                    $fail('Este cliente está arquivado. Reative-o para registrar novas receitas.');
                }
            }],
            'category_id' => ['required', 'integer', 'min:1', function (string $attribute, mixed $value, Closure $fail) {
                $category = Category::owned()->whereKey($value)->first();
                if (! $category) {
                    $fail('Escolha uma categoria desta empresa.');
                } elseif ($category->type !== 'REVENUE') {
                    $fail('Escolha uma categoria de receita.');
                } elseif ($category->isArchived()) {
                    $fail('Esta categoria está arquivada e não aceita novos lançamentos.');
                }
            }],
            'description' => ['required', 'string', 'min:3', 'max:160'],
            'total_amount' => $this->money(),
            'sale_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.$today],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Three exclusive shapes: an instalment schedule (card 10), a single entry settled at once (card 06)
            // or a single pending obligation whose due date is chosen freely.
            ...$this->settlementRules(Receivable::METHODS, 'received_at', 'recebimento', 'sale_date'),
            ...$this->scheduleRules(Receivable::METHODS, 'received_at', 'recebimento', 'sale_date'),
            ...match (true) {
                $this->hasSchedule() => ['due_date' => ['prohibited'], 'expected_method' => ['prohibited'], 'reminder_date' => ['prohibited'], 'settlement' => ['prohibited']],
                $this->settled() => ['due_date' => ['prohibited'], 'expected_method' => ['prohibited'], 'reminder_date' => ['prohibited']],
                default => [
                    'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:sale_date', 'before_or_equal:2100-12-31'],
                    'expected_method' => ['required', Rule::in(Receivable::METHODS)],
                    'reminder_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
                ],
            },
        ];
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => 'Escolha o cliente.',
            'category_id.required' => 'Escolha a categoria de receita.',
            'description.required' => 'Descreva a venda.',
            'description.min' => 'A descrição precisa ter ao menos 3 caracteres.',
            'sale_date.required' => 'Informe a data da venda.',
            'sale_date.date_format' => 'Use uma data válida.',
            'sale_date.before_or_equal' => 'A data da venda não pode estar no futuro.',
            'sale_date.after_or_equal' => 'Informe uma data a partir de 2000.',
            'due_date.required' => 'Informe o vencimento.',
            'due_date.date_format' => 'Use uma data válida.',
            'due_date.after_or_equal' => 'O vencimento não pode ser anterior à data da venda.',
            'due_date.before_or_equal' => 'Informe um vencimento até 2100.',
            'expected_method.required' => 'Escolha a forma prevista.',
            'expected_method.in' => 'Escolha uma forma prevista válida.',
            'due_date.prohibited' => 'Uma receita já recebida não tem vencimento a escolher.',
            'expected_method.prohibited' => 'Uma receita já recebida usa a forma do recebimento.',
            'settlement.array' => 'Informe somente a forma e a data do recebimento.',
            'settlement.method.required_with' => 'Escolha a forma do recebimento.',
            'settlement.method.in' => 'Escolha uma forma de recebimento válida.',
            'settlement.received_at.required_with' => 'Informe a data e a hora do recebimento.',
            'settlement.prohibited' => 'Num parcelamento, marque a entrada como recebida na própria linha.',
            'reminder_date.prohibited' => 'Uma receita já recebida ou parcelada não usa este lembrete único.',
            ...$this->scheduleMessages('recebimento', true),
        ];
    }

    public function after(): array
    {
        return [$this->scheduleChecks('recebimento')];
    }

    /** Canonical payload used by the Action and by the idempotency hash. */
    public function payload(): array
    {
        $data = $this->validated();

        return [
            'customer_id' => (int) $data['customer_id'],
            'category_id' => (int) $data['category_id'],
            'description' => $data['description'],
            'total_amount' => bcadd($data['total_amount'], '0', 2),
            'sale_date' => $data['sale_date'],
            'notes' => $data['notes'] ?? null,
            'installments' => $this->obligationsPayload('received_at', bcadd($data['total_amount'], '0', 2)),
        ];
    }
}
