<?php

namespace App\Policies;

use App\Models\FinancialRecurrence;
use App\Models\User;
use App\Support\OrganizationAccess;

final class FinancialRecurrencePolicy
{
    public function __construct(private OrganizationAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'revenue.view') || $this->access->allows($user, 'expense.view');
    }

    public function view(User $user, FinancialRecurrence $recurrence): bool
    {
        return $this->access->allows($user, strtolower($recurrence->kind).'.view', $recurrence);
    }

    public function update(User $user, FinancialRecurrence $recurrence): bool
    {
        return $this->access->allows($user, strtolower($recurrence->kind).'.update', $recurrence);
    }
}
