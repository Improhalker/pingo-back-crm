<?php

namespace App\Policies;

use App\Models\Receivable;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use Illuminate\Auth\Access\Response;

class ReceivablePolicy
{
    use ChecksOrganizationCapability;

    public function settle(User $user, Receivable $receivable): Response
    {
        return $this->capability($user, 'receivable.settle', 'Seu perfil não pode registrar recebimentos nesta empresa.', $receivable);
    }

    public function correct(User $user, Receivable $receivable): Response
    {
        return $this->capability($user, 'receipt.correct', 'Seu perfil não pode corrigir recebimentos nesta empresa.', $receivable);
    }
}
