<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * One company per client account (ADR-028). Resolves, on the server, which organization a company user
 * works in. Legacy accounts linked to more than one company are blocked with an explicit status instead
 * of silently picking one; platform administrators stay in the platform flow and never get a company here.
 */
final class CompanyMembership
{
    public const OK = 'ok';

    public const NONE = 'none';

    public const MULTIPLE = 'multiple';

    public const SUSPENDED = 'suspended';

    public const PLATFORM = 'platform';

    /** @return array{status: string, organization: ?Organization} */
    public static function resolve(User $user): array
    {
        if ($user->isPlatformAdministrator()) {
            return ['status' => self::PLATFORM, 'organization' => null];
        }
        // At most two rows are needed to tell "one" from "more than one".
        $organizations = $user->organizations()->orderBy('organizations.id')->limit(2)->get();
        if ($organizations->isEmpty()) {
            return ['status' => self::NONE, 'organization' => null];
        }
        if ($organizations->count() > 1) {
            return ['status' => self::MULTIPLE, 'organization' => null];
        }
        $organization = $organizations->first();

        return ['status' => $organization->isSuspended() ? self::SUSPENDED : self::OK, 'organization' => $organization];
    }

    /** Public error for a status that does not allow opening a company. @return array{code: string, message: string} */
    public static function denial(string $status): array
    {
        return match ($status) {
            self::SUSPENDED => ['code' => 'ORGANIZATION_SUSPENDED', 'message' => 'Esta empresa está suspensa. Fale com a equipe do PingoFin.'],
            self::MULTIPLE => ['code' => 'ORGANIZATION_AMBIGUOUS', 'message' => 'Sua conta está vinculada a mais de uma empresa. Por segurança, o acesso fica bloqueado até a equipe do PingoFin ajustar o vínculo.'],
            default => ['code' => 'ORGANIZATION_NONE', 'message' => 'Sua conta não está vinculada a uma empresa. Fale com a pessoa responsável pelo seu acesso.'],
        };
    }
}
