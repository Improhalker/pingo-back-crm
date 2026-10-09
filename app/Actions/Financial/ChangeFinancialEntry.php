<?php

namespace App\Actions\Financial;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Payable;
use App\Models\Receipt;
use App\Models\Receivable;
use App\Models\Revenue;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared edit/deletion rules for revenues and expenses, with one obligation or an instalment schedule. */
final class ChangeFinancialEntry
{
    public function __construct(private AuditTrail $audit, private OrganizationContext $context) {}

    public function run(User $user, bool $revenue, int $id, array $data, bool $delete = false): Model
    {
        return DB::transaction(function () use ($user, $revenue, $id, $data, $delete) {
            $class = $revenue ? Revenue::class : Expense::class;
            $obligationClass = $revenue ? Receivable::class : Payable::class;
            // Same lock order as settlements: obligations, then entry. A settlement cannot race deletion.
            $obligations = $obligationClass::owned()->where($revenue ? 'revenue_id' : 'expense_id', $id)->orderBy('sequence')->lockForUpdate()->get();
            abort_if($obligations->isEmpty(), 404);
            $obligation = $obligations->first();
            // Instalment schedules (card 10, ADR-032): amounts, due dates and methods stay as scheduled here.
            $instalments = $obligations->count() > 1;
            $entry = $class::owned()->withTrashed()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $entry->version !== $data['expected_version']) {
                throw new HttpResponseException(response()->json([
                    'code' => 'FINANCIAL_ENTRY_CHANGED',
                    'message' => 'Este lançamento mudou enquanto você o editava. Recarregue os dados e confira antes de tentar novamente.',
                ], 409));
            }
            abort_if($entry->trashed(), 404);
            $settled = $obligations->reduce(fn (string $sum, $item) => Money::add($sum, $revenue ? $item->receivedTotal() : $item->paidTotal()), '0.00');
            $before = $this->snapshot($entry, $obligation, $revenue);
            if ($delete) {
                if (Money::compare($settled, '0.00') > 0) {
                    throw ValidationException::withMessages(['entry' => ['Este lançamento possui recebimentos ou pagamentos. A exclusão exige estorno, que ainda não está disponível. As baixas e o caixa foram preservados.']]);
                }
                $entry->version++;
                $entry->save();
                $entry->delete();
            } else {
                if ($instalments && Money::compare($data['total_amount'], (string) $entry->total_amount) !== 0) {
                    throw ValidationException::withMessages(['total_amount' => ['Em lançamentos parcelados o total é a soma das parcelas e não muda nesta edição.']]);
                }
                if (Money::compare($data['total_amount'], $settled) < 0) {
                    throw ValidationException::withMessages(['total_amount' => ['O total não pode ser menor que o valor já recebido ou pago.']]);
                }
                $category = Category::owned()->whereKey($data['category_id'])->sharedLock()->first();
                if (! $category || $category->type !== ($revenue ? 'REVENUE' : 'EXPENSE') || ($category->isArchived() && $category->id !== $entry->category_id)) {
                    throw ValidationException::withMessages(['category_id' => ['Escolha uma categoria ativa, do tipo correto e desta empresa.']]);
                }
                if ($revenue) {
                    $customer = Customer::owned()->whereKey($data['customer_id'])->sharedLock()->first();
                    if (! $customer || ($customer->isArchived() && $customer->id !== $entry->customer_id)) {
                        throw ValidationException::withMessages(['customer_id' => ['Escolha um cliente ativo desta empresa.']]);
                    }
                    $first = Receipt::owned()->effective()->whereIn('receivable_id', $obligations->pluck('id'))->min('received_at');
                    // Legacy receipts may precede the original sale. Metadata corrections preserve them;
                    // changing the sale date cannot move it past an existing receipt.
                    if ($data['sale_date'] !== $entry->sale_date->toDateString() && $first !== null
                        && $data['sale_date'] > CarbonImmutable::parse($first, 'UTC')->setTimezone($this->context->organization()->timezone)->toDateString()) {
                        throw ValidationException::withMessages(['sale_date' => ['A data da venda não pode ser posterior a um recebimento já registrado.']]);
                    }
                    $entry->customer_id = $data['customer_id'];
                    $entry->sale_date = $data['sale_date'];
                }
                $entry->forceFill([
                    'description' => $data['description'], 'category_id' => $data['category_id'],
                    'total_amount' => $data['total_amount'], 'notes' => $data['notes'], 'version' => $entry->version + 1,
                ])->save();
                if (! $instalments) {
                    $obligation->forceFill(['amount' => $data['total_amount'], 'due_date' => $data['due_date'], 'expected_method' => $data['expected_method']])->save();
                }
            }
            $this->audit->record($user, ($revenue ? 'revenue' : 'expense').($delete ? '.deleted' : '.updated'), $entry, $before, $this->snapshot($entry, $obligation, $revenue));

            return $entry;
        }, 3);
    }

    private function snapshot(Model $entry, Model $obligation, bool $revenue): array
    {
        return [
            'description' => $entry->description, 'category_id' => $entry->category_id,
            'total_amount' => $entry->total_amount, 'notes' => $entry->notes,
            'due_date' => $obligation->due_date->toDateString(), 'expected_method' => $obligation->expected_method,
            'version' => (int) $entry->version, 'deleted_at' => $entry->deleted_at?->utc()->toIso8601String(),
            'instalments_count' => $obligation->newQuery()->where($revenue ? 'revenue_id' : 'expense_id', $entry->getKey())->count(),
            ...($revenue ? ['customer_id' => $entry->customer_id, 'sale_date' => $entry->sale_date->toDateString()] : []),
        ];
    }
}
