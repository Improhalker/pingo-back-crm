<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
{
    use ChecksOrganizationCapability;

    private const DENIAL = 'Seu perfil pode consultar categorias, mas somente administradores da empresa as alteram.';

    public function viewAny(User $user): Response
    {
        return $this->capability($user, 'category.view', 'Você não pode consultar categorias desta empresa.');
    }

    public function view(User $user, Category $category): Response
    {
        return $this->capability($user, 'category.view', 'Você não pode consultar categorias desta empresa.', $category);
    }

    public function create(User $user): Response
    {
        return $this->capability($user, 'category.manage', self::DENIAL);
    }

    public function update(User $user, Category $category): Response
    {
        return $this->capability($user, 'category.manage', self::DENIAL, $category);
    }
}
