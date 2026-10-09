<?php

namespace App\Support;

use App\Models\Organization;
use LogicException;

// Bound as scoped: never a static/global tenant or singleton.
class OrganizationContext
{
    private ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new LogicException('Authorized organization context required.');
    }

    public function clear(): void
    {
        $this->organization = null;
    }
}
