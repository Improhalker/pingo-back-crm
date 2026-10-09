<?php

namespace App\Actions\Platform;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Creates a new account with the ADMIN role in an existing company. Existing accounts are never reused. */
final class AddOrganizationAdministrator
{
    public function __invoke(User $actor, Organization $organization, string $name, string $email, string $password): User
    {
        try {
            $admin = DB::transaction(function () use ($organization, $name, $email, $password) {
                // Lock the company so a concurrent delete cannot leave an orphan account behind.
                Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                $admin = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
                $organization->users()->attach($admin->id, ['role' => 'ADMIN']);

                return $admin;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Este e-mail já está em uso.']]);
        }

        Log::info('platform.administrator_added', [
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'administrator_user_id' => $admin->id,
        ]);

        return $admin;
    }
}
