<?php

namespace App\Actions\Platform;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Permanently deletes a client company in one transaction: its memberships, the company row and the
 * accounts that only had access to it (they would otherwise remain as logins without any access).
 * Accounts linked to other companies and platform administrators are always kept.
 *
 * Future tenant-owned tables must reference organizations with restrictive foreign keys: when such
 * data exists, the delete fails with 409 and the company should be suspended instead.
 */
final class DeleteOrganization
{
    /** @return array{removed_accounts: int} */
    public function __invoke(User $actor, Organization $organization, string $confirmationName): array
    {
        try {
            $result = DB::transaction(function () use ($organization, $confirmationName) {
                $locked = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
                if ($confirmationName !== $locked->name) {
                    throw ValidationException::withMessages(['confirmation_name' => ['Digite o nome exato da empresa para confirmar a exclusão.']]);
                }

                $memberIds = DB::table('organization_user')->where('organization_id', $locked->id)->pluck('user_id');
                DB::table('organization_user')->where('organization_id', $locked->id)->delete();
                $orphanIds = User::query()->whereIn('id', $memberIds)
                    ->whereDoesntHave('organizations')
                    ->whereDoesntHave('platformAdministrator')
                    ->lockForUpdate()
                    ->pluck('id');
                // Sessions of removed accounts are dropped so no authenticated cookie survives the account.
                DB::table('sessions')->whereIn('user_id', $orphanIds)->delete();
                User::query()->whereIn('id', $orphanIds)->delete();
                $locked->delete();

                return ['removed_accounts' => $orphanIds->count(), 'removed_memberships' => $memberIds->count()];
            });
        } catch (QueryException $exception) {
            // 1451: a row of another table still references this organization or one of its accounts.
            if (($exception->errorInfo[1] ?? null) === 1451) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Esta empresa possui dados vinculados e não pode ser excluída. Suspenda-a em vez disso.',
                    'code' => 'PLATFORM_ORGANIZATION_HAS_DATA',
                    'errors' => (object) [],
                    'request_id' => request()->attributes->get('request_id'),
                ], 409));
            }
            throw $exception;
        }

        Log::info('platform.organization_deleted', [
            'actor_user_id' => $actor->id,
            'organization_id' => $organization->id,
            'removed_memberships' => $result['removed_memberships'],
            'removed_accounts' => $result['removed_accounts'],
        ]);

        return ['removed_accounts' => $result['removed_accounts']];
    }
}
