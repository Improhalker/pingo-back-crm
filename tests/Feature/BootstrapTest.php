<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class StrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create(['password' => Hash::make('test-only-password')]);
        $a = Organization::create(['name' => 'Synthetic A']);
        $b = Organization::create(['name' => 'Synthetic B']);
        $user->organizations()->attach($a, ['role' => 'ADMIN']);

        return [$user, $a, $b];
    }

    public function test_session_is_required(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_bearer_header_does_not_enable_token_authentication(): void
    {
        $this->withHeader('Authorization', 'Bearer synthetic-not-a-token')
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_context_fails_closed_and_does_not_leak_other_organizations(): void
    {
        [$user, $a, $b] = $this->fixture();
        // ADR-028: without a selector the server resolves the account's single company.
        $this->actingAs($user, 'web')->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.id', (string) $a->id);
        $this->withHeader('X-Organization-Id', 'invalid')->getJson('/api/v1/context')->assertUnprocessable();
        $this->withHeader('X-Organization-Id', (string) $b->id)->getJson('/api/v1/context')->assertNotFound();
        $this->withHeader('X-Organization-Id', (string) $a->id)->getJson('/api/v1/context')->assertOk()->assertJsonPath('data.id', (string) $a->id);
        $this->expectException(LogicException::class);
        app(OrganizationContext::class)->organization();
    }

    public function test_role_is_per_membership_and_admin_has_no_global_access(): void
    {
        // One company per account (ADR-028): the role comes from that single membership.
        [$user, $a, $b] = $this->fixture();
        $operator = User::factory()->create();
        $operator->organizations()->attach($b, ['role' => 'OPERATOR']);
        $this->actingAs($user, 'web')->getJson('/api/v1/context')->assertJsonPath('data.role', 'ADMIN');
        $this->app['auth']->forgetGuards();
        $this->actingAs($operator, 'web')->getJson('/api/v1/context')->assertJsonPath('data.role', 'OPERATOR');
        $operator->organizations()->detach($b);
        $this->getJson('/api/v1/context')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
        $this->assertFalse(Schema::hasColumn('users', 'role'));
    }

    public function test_membership_constraints_are_real_mysql_constraints(): void
    {
        [$user, $a] = $this->fixture();
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['organization_user']);
        $this->assertSame('InnoDB', $engine->engine);
        $this->expectException(UniqueConstraintViolationException::class);
        $user->organizations()->attach($a, ['role' => 'OPERATOR']);
    }

    public function test_csrf_is_enforced_even_in_this_test(): void
    {
        $this->app->bind(ValidateCsrfToken::class, StrictCsrf::class);
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/login', ['email' => 'synthetic@test.invalid', 'password' => 'invalid'])->assertStatus(419)->assertJsonPath('code', 'CSRF_EXPIRED');
    }

    public function test_cookie_session_login_regenerates_and_logout_invalidates_session(): void
    {
        [$user] = $this->fixture();
        $this->app->bind(ValidateCsrfToken::class, StrictCsrf::class);
        $this->withHeader('Origin', 'http://localhost:3000')->get('/sanctum/csrf-cookie')->assertNoContent();
        $oldId = session()->getId();
        $token = session()->token();
        $this->withSession(['_token' => $token])->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'test-only-password', '_token' => $token])->assertOk()->assertJsonPath('data.id', (string) $user->id);
        $this->assertNotSame($oldId, session()->getId());
        $this->getJson('/api/v1/auth/me')->assertOk();
        $authenticatedId = session()->getId();
        $this->postJson('/api/v1/auth/logout', ['_token' => session()->token()])->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => $authenticatedId]);
        $this->assertGuest('web');
        // HTTP feature tests reuse the container; a real request creates fresh guards.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_health_checks_real_mysql_and_redis(): void
    {
        $this->getJson('/api/v1/health/live')->assertOk()->assertJsonPath('data.status', 'alive');
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.checks.mysql', 'up')->assertJsonPath('data.checks.redis', 'up');
    }

    public function test_redis_failure_is_explicit_and_does_not_block_login(): void
    {
        [$user] = $this->fixture();
        config(['database.redis.default.port' => 1]);
        Redis::purge('default');
        $this->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('data.status', 'degraded')->assertJsonPath('data.checks.mysql', 'up')->assertJsonPath('data.checks.redis', 'down');
        $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'test-only-password'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_mysql_failure_does_not_claim_readiness(): void
    {
        $originalPort = config('database.connections.mysql.port');
        config(['database.connections.mysql.port' => 1]);
        DB::purge('mysql');
        try {
            // Readiness must bypass session storage even with the frontend Origin.
            $this->withHeader('Origin', 'http://localhost:3000')->getJson('/api/v1/health')->assertStatus(503)->assertJsonPath('data.status', 'unavailable')->assertJsonPath('data.checks.mysql', 'down');
            $this->getJson('/api/v1/health/live')->assertOk();
        } finally {
            // Restore the configured test connection before RefreshDatabase rolls back.
            config(['database.connections.mysql.port' => $originalPort]);
            DB::purge('mysql');
        }
    }

    public function test_bad_login_is_not_success_and_login_is_rate_limited(): void
    {
        $email = 'limit-'.bin2hex(random_bytes(6)).'@test.invalid';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->withHeader('Origin', 'http://localhost:3000')->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'invalid'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'invalid'])->assertStatus(429);
    }
}
