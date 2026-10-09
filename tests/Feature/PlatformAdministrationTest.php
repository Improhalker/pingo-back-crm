<?php

namespace Tests\Feature;

use App\Actions\Platform\ProvisionOrganization;
use App\Models\Organization;
use App\Models\PlatformAdministrator;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class PlatformStrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class PlatformAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private const ORGS = '/api/v1/platform/organizations';

    private const STRONG = 'Synthetic-pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        // Login limiters live in a file store shared by the whole suite; a distinct client IP per test
        // keeps these tests from consuming the per-IP budget of other test classes.
        $this->withServerVariables(['REMOTE_ADDR' => '10.'.random_int(1, 254).'.'.random_int(1, 254).'.'.random_int(1, 254)]);
    }

    private function master(string $email = 'master@test.invalid'): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make(self::STRONG)]);
        $user->platformAdministrator()->create();

        return $user;
    }

    private function companyUser(string $role): array
    {
        $user = User::factory()->create(['password' => Hash::make(self::STRONG)]);
        $organization = Organization::create(['name' => 'Synthetic company '.$role]);
        $user->organizations()->attach($organization, ['role' => $role]);

        return [$user, $organization];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'organization' => ['name' => 'Autoescola Sintética', 'timezone' => 'America/Sao_Paulo'],
            'administrator' => [
                'name' => 'Pessoa Administradora',
                'email' => 'admin.novo@test.invalid',
                'password' => self::STRONG,
                'password_confirmation' => self::STRONG,
            ],
        ], $overrides);
    }

    public function test_visitor_cannot_use_platform_endpoints(): void
    {
        $this->getJson(self::ORGS)->assertUnauthorized();
        $this->postJson(self::ORGS, $this->payload())->assertUnauthorized();
        $this->assertSame(0, Organization::count());
    }

    public function test_company_admin_and_operator_cannot_use_platform_endpoints(): void
    {
        foreach (['ADMIN', 'OPERATOR'] as $role) {
            [$user] = $this->companyUser($role);
            $before = Organization::count();
            $this->actingAs($user, 'web')->getJson(self::ORGS)->assertForbidden()->assertJsonPath('code', 'PLATFORM_FORBIDDEN');
            // Authorization runs before validation: even an invalid payload is a 403, not a 422.
            $this->postJson(self::ORGS, [])->assertForbidden()->assertJsonPath('code', 'PLATFORM_FORBIDDEN');
            $this->postJson(self::ORGS, $this->payload())->assertForbidden();
            $this->assertSame($before, Organization::count());
        }
    }

    public function test_master_lists_organizations_with_only_public_fields_and_pagination(): void
    {
        foreach (range(1, 3) as $i) {
            Organization::create(['name' => "Synthetic list {$i}"]);
        }
        $this->actingAs($this->master(), 'web');
        $response = $this->getJson(self::ORGS.'?per_page=2')->assertOk();
        $response->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2);
        $this->assertSame(['id', 'name', 'timezone', 'status', 'suspended_at', 'members_count', 'created_at', 'updated_at', 'first_administrator'], array_keys($response->json('data.0')));
        $this->assertNull($response->json('data.0.first_administrator'));
        $this->assertSame('Synthetic list 3', $response->json('data.0.name'));
        $this->assertStringNotContainsStringIgnoringCase('password', $response->getContent());
        $this->getJson(self::ORGS.'?per_page=101')->assertUnprocessable();
        $this->getJson(self::ORGS.'?page=2&per_page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_master_provisions_organization_user_and_admin_membership(): void
    {
        $master = $this->master();
        $response = $this->actingAs($master, 'web')->postJson(self::ORGS, $this->payload())->assertCreated();
        $response->assertJsonPath('data.name', 'Autoescola Sintética')
            ->assertJsonPath('data.timezone', 'America/Sao_Paulo')
            ->assertJsonPath('data.first_administrator.email', 'admin.novo@test.invalid');
        $this->assertStringNotContainsStringIgnoringCase('password', $response->getContent());

        $organization = Organization::where('name', 'Autoescola Sintética')->firstOrFail();
        $admin = User::where('email', 'admin.novo@test.invalid')->firstOrFail();
        $this->assertNotSame(self::STRONG, $admin->password);
        $this->assertTrue(Hash::check(self::STRONG, $admin->password));
        $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $admin->id, 'role' => 'ADMIN']);
        $this->assertFalse($admin->isPlatformAdministrator());
        $this->assertSame(0, $master->organizations()->count());
    }

    public function test_client_cannot_choose_roles_or_platform_privileges(): void
    {
        $this->actingAs($this->master(), 'web')->postJson(self::ORGS, $this->payload([
            'administrator' => ['role' => 'OPERATOR', 'platform_administrator' => true, 'is_platform_administrator' => true],
            'role' => 'OPERATOR',
            'platform_administrator' => true,
        ]))->assertCreated();
        $admin = User::where('email', 'admin.novo@test.invalid')->firstOrFail();
        $this->assertSame('ADMIN', $admin->organizations()->first()->pivot->role);
        $this->assertFalse($admin->isPlatformAdministrator());
        $this->assertSame(1, PlatformAdministrator::count());
    }

    public function test_company_user_cannot_grant_themselves_platform_authorization(): void
    {
        [$user] = $this->companyUser('ADMIN');
        $this->actingAs($user, 'web');
        $this->postJson(self::ORGS, $this->payload(['administrator' => ['email' => $user->email]]) + ['platform_administrator' => true])->assertForbidden();
        $this->assertFalse($user->fresh()->isPlatformAdministrator());
        $this->assertSame(0, PlatformAdministrator::count());
        // Routes under /platform manage companies only: there is no endpoint that grants platform authorization.
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
            ->filter(fn ($line) => str_contains($line, 'platform'))->sort()->values()->all();
        $this->assertSame([
            'DELETE api/v1/platform/organizations/{organization}',
            'GET|HEAD api/v1/platform/organizations',
            'GET|HEAD api/v1/platform/organizations/{organization}',
            'PATCH api/v1/platform/organizations/{organization}',
            'POST api/v1/platform/auth/login',
            'POST api/v1/platform/organizations',
            'POST api/v1/platform/organizations/{organization}/administrators',
            'POST api/v1/platform/organizations/{organization}/reactivate',
            'POST api/v1/platform/organizations/{organization}/suspend',
        ], $uris);
        // Mass assignment cannot create the authorization.
        $this->assertTrue(User::create(['name' => 'X Synthetic', 'email' => 'x@test.invalid', 'password' => self::STRONG, 'platform_administrator' => true])->exists);
        $this->assertSame(0, PlatformAdministrator::count());
    }

    public function test_master_has_no_automatic_access_to_company_context(): void
    {
        [, $organization] = $this->companyUser('ADMIN');
        $master = $this->master();
        $this->actingAs($master, 'web')->withHeader('X-Organization-Id', (string) $organization->id)
            ->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
        $this->assertSame(0, DB::table('organization_user')->where('user_id', $master->id)->count());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.is_platform_administrator', true)->assertJsonPath('data.organizations', []);
    }

    public function test_existing_email_is_rejected_without_changing_account_or_creating_records(): void
    {
        [$existing, $other] = $this->companyUser('OPERATOR');
        $passwordHash = $existing->password;
        $this->actingAs($this->master(), 'web');
        $organizations = Organization::count();
        $users = User::count();
        foreach ([$existing->email, strtoupper($existing->email), '  '.$existing->email.' '] as $email) {
            $this->postJson(self::ORGS, $this->payload(['administrator' => ['email' => $email, 'password' => 'Another-pass-2026', 'password_confirmation' => 'Another-pass-2026']]))
                ->assertUnprocessable()->assertJsonValidationErrors(['administrator.email']);
        }
        $this->assertSame($organizations, Organization::count());
        $this->assertSame($users, User::count());
        $existing->refresh();
        $this->assertSame($passwordHash, $existing->password);
        $this->assertSame([$other->id => 'OPERATOR'], $existing->organizations->mapWithKeys(fn ($o) => [$o->id => $o->pivot->role])->all());
    }

    public function test_validation_of_names_timezone_and_password_policy(): void
    {
        $this->actingAs($this->master(), 'web');
        $this->postJson(self::ORGS, $this->payload(['organization' => ['timezone' => 'Mars/Olympus']]))->assertUnprocessable()->assertJsonValidationErrors(['organization.timezone']);
        $this->postJson(self::ORGS, $this->payload(['organization' => ['name' => ' ']]))->assertUnprocessable()->assertJsonValidationErrors(['organization.name']);
        foreach (['short1A', 'onlylettersonlyletters', '1234567890123456'] as $weak) {
            $this->postJson(self::ORGS, $this->payload(['administrator' => ['password' => $weak, 'password_confirmation' => $weak]]))->assertUnprocessable()->assertJsonValidationErrors(['administrator.password']);
        }
        $this->postJson(self::ORGS, $this->payload(['administrator' => ['password_confirmation' => 'Different-pass-2026']]))->assertUnprocessable()->assertJsonValidationErrors(['administrator.password']);
        $long = str_repeat('a1', 40);
        $this->postJson(self::ORGS, $this->payload(['administrator' => ['password' => $long, 'password_confirmation' => $long]]))->assertUnprocessable()->assertJsonValidationErrors(['administrator.password']);
        $this->assertSame(0, Organization::count());
        $this->assertSame(1, User::count());
    }

    public function test_failure_during_provisioning_leaves_no_partial_records(): void
    {
        $master = $this->master();
        $organizations = Organization::count();
        $users = User::count();
        // The organization is already inserted when creating the user fails: everything must roll back.
        User::creating(function () {
            throw new RuntimeException('forced failure');
        });
        try {
            $this->expectException(RuntimeException::class);
            app(ProvisionOrganization::class)($master, 'Atomic Synthetic', 'America/Sao_Paulo', 'Pessoa', 'atomic@test.invalid', self::STRONG);
        } finally {
            User::flushEventListeners();
            $this->assertSame($organizations, Organization::count());
            $this->assertSame($users, User::count());
            $this->assertSame(0, DB::table('organization_user')->count());
        }
    }

    public function test_unique_conflict_that_slips_past_validation_rolls_back_the_organization(): void
    {
        $master = $this->master();
        $organizations = Organization::count();
        try {
            app(ProvisionOrganization::class)($master, 'Race Synthetic', 'America/Sao_Paulo', 'Pessoa', $master->email, self::STRONG);
            $this->fail('Expected a validation error for the duplicated e-mail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('administrator.email', $e->errors());
        }
        $this->assertSame($organizations, Organization::count());
        $this->assertSame(1, User::count());
    }

    public function test_new_administrator_logs_in_and_reaches_only_their_own_organization(): void
    {
        [, $other] = $this->companyUser('ADMIN');
        $this->actingAs($this->master(), 'web')->postJson(self::ORGS, $this->payload())->assertCreated();
        $organization = Organization::where('name', 'Autoescola Sintética')->firstOrFail();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/login', ['email' => 'admin.novo@test.invalid', 'password' => self::STRONG])->assertOk()
            ->assertJsonPath('data.is_platform_administrator', false)
            ->assertJsonPath('data.organizations.0.id', (string) $organization->id)
            ->assertJsonPath('data.organizations.0.role', 'ADMIN');
        $this->withHeader('X-Organization-Id', (string) $organization->id)->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.role', 'ADMIN');
        $this->withHeader('X-Organization-Id', (string) $other->id)->getJson('/api/v1/context')->assertNotFound();
        $this->getJson(self::ORGS)->assertForbidden();
    }

    public function test_platform_login_accepts_only_platform_administrators(): void
    {
        $master = $this->master();
        [$company] = $this->companyUser('ADMIN');
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/platform/auth/login', ['email' => $company->email, 'password' => self::STRONG])->assertUnprocessable();
        $this->assertGuest('web');
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/platform/auth/login', ['email' => $master->email, 'password' => 'wrong-password-123'])->assertUnprocessable();
        $this->assertGuest('web');
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/platform/auth/login', ['email' => $master->email, 'password' => self::STRONG])->assertOk()
            ->assertJsonPath('data.is_platform_administrator', true);
        $this->assertAuthenticatedAs($master, 'web');
    }

    public function test_platform_login_keeps_csrf_session_regeneration_and_logout_invalidation(): void
    {
        $master = $this->master();
        $this->app->bind(ValidateCsrfToken::class, PlatformStrictCsrf::class);
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/platform/auth/login', ['email' => $master->email, 'password' => self::STRONG])
            ->assertStatus(419);
        $this->withHeader('Origin', 'http://localhost:3000')->get('/sanctum/csrf-cookie')->assertNoContent();
        $oldId = session()->getId();
        $token = session()->token();
        $this->withSession(['_token' => $token])->postJson('/api/v1/platform/auth/login', ['email' => $master->email, 'password' => self::STRONG, '_token' => $token])->assertOk();
        $this->assertNotSame($oldId, session()->getId());
        $this->getJson(self::ORGS)->assertOk();
        $authenticatedId = session()->getId();
        $this->postJson('/api/v1/auth/logout', ['_token' => session()->token()])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => $authenticatedId]);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ORGS)->assertUnauthorized();
    }

    public function test_platform_login_is_rate_limited(): void
    {
        $email = 'limit-'.bin2hex(random_bytes(6)).'@test.invalid';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/platform/auth/login', ['email' => $email, 'password' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/platform/auth/login', ['email' => $email, 'password' => 'invalid'])->assertStatus(429);
    }

    public function test_platform_authorization_table_constraints(): void
    {
        $master = $this->master();
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['platform_administrators']);
        $this->assertSame('InnoDB', $engine->engine);
        try {
            DB::table('platform_administrators')->insert(['user_id' => $master->id]);
            $this->fail('A user must have at most one platform authorization row.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, PlatformAdministrator::count());
        }
        try {
            DB::table('platform_administrators')->insert(['user_id' => 999999999]);
            $this->fail('Foreign key must reject unknown users.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }
        $this->expectException(QueryException::class);
        $master->delete();
    }

    public function test_existing_membership_constraints_still_hold_after_provisioning(): void
    {
        $this->actingAs($this->master(), 'web')->postJson(self::ORGS, $this->payload())->assertCreated();
        $admin = User::where('email', 'admin.novo@test.invalid')->firstOrFail();
        $organization = $admin->organizations()->firstOrFail();
        $this->expectException(UniqueConstraintViolationException::class);
        $admin->organizations()->attach($organization, ['role' => 'OPERATOR']);
    }

    public function test_create_master_command_creates_account_without_memberships_or_leaking_the_password(): void
    {
        $this->artisan('platform:create-master', ['--name' => 'Pessoa Master', '--email' => 'Novo.Master@Test.Invalid'])
            ->expectsQuestion('Senha ('.PasswordPolicy::DESCRIPTION.')', self::STRONG)
            ->expectsQuestion('Confirme a senha', self::STRONG)
            ->doesntExpectOutputToContain(self::STRONG)
            ->assertSuccessful();
        $user = User::where('email', 'novo.master@test.invalid')->firstOrFail();
        $this->assertTrue($user->isPlatformAdministrator());
        $this->assertTrue(Hash::check(self::STRONG, $user->password));
        $this->assertSame(0, $user->organizations()->count());
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/platform/auth/login', ['email' => 'novo.master@test.invalid', 'password' => self::STRONG])->assertOk();
    }

    public function test_create_master_command_never_converts_an_existing_account(): void
    {
        [$existing] = $this->companyUser('ADMIN');
        $hash = $existing->password;
        $this->artisan('platform:create-master', ['--name' => 'Pessoa', '--email' => $existing->email])
            ->expectsOutputToContain('Já existe uma conta com este e-mail')
            ->assertFailed();
        $this->assertFalse($existing->fresh()->isPlatformAdministrator());
        $this->assertSame($hash, $existing->fresh()->password);
    }

    public function test_create_master_command_rejects_weak_or_mismatched_passwords_and_non_interactive_use(): void
    {
        $this->artisan('platform:create-master', ['--name' => 'Pessoa', '--email' => 'weak@test.invalid'])
            ->expectsQuestion('Senha ('.PasswordPolicy::DESCRIPTION.')', 'short')
            ->expectsQuestion('Confirme a senha', 'short')
            ->assertFailed();
        $this->artisan('platform:create-master', ['--name' => 'Pessoa', '--email' => 'mismatch@test.invalid'])
            ->expectsQuestion('Senha ('.PasswordPolicy::DESCRIPTION.')', self::STRONG)
            ->expectsQuestion('Confirme a senha', 'Different-pass-2026')
            ->expectsOutputToContain('A confirmação da senha não confere.')
            ->assertFailed();
        $this->artisan('platform:create-master', ['--name' => 'Pessoa', '--email' => 'ci@test.invalid', '--no-interaction' => true])->assertFailed();
        $this->assertSame(0, User::count());
        $this->assertSame(0, PlatformAdministrator::count());
    }
}
