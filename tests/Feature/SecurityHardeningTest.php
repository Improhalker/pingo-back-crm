<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_answers_cannot_be_sniffed_rendered_or_framed(): void
    {
        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_public_greeting_keeps_its_own_policy(): void
    {
        $this->get('/')->assertOk()->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; img-src 'self'; style-src 'self'; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
        );
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->getJson('https://localhost/api/v1/health/live')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->getJson('http://localhost/api/v1/health/live')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_login_is_limited_per_account_even_from_rotating_addresses(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->postJson('/api/v1/auth/login', ['email' => 'Victim@Example.com', 'password' => 'wrong-guess'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/v1/auth/login', ['email' => 'victim@example.com ', 'password' => 'wrong-guess'])
            ->assertStatus(429);
        // Another account from the same new address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/v1/auth/login', ['email' => 'someone@example.com', 'password' => 'wrong-guess'])
            ->assertUnprocessable();
    }

    public function test_platform_login_shares_the_per_account_limit(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$attempt}"])
                ->postJson('/api/v1/platform/auth/login', ['email' => 'master@example.com', 'password' => 'wrong-guess'])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/v1/platform/auth/login', ['email' => 'master@example.com', 'password' => 'wrong-guess'])
            ->assertStatus(429);
    }
}
