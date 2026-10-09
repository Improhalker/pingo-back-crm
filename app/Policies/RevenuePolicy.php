<?php

namespace App\Policies;

use App\Models\Revenue;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use Illuminate\Auth\Access\Response;

class RevenuePolicy
{
    use ChecksOrganizationCapability;

    public function viewAny(User $user): Response
    {
        return $this->capability($user, 'revenue.view', 'Você não pode consultar receitas desta empresa.');
    }

    public function view(User $user, Revenue $revenue): Response
    {
        return $this->capability($user, 'revenue.view', 'Você não pode consultar receitas desta empresa.', $revenue);
    }

    public function update(User $user, Revenue $entry): Response
    {
        return $this->capability($user, 'revenue.update', 'Seu perfil não pode editar este lançamento.', $entry);
    }

    public function delete(User $user, Revenue $entry): Response
    {
        return $this->capability($user, 'revenue.delete', 'Seu perfil não pode excluir este lançamento.', $entry);
    }

    public function create(User $user): Response
    {
        return $this->capability($user, 'revenue.create', 'Seu perfil não pode registrar receitas nesta empresa.');
    }
}
