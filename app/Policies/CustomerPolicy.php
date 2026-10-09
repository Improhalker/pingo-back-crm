<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    use ChecksOrganizationCapability;

    private const DENIAL = 'Seu perfil pode consultar clientes, mas somente administradores da empresa alteram o cadastro.';

    public function viewAny(User $user): Response
    {
        return $this->capability($user, 'customer.view', 'Você não pode consultar clientes desta empresa.');
    }

    public function view(User $user, Customer $customer): Response
    {
        return $this->capability($user, 'customer.view', 'Você não pode consultar clientes desta empresa.', $customer);
    }

    public function create(User $user): Response
    {
        return $this->capability($user, 'customer.manage', self::DENIAL);
    }

    public function update(User $user, Customer $customer): Response
    {
        return $this->capability($user, 'customer.manage', self::DENIAL, $customer);
    }
}
