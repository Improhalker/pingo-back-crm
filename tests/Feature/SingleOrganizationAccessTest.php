<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SingleOrganizationStrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

/** ADR-028: one company per client account, resolved by the server at login, reload and every request. */
class SingleOrganizationAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Login limiters live in a file store shared by the suite; a distinct client IP per test avoids shared budgets.
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254)]);
    }

    private function member(string $role = 'ADMIN', ?Organization $organization = null): array
    {
        $organization ??= Organization::create(['name' => 'Synthetic '.bin2hex(random_bytes(3))]);
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $user->organizations()->attach($organization, ['role' => $role]);

        return [$user, $organization];
    }

    private function login(User $user)
    {
        return $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD]);
    }

    public function test_single_company_account_logs_in_with_its_organization_resolved(): void
    {
        [$user, $organization] = $this->member('OPERATOR');
        $this->login($user)->assertOk()
            ->assertJsonPath('data.access', 'ok')
            ->assertJsonPath('data.organization.id', (string) $organization->id)
            ->assertJsonPath('data.organization.role', 'OPERATOR');
        // Without a selector the server opens the account's own company; reload is just another /me + /context.
        $this->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.id', (string) $organization->id);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.organization.id', (string) $organization->id);
        $this->withHeader('X-Organization-Id', (string) $organization->id)->getJson('/api/v1/context')->assertOk();
    }

    public function test_forged_selector_never_grants_another_company(): void
    {
        [$user] = $this->member();
        [, $other] = $this->member();
        $this->actingAs($user, 'web');
        $this->withHeader('X-Organization-Id', (string) $other->id)->getJson('/api/v1/context')->assertNotFound();
        $this->withHeader('X-Organization-Id', '999999999')->getJson('/api/v1/customers')->assertNotFound();
        $this->withHeader('X-Organization-Id', 'abc')->getJson('/api/v1/context')->assertUnprocessable();
    }

    public function test_account_without_company_cannot_log_in_and_gets_a_clear_message(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->login($user)->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE')
            ->assertJsonPath('message', 'Sua conta não está vinculada a uma empresa. Fale com a pessoa responsável pelo seu acesso.');
        $this->assertGuest('web');
    }

    public function test_wrong_password_stays_a_generic_validation_error(): void
    {
        $user = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $this->withHeader('Origin', 'http://localhost:3000')
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Wrong-pass-2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_suspended_company_blocks_login_and_running_sessions(): void
    {
        [$user, $organization] = $this->member();
        $organization->forceFill(['suspended_at' => now()])->save();
        $this->login($user)->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_SUSPENDED');
        $this->assertGuest('web');

        $this->actingAs($user, 'web')->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_SUSPENDED');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.access', 'suspended');
    }

    public function test_legacy_account_with_two_companies_is_blocked_without_picking_one(): void
    {
        [$user, $first] = $this->member('ADMIN');
        $second = Organization::create(['name' => 'Second Synthetic']);
        $user->organizations()->attach($second, ['role' => 'OPERATOR']);

        $this->login($user)->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_AMBIGUOUS');
        $this->assertGuest('web');

        // An already open session is blocked too, even when the selector names one of the two companies.
        $this->actingAs($user, 'web');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.access', 'multiple')->assertJsonPath('data.organization', null);
        $this->withHeader('X-Organization-Id', (string) $first->id)->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_AMBIGUOUS');
        $this->getJson('/api/v1/customers')->assertForbidden();
        $this->assertSame(2, $user->organizations()->count());
    }

    public function test_revoked_link_blocks_the_running_session(): void
    {
        [$user, $organization] = $this->member();
        $this->actingAs($user, 'web')->getJson('/api/v1/context')->assertOk();
        $user->organizations()->detach($organization);
        $this->withHeader('X-Organization-Id', (string) $organization->id)->getJson('/api/v1/context')
            ->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.access', 'none');
    }

    public function test_master_keeps_the_platform_flow_and_gets_no_company_context(): void
    {
        $master = User::factory()->create(['password' => Hash::make(self::PASSWORD)]);
        $master->platformAdministrator()->create();
        $this->login($master)->assertOk()->assertJsonPath('data.access', 'platform')->assertJsonPath('data.is_platform_administrator', true)->assertJsonPath('data.organization', null);
        $this->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
    }

    public function test_login_keeps_csrf_session_regeneration_and_logout(): void
    {
        [$user, $organization] = $this->member();
        $this->app->bind(ValidateCsrfToken::class, SingleOrganizationStrictCsrf::class);
        $this->login($user)->assertStatus(419);
        $this->withHeader('Origin', 'http://localhost:3000')->get('/sanctum/csrf-cookie')->assertNoContent();
        $before = session()->getId();
        $token = session()->token();
        $this->withSession(['_token' => $token])->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, '_token' => $token])
            ->assertOk()->assertJsonPath('data.organization.id', (string) $organization->id);
        $this->assertNotSame($before, session()->getId());
        $this->postJson('/api/v1/auth/logout', ['_token' => session()->token()])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_platform_provisioning_always_creates_single_company_accounts(): void
    {
        $master = User::factory()->create();
        $master->platformAdministrator()->create();
        [$existing] = $this->member();
        $this->actingAs($master, 'web')->postJson('/api/v1/platform/organizations', [
            'organization' => ['name' => 'Nova Synthetic', 'timezone' => 'America/Sao_Paulo'],
            'administrator' => ['name' => 'Pessoa', 'email' => $existing->email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD],
        ])->assertUnprocessable()->assertJsonValidationErrors(['administrator.email']);
        $this->assertSame(1, $existing->organizations()->count());
    }

    public function test_legacy_command_reports_and_resolves_only_with_explicit_choice(): void
    {
        [$user, $first] = $this->member('ADMIN');
        $second = Organization::create(['name' => 'Second Synthetic']);
        $user->organizations()->attach($second, ['role' => 'OPERATOR']);

        $this->artisan('platform:legacy-memberships')->expectsOutputToContain($user->email)->assertSuccessful();
        $this->artisan('platform:legacy-memberships', ['--email' => $user->email, '--keep' => '999999'])->assertFailed();
        $this->artisan('platform:legacy-memberships', ['--email' => $user->email, '--keep' => (string) $first->id])
            ->expectsConfirmation('Confirmar o ajuste?', 'no')->assertFailed();
        $this->assertSame(2, $user->organizations()->count());

        $this->artisan('platform:legacy-memberships', ['--email' => $user->email, '--keep' => (string) $first->id])
            ->expectsConfirmation('Confirmar o ajuste?', 'yes')
            ->expectsOutputToContain('Second Synthetic ficou sem pessoa administradora')
            ->assertSuccessful();
        $this->assertSame([$first->id], $user->organizations()->pluck('organizations.id')->all());
        $this->assertNotNull($second->fresh());
        $this->artisan('platform:legacy-memberships')->expectsOutputToContain('Nenhuma conta')->assertSuccessful();
        $this->login($user)->assertOk()->assertJsonPath('data.organization.id', (string) $first->id);
    }

    public function test_platform_detail_flags_legacy_members(): void
    {
        [$user, $first] = $this->member('ADMIN');
        $user->organizations()->attach(Organization::create(['name' => 'Second Synthetic']), ['role' => 'OPERATOR']);
        $master = User::factory()->create();
        $master->platformAdministrator()->create();
        $this->actingAs($master, 'web')->getJson('/api/v1/platform/organizations/'.$first->id)
            ->assertOk()->assertJsonPath('data.members.0.has_other_organizations', true);
        $this->assertSame(2, DB::table('organization_user')->where('user_id', $user->id)->count());
    }
}
