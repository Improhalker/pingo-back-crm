<?php

namespace App\Policies;

use App\Models\Payable;
use App\Models\User;
use App\Policies\Concerns\ChecksOrganizationCapability;
use Illuminate\Auth\Access\Response;

class PayablePolicy
{
    use ChecksOrganizationCapability;

    public function settle(User $user, Payable $payable): Response
    {
        return $this->capability($user, 'payable.settle', 'Seu perfil não pode registrar pagamentos nesta empresa.', $payable);
    }

    public function correct(User $user, Payable $payable): Response
    {
        return $this->capability($user, 'payment.correct', 'Seu perfil não pode corrigir pagamentos nesta empresa.', $payable);
    }
}
