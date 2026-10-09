<?php

namespace App\Internal\Kanban;

use App\Models\User;

/** Internal project tool: enabled explicitly and restricted to platform masters. */
final class KanbanAccess
{
    public static function enabled(): bool
    {
        return config('internal_kanban.enabled') === true;
    }

    public static function allows(?User $user): bool
    {
        return $user !== null
            && self::enabled()
            && $user->isPlatformAdministrator();
    }
}
