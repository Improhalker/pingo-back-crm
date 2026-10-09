<?php

namespace App\Actions\Platform;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates a client company, its first administrator and the ADMIN membership in one MySQL transaction.
 * The role is decided here, on the server. An existing e-mail is never reused or modified.
 */
final class ProvisionOrganization
{
    /** @return array{0: Organization, 1: User} */
    public function __invoke(User $actor, string $organizationName, string $timezone, string $adminName, string $adminEmail, string $adminPassword): array
    {
        try {
            [$organization, $admin] = DB::transaction(function () use ($organizationName, $timezone, $adminName, $adminEmail, $adminPassword) {
                $organization = Organization::create(['name' => $organizationName, 'timezone' => $timezone]);
                // The model's "hashed" cast hashes the password; the plain value is never stored or logged.
                $admin = User::create(['name' => $adminName, 'email' => $adminEmail, 'password' => $adminPassword]);
                $organization->users()->attach($admin->id, ['role' => 'ADMIN']);

                return [$organization, $admin];
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent submission of the same e-mail: the transaction rolled back, nothing is left behind.
            throw ValidationException::withMessages(['administrator.email' => ['Este e-mail já está em uso.']]);
        }

        Log::info('platform.organization_provisioned', [
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'administrator_user_id' => $admin->id,
        ]);

        return [$organization, $admin];
    }
}
