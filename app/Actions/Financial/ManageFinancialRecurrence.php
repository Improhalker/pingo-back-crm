<?php

namespace App\Actions\Financial;

use App\Models\Category;
use App\Models\Customer;
use App\Models\FinancialRecurrence;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

final class ManageFinancialRecurrence
{
    public function __construct(
        private OrganizationContext $context,
        private AuditTrail $audit,
        private GenerateRecurringEntries $generate,
    ) {}

    /** Runs inside the HTTP idempotency transaction. */
    public function create(User $user, array $data): FinancialRecurrence
    {
        if ($data['starts_on'] < $this->month()->toDateString()) {
            throw ValidationException::withMessages(['starts_month' => ['Escolha o mês atual ou uma competência futura.']]);
        }
        $category = Category::owned()->whereKey($data['category_id'])->sharedLock()->first();
        if (! $category || $category->isArchived() || $category->type !== $data['kind']) {
            throw ValidationException::withMessages(['category_id' => ['Escolha uma categoria ativa deste tipo e desta empresa.']]);
        }
        if ($data['kind'] === 'REVENUE') {
            $customer = Customer::owned()->whereKey($data['customer_id'])->sharedLock()->first();
            if (! $customer || $customer->isArchived()) {
                throw ValidationException::withMessages(['customer_id' => ['Escolha um cliente ativo como fonte pagadora.']]);
            }
        }

        $series = new FinancialRecurrence;
        $amount = $data['amount'];
        unset($data['amount']);
        $series->forceFill([
            ...$data, 'organization_id' => $this->context->organization()->id,
            'created_by' => $user->id, 'status' => 'ACTIVE', 'version' => 1, 'next_month' => $data['starts_on'],
        ])->save();
        $series->rates()->create(['amount' => $amount, 'effective_month' => $data['starts_on'], 'created_by' => $user->id]);
        $this->audit->record($user, 'recurrence.created', $series, null, [...$data, 'amount' => $amount]);
        $this->generate->generate($series);

        return $series;
    }

    /** Runs inside the HTTP idempotency transaction. */
    public function change(User $user, int $id, array $data): FinancialRecurrence
    {
        $series = FinancialRecurrence::owned()->whereKey($id)->lockForUpdate()->firstOrFail();
        if ((int) $series->version !== $data['expected_version']) {
            throw new HttpResponseException(response()->json([
                'code' => 'FINANCIAL_RECURRENCE_CHANGED',
                'message' => 'A série mudou. Recarregue os dados antes de tentar novamente.',
                'errors' => (object) [],
            ], 409));
        }

        $action = $data['action'];
        $current = $this->month()->toDateString();
        $allowed = match ($action) {
            'pause' => $series->status === 'ACTIVE',
            'resume' => $series->status === 'PAUSED',
            'end', 'adjust' => $series->status !== 'ENDED',
        };
        if (! $allowed || ($series->ends_on && $series->ends_on->toDateString() < $current)) {
            throw ValidationException::withMessages(['action' => ['Esta ação não está disponível para a situação atual da série.']]);
        }

        $before = ['status' => $series->status, 'version' => (int) $series->version];
        $changed = 0;
        switch ($action) {
            case 'adjust':
                $changed = $this->adjust($user, $series, $data['amount'], $data['effective_month']);
                break;
            case 'pause':
                $series->status = 'PAUSED';
                break;
            case 'resume':
                $series->status = 'ACTIVE';
                // Paused months are not billed retroactively. A current open period can be generated.
                $series->next_month = max($series->next_month->toDateString(), $current);
                break;
            case 'end':
                $series->status = 'ENDED';
                break;
        }

        $series->version++;
        $series->save();
        $this->audit->record($user, 'recurrence.'.$action, $series, $before, [
            'status' => $series->status, 'version' => (int) $series->version,
            ...($action === 'adjust' ? [
                'amount' => $data['amount'], 'effective_month' => $data['effective_month'], 'occurrences_updated' => $changed,
            ] : []),
        ]);
        if ($action === 'resume') {
            $this->generate->generate($series);
        }

        return $series;
    }

    private function adjust(User $user, FinancialRecurrence $series, string $amount, string $from): int
    {
        $first = max($series->starts_on->toDateString(), $this->month()->addMonth()->toDateString());
        if ($from < $first || ($series->ends_on && $from > $series->ends_on->toDateString())) {
            throw ValidationException::withMessages(['effective_month' => ['Escolha uma competência futura dentro da vigência da série.']]);
        }
        $series->rates()->create(['effective_month' => $from, 'amount' => $amount, 'created_by' => $user->id]);

        $flow = $series->flow();
        $changed = 0;
        $entries = $flow->entryClass::owned()->where('financial_recurrence_id', $series->id)->where('recurrence_month', '>=', $from);
        foreach ($entries->select('id')->orderBy('id')->get() as $candidate) {
            // Same lock order as the ordinary settlement/edit actions. Re-check after acquiring the locks.
            $obligation = $flow->obligationClass::owned()->where($flow->entryKey, $candidate->id)->lockForUpdate()->firstOrFail();
            $entry = $flow->entryClass::owned()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $entry || $entry->recurrence_amount_overridden || Money::compare((string) $entry->total_amount, $amount) === 0) {
                continue;
            }
            if ($flow->settlementClass::owned()->where($flow->obligationKey, $obligation->id)->lockForUpdate()->first()) {
                continue;
            }
            $old = (string) $entry->total_amount;
            $entry->forceFill(['total_amount' => $amount, 'version' => $entry->version + 1])->save();
            $obligation->forceFill(['amount' => $amount])->save();
            $this->audit->record($user, 'recurrence.occurrence_adjusted', $entry, ['amount' => $old], [
                'amount' => $amount, 'series_id' => $series->id, 'month' => $entry->recurrence_month,
            ]);
            $changed++;
        }

        return $changed;
    }

    private function month(): CarbonImmutable
    {
        return CarbonImmutable::now($this->context->organization()->timezone)->startOfMonth();
    }
}
