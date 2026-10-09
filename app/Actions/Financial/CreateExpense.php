<?php

namespace App\Actions\Financial;

use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\FinanceFlow;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the expense, its obligation and audit atomically. No payment is implied by creation or by dates;
 * an explicit settlement ("already paid in full", card 06) is registered by RegisterPayment for the whole
 * total inside this same transaction, with its own lock and audit entry.
 */
final class CreateExpense
{
    public function __construct(private OrganizationContext $context, private AuditTrail $audit, private WriteSchedule $schedule) {}

    public function __invoke(User $user, array $data): Expense
    {
        return DB::transaction(function () use ($user, $data) {
            // Prevent an archive racing this write. Re-check the type and tenant under the lock.
            $category = Category::owned()->whereKey($data['category_id'])->sharedLock()->first();
            if (! $category || $category->type !== 'EXPENSE' || $category->isArchived()) {
                throw ValidationException::withMessages(['category_id' => ['Escolha uma categoria de despesa ativa desta empresa.']]);
            }

            $expense = new Expense;
            $expense->forceFill([
                'organization_id' => $this->context->organization()->id,
                'category_id' => $category->id,
                'description' => $data['description'],
                'total_amount' => $data['total_amount'],
                'notes' => $data['notes'],
                'created_by' => $user->id,
            ])->save();

            // One line (pending or paid at once) or an instalment schedule whose sum the request checked.
            $obligations = $this->schedule->obligations(FinanceFlow::expense(), $expense, $data['installments']);

            $this->audit->record($user, 'expense.created', $expense, null, [
                'expense' => [
                    'category_id' => $category->id,
                    'description' => $data['description'],
                    'total_amount' => $data['total_amount'],
                ],
                'payables' => WriteSchedule::audit($obligations),
            ]);

            $this->schedule->settle(FinanceFlow::expense(), $user, $obligations);

            return $expense;
        });
    }
}
