<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user): Response
    {
        return $this->capability($user, 'expense.view', 'Você não pode consultar despesas desta empresa.');
    }

    public function view(User $user, Expense $expense): Response
    {
        return $this->capability($user, 'expense.view', 'Você não pode consultar despesas desta empresa.', $expense);
    }

    public function update(User $user, Expense $entry): Response
    {
        return $this->capability($user, 'expense.update', 'Seu perfil não pode editar este lançamento.', $entry);
    }

    public function delete(User $user, Expense $entry): Response
    {
        return $this->capability($user, 'expense.delete', 'Seu perfil não pode excluir este lançamento.', $entry);
    }

    public function create(User $user): Response
    {
        return $this->capability($user, 'expense.create', 'Seu perfil não pode registrar despesas nesta empresa.');
    }
}
