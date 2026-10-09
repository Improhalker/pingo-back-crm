<?php

namespace App\Http\Requests\Finance;

use App\Models\Category;
use App\Models\Payable;
use Closure;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FinanceRequest
{
    public function rules(): array
    {
        return [
            ...$this->serverOwned(),
            'category_id' => ['required', 'integer', 'min:1', function (string $attribute, mixed $value, Closure $fail) {
                $category = Category::owned()->whereKey($value)->first();
                if (! $category) {
                    $fail('Escolha uma categoria desta empresa.');
                } elseif ($category->type !== 'EXPENSE') {
                    $fail('Escolha uma categoria de despesa.');
                } elseif ($category->isArchived()) {
                    $fail('Esta categoria está arquivada e não aceita novos lançamentos.');
                }
            }],
            'description' => ['required', 'string', 'min:3', 'max:160'],
            'total_amount' => $this->money(),
            // Three exclusive shapes: an instalment schedule (card 10), a single entry settled at once (card 06)
            // or a single pending obligation whose due date is chosen freely.
            ...$this->settlementRules(Payable::METHODS, 'paid_at', 'pagamento'),
            ...$this->scheduleRules(Payable::METHODS, 'paid_at', 'pagamento'),
            ...match (true) {
                $this->hasSchedule() => ['due_date' => ['prohibited'], 'expected_method' => ['prohibited'], 'reminder_date' => ['prohibited'], 'settlement' => ['prohibited']],
                $this->settled() => ['due_date' => ['prohibited'], 'expected_method' => ['prohibited'], 'reminder_date' => ['prohibited']],
                default => [
                    'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
                    'expected_method' => ['required', Rule::in(Payable::METHODS)],
                    'reminder_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
                ],
            },
            'notes' => ['nullable', 'string', 'max:2000'],
            // Status is derived; no client can mark an expense paid without an explicit payment.
            'status' => ['prohibited'],
            'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Escolha a categoria de despesa.',
            'description.required' => 'Descreva a despesa.',
            'description.min' => 'A descrição precisa ter ao menos 3 caracteres.',
            'due_date.required' => 'Informe o vencimento.',
            'due_date.date_format' => 'Use uma data válida.',
            'due_date.after_or_equal' => 'Informe um vencimento a partir de 2000.',
            'due_date.before_or_equal' => 'Informe um vencimento até 2100.',
            'expected_method.required' => 'Escolha a forma prevista.',
            'expected_method.in' => 'Escolha uma forma prevista válida.',
            'due_date.prohibited' => 'Uma despesa já paga não tem vencimento a escolher.',
            'expected_method.prohibited' => 'Uma despesa já paga usa a forma do pagamento.',
            'settlement.array' => 'Informe somente a forma e a data do pagamento.',
            'settlement.method.required_with' => 'Escolha a forma do pagamento.',
            'settlement.method.in' => 'Escolha uma forma de pagamento válida.',
            'settlement.paid_at.required_with' => 'Informe a data e a hora do pagamento.',
            'settlement.prohibited' => 'Num parcelamento, marque a entrada como paga na própria linha.',
            'reminder_date.prohibited' => 'Uma despesa já paga ou parcelada não usa este lembrete único.',
            ...$this->scheduleMessages('pagamento', false),
        ];
    }

    public function after(): array
    {
        return [$this->scheduleChecks('pagamento')];
    }

    public function payload(): array
    {
        $data = $this->validated();

        return [
            'category_id' => (int) $data['category_id'],
            'description' => $data['description'],
            'total_amount' => bcadd($data['total_amount'], '0', 2),
            'notes' => $data['notes'] ?? null,
            'installments' => $this->obligationsPayload('paid_at', bcadd($data['total_amount'], '0', 2)),
        ];
    }
}
