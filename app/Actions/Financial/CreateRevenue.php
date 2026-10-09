<?php

namespace App\Actions\Financial;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Revenue;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\FinanceFlow;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a sale and its single expected obligation (this version) with the audit entry, atomically.
 * No receipt is implied by the sale or its dates: money only enters the cash through an explicit settlement.
 * When the request carries one ("already received in full", card 06), RegisterReceipt settles the whole
 * total inside this same transaction, with its own lock and audit entry, so nothing is left half-written.
 */
final class CreateRevenue
{
    public function __construct(private OrganizationContext $context, private AuditTrail $audit, private WriteSchedule $schedule) {}

    /** Runs inside the caller's transaction (Idempotency) or opens its own. */
    public function __invoke(User $user, array $data): Revenue
    {
        return DB::transaction(function () use ($user, $data) {
            $organization = $this->context->organization();
            // Shared locks keep the references from being archived concurrently while the sale is written.
            $customer = Customer::owned()->whereKey($data['customer_id'])->sharedLock()->first();
            $category = Category::owned()->whereKey($data['category_id'])->sharedLock()->first();
            if (! $customer || $customer->isArchived()) {
                throw ValidationException::withMessages(['customer_id' => ['Escolha um cliente ativo desta empresa.']]);
            }
            if (! $category || $category->type !== 'REVENUE' || $category->isArchived()) {
                throw ValidationException::withMessages(['category_id' => ['Escolha uma categoria de receita ativa desta empresa.']]);
            }

            $revenue = new Revenue;
            $revenue->forceFill([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'category_id' => $category->id,
                'description' => $data['description'],
                'total_amount' => $data['total_amount'],
                'sale_date' => $data['sale_date'],
                'notes' => $data['notes'],
                'created_by' => $user->id,
            ])->save();

            // One line (pending or settled at once) or an instalment schedule whose sum the request checked.
            $obligations = $this->schedule->obligations(FinanceFlow::revenue(), $revenue, $data['installments']);

            $this->audit->record($user, 'revenue.created', $revenue, null, [
                'revenue' => [
                    'customer_id' => $customer->id,
                    'category_id' => $category->id,
                    'description' => $data['description'],
                    'total_amount' => $data['total_amount'],
                    'sale_date' => $data['sale_date'],
                ],
                'receivables' => WriteSchedule::audit($obligations),
            ]);

            $this->schedule->settle(FinanceFlow::revenue(), $user, $obligations);

            return $revenue;
        });
    }
}
