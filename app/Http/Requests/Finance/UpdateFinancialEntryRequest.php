<?php

namespace App\Http\Requests\Finance;

use App\Models\Expense;
use App\Models\Receivable;
use App\Models\Revenue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Complete correction of the entry, never a correction of its settlements. */
class UpdateFinancialEntryRequest extends FinanceRequest
{
    public function authorize(): bool
    {
        $revenue = $this->route('revenue') !== null;
        $class = $revenue ? Revenue::class : Expense::class;
        Gate::authorize('update', $class::owned()->findOrFail($this->route($revenue ? 'revenue' : 'expense')));

        return true;
    }

    public function rules(): array
    {
        $revenue = $this->route('revenue') !== null;

        return [
            ...$this->serverOwned(),
            'version' => ['prohibited'], 'deleted_at' => ['prohibited'], 'settlement' => ['prohibited'],
            'status' => ['prohibited'], 'received_amount' => ['prohibited'], 'paid_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
            'expected_version' => ['required', 'integer', 'min:1', 'max:4294967294'],
            'description' => ['required', 'string', 'min:3', 'max:160'],
            'total_amount' => $this->money(),
            'category_id' => ['required', 'integer', 'min:1'],
            'customer_id' => $revenue ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'sale_date' => $revenue ? ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.CarbonImmutable::now($this->organizationTimezone())->toDateString()] : ['prohibited'],
            'due_date' => ['required', 'date_format:Y-m-d', $revenue ? 'after_or_equal:sale_date' : 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'expected_method' => ['required', Rule::in(Receivable::METHODS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'expected_version.required' => 'Reabra o lançamento antes de editar.',
            'description.min' => 'Descreva o lançamento com ao menos 3 caracteres.',
            'due_date.after_or_equal' => 'O vencimento deve respeitar a data da venda e ser a partir de 2000.',
            'sale_date.before_or_equal' => 'A data da venda não pode estar no futuro.',
            'settlement.prohibited' => 'A edição não altera recebimentos ou pagamentos.',
        ];
    }

    public function payload(): array
    {
        $data = $this->validated();
        $data['total_amount'] = bcadd($data['total_amount'], '0', 2);
        $data['category_id'] = (int) $data['category_id'];
        $data['expected_version'] = (int) $data['expected_version'];
        $data['notes'] ??= null;
        if (isset($data['customer_id'])) {
            $data['customer_id'] = (int) $data['customer_id'];
        }

        return $data;
    }
}
