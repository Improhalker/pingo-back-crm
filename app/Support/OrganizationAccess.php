<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Named capabilities per organization role (ARCHITECTURE §8). The role comes from the membership loaded by
 * ResolveOrganization for the authorized context, never from the client. Platform administrators have no
 * membership and therefore no capability here (ADR-026).
 *
 * Customers/categories: ADMIN maintains, OPERATOR consults (proposal recorded on 06/10/2026, card 01).
 * Revenues/receipts and expenses/payments: both roles create and settle.
 * Correcting a receipt/payment (card 09, ADR-031): both roles, as decided by the user on 08/10/2026.
 * Reports (card 11, ADR-033): both roles read, like the dashboard.
 */
final class OrganizationAccess
{
    private const CAPABILITIES = [
        'ADMIN' => ['customer.view', 'customer.manage', 'category.view', 'category.manage', 'revenue.view', 'revenue.create', 'receivable.settle', 'expense.view', 'expense.create', 'payable.settle', 'dashboard.view', 'revenue.update', 'revenue.delete', 'expense.update', 'expense.delete', 'receipt.correct', 'payment.correct', 'report.view'],
        'OPERATOR' => ['customer.view', 'category.view', 'revenue.view', 'revenue.create', 'receivable.settle', 'expense.view', 'expense.create', 'payable.settle', 'dashboard.view', 'revenue.update', 'revenue.delete', 'expense.update', 'expense.delete', 'receipt.correct', 'payment.correct', 'report.view'],
    ];

    public function __construct(private OrganizationContext $context) {}

    public function organization(): Organization
    {
        return $this->context->organization();
    }

    public function role(): ?string
    {
        return $this->organization()->pivot?->role;
    }

    public function allows(User $user, string $capability, ?Model $resource = null): bool
    {
        $organization = $this->organization();
        // The context membership must belong to the acting user; resources must be owned by the context.
        if ((int) $organization->pivot?->user_id !== (int) $user->id) {
            return false;
        }
        if ($resource !== null && (int) $resource->getAttribute('organization_id') !== (int) $organization->id) {
            return false;
        }

        return in_array($capability, self::CAPABILITIES[$this->role()] ?? [], true);
    }
}
