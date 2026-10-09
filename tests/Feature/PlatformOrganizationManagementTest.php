<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformOrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private const ORGS = '/api/v1/platform/organizations';

    private const STRONG = 'Synthetic-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // File-based limiters are shared across runs; a distinct client IP per test avoids cross-test budgets.
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254)]);
    }

    private function master(): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::STRONG)]);
        $user->platformAdministrator()->create();

        return $user;
    }

    /** @return array{0: Organization, 1: User} */
    private function company(string $name = 'Synthetic Company', string $role = 'ADMIN'): array
    {
        $organization = Organization::create(['name' => $name]);
        $user = User::factory()->create(['password' => Hash::make(self::STRONG)]);
        $user->organizations()->attach($organization, ['role' => $role]);

        return [$organization, $user];
    }

    public function test_company_members_cannot_manage_companies_nor_probe_ids(): void
    {
        [$organization, $admin] = $this->company();
        $this->actingAs($admin, 'web');
        $url = self::ORGS.'/'.$organization->id;
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'PLATFORM_FORBIDDEN');
        $this->getJson(self::ORGS.'/999999999')->assertForbidden();
        $this->patchJson($url, ['name' => 'Hijacked'])->assertForbidden();
        $this->postJson($url.'/suspend')->assertForbidden();
        $this->postJson($url.'/administrators', ['name' => 'X', 'email' => 'x@test.invalid', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertForbidden();
        $this->deleteJson($url, ['confirmation_name' => $organization->name])->assertForbidden();
        $organization->refresh();
        $this->assertSame('Synthetic Company', $organization->name);
        $this->assertFalse($organization->isSuspended());
        $this->assertSame(1, User::count());
    }

    public function test_visitor_gets_401_on_management_endpoints(): void
    {
        [$organization] = $this->company();
        $this->getJson(self::ORGS.'/'.$organization->id)->assertUnauthorized();
        $this->deleteJson(self::ORGS.'/'.$organization->id, ['confirmation_name' => $organization->name])->assertUnauthorized();
        $this->assertNotNull($organization->fresh());
    }

    public function test_master_sees_detail_with_members_but_no_hashes(): void
    {
        [$organization, $admin] = $this->company();
        [$other] = $this->company('Other Company');
        $admin->organizations()->attach($other, ['role' => 'OPERATOR']);
        $this->actingAs($this->master(), 'web');
        $response = $this->getJson(self::ORGS.'/'.$organization->id)->assertOk()
            ->assertJsonPath('data.name', 'Synthetic Company')
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.members_count', 1)
            ->assertJsonPath('data.members.0.email', $admin->email)
            ->assertJsonPath('data.members.0.role', 'ADMIN')
            ->assertJsonPath('data.members.0.only_in_this_organization', false);
        $this->assertStringNotContainsStringIgnoringCase('password', $response->getContent());
        $this->getJson(self::ORGS.'/999999999')->assertNotFound();
    }

    public function test_master_updates_name_and_timezone_only(): void
    {
        [$organization, $admin] = $this->company();
        $this->actingAs($this->master(), 'web');
        $url = self::ORGS.'/'.$organization->id;
        $this->patchJson($url, ['name' => 'Renamed Synthetic', 'timezone' => 'America/Manaus', 'suspended_at' => now()->toIso8601String(), 'id' => 5])
            ->assertOk()->assertJsonPath('data.name', 'Renamed Synthetic')->assertJsonPath('data.timezone', 'America/Manaus')->assertJsonPath('data.status', 'ACTIVE');
        $this->patchJson($url, ['timezone' => 'Mars/Base'])->assertUnprocessable()->assertJsonValidationErrors(['timezone']);
        $this->patchJson($url, ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $organization->refresh();
        $this->assertSame('Renamed Synthetic', $organization->name);
        $this->assertFalse($organization->isSuspended());
        $this->assertSame('ADMIN', $admin->organizations()->first()->pivot->role);
    }

    public function test_suspension_blocks_tenant_context_and_reactivation_restores_it(): void
    {
        [$organization, $admin] = $this->company();
        $master = $this->master();
        $url = self::ORGS.'/'.$organization->id;
        $this->actingAs($master, 'web')->postJson($url.'/suspend')->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->postJson($url.'/suspend')->assertOk()->assertJsonPath('data.status', 'SUSPENDED');

        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->withHeader('X-Organization-Id', (string) $organization->id)
            ->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_SUSPENDED');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.organizations.0.suspended', true);
        $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $admin->id]);

        $this->app['auth']->forgetGuards();
        $this->actingAs($master, 'web')->postJson($url.'/reactivate')->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web')->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.role', 'ADMIN');
    }

    public function test_list_filters_by_search_and_status_with_member_counts(): void
    {
        [$a] = $this->company('Autoescola Alfa');
        $this->company('Padaria Beta');
        $a->forceFill(['suspended_at' => now()])->save();
        $this->actingAs($this->master(), 'web');
        $this->getJson(self::ORGS.'?search=alfa')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Autoescola Alfa')->assertJsonPath('data.0.members_count', 1)->assertJsonPath('data.0.status', 'SUSPENDED');
        $this->getJson(self::ORGS.'?status=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Padaria Beta');
        $this->getJson(self::ORGS.'?status=suspended')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::ORGS.'?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::ORGS.'?status=deleted')->assertUnprocessable();
    }

    public function test_master_adds_a_new_administrator_and_existing_accounts_are_untouched(): void
    {
        [$organization, $existing] = $this->company();
        $hash = $existing->password;
        $this->actingAs($this->master(), 'web');
        $url = self::ORGS.'/'.$organization->id.'/administrators';
        $this->postJson($url, ['name' => 'Segunda Pessoa', 'email' => ' Segunda@Test.Invalid ', 'password' => self::STRONG, 'password_confirmation' => self::STRONG, 'role' => 'OPERATOR'])
            ->assertCreated()->assertJsonPath('data.members_count', 2);
        $added = User::where('email', 'segunda@test.invalid')->firstOrFail();
        $this->assertSame('ADMIN', $added->organizations()->first()->pivot->role);
        $this->assertTrue(Hash::check(self::STRONG, $added->password));
        $this->assertFalse($added->isPlatformAdministrator());

        $this->postJson($url, ['name' => 'Dup', 'email' => $existing->email, 'password' => 'Other-pass-2026', 'password_confirmation' => 'Other-pass-2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
        $this->postJson($url, ['name' => 'Weak', 'email' => 'weak@test.invalid', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(2, $organization->users()->count());
    }

    public function test_delete_requires_exact_name_and_removes_only_exclusive_accounts(): void
    {
        [$organization, $exclusive] = $this->company('Doomed Synthetic');
        [$other, $otherAdmin] = $this->company('Survivor Synthetic');
        $shared = User::factory()->create();
        $shared->organizations()->attach($organization, ['role' => 'OPERATOR']);
        $shared->organizations()->attach($other, ['role' => 'OPERATOR']);
        $master = $this->master();
        $master->organizations()->attach($organization, ['role' => 'OPERATOR']);
        DB::table('sessions')->insert(['id' => 'synthetic-session', 'user_id' => $exclusive->id, 'payload' => '', 'last_activity' => time()]);
        $url = self::ORGS.'/'.$organization->id;

        $this->actingAs($master, 'web');
        $this->deleteJson($url, ['confirmation_name' => 'doomed synthetic'])->assertUnprocessable()->assertJsonValidationErrors(['confirmation_name']);
        $this->deleteJson($url, [])->assertUnprocessable();
        $this->assertNotNull($organization->fresh());

        $this->deleteJson($url, ['confirmation_name' => 'Doomed Synthetic'])->assertOk()->assertJsonPath('data.removed_accounts', 1);
        $this->assertNull($organization->fresh());
        $this->assertNull($exclusive->fresh());
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-session']);
        $this->assertNotNull($shared->fresh());
        $this->assertSame([$other->id], $shared->organizations()->pluck('organizations.id')->all());
        $this->assertNotNull($master->fresh());
        $this->assertTrue($master->isPlatformAdministrator());
        $this->assertNotNull($otherAdmin->fresh());
        $this->assertSame(0, DB::table('organization_user')->where('organization_id', $organization->id)->count());
        $this->deleteJson($url, ['confirmation_name' => 'Doomed Synthetic'])->assertNotFound();
    }

    public function test_delete_is_refused_with_409_when_other_rows_reference_the_company(): void
    {
        [$organization, $admin] = $this->company('Referenced Synthetic');
        // Simulates MySQL error 1451 from a future tenant-owned table with a restrictive foreign key.
        // (Creating a real table here would implicitly commit the RefreshDatabase transaction.)
        Organization::deleting(function () {
            $pdo = new \PDOException('Cannot delete or update a parent row: a foreign key constraint fails');
            $pdo->errorInfo = ['23000', 1451, 'synthetic'];
            throw new QueryException('mysql', 'delete from organizations', [], $pdo);
        });
        try {
            $this->actingAs($this->master(), 'web')
                ->deleteJson(self::ORGS.'/'.$organization->id, ['confirmation_name' => 'Referenced Synthetic'])
                ->assertStatus(409)->assertJsonPath('code', 'PLATFORM_ORGANIZATION_HAS_DATA');
        } finally {
            Organization::flushEventListeners();
        }
        // The transaction rolled back: memberships and the exclusive account are intact.
        $this->assertNotNull($organization->fresh());
        $this->assertNotNull($admin->fresh());
        $this->assertSame(1, $organization->users()->count());
    }
}
