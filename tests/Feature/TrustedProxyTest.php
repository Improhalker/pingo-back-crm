<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/api/v1/test-proxy-context', fn (Request $request) => response()->json([
            'secure' => $request->secure(),
            'ip' => $request->ip(),
            'host' => $request->getHost(),
        ]));
    }

    public function test_untrusted_client_cannot_spoof_tls_or_client_ip(): void
    {
        config(['trustedproxy.proxies' => []]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->withHeaders(['Host' => 'api.pingofin.test', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.5'])
            ->getJson('http://api.pingofin.test/api/v1/test-proxy-context')
            ->assertOk()
            ->assertJsonPath('secure', false)
            ->assertJsonPath('ip', '198.51.100.2');
    }

    public function test_configured_proxy_preserves_tls_and_ip_without_replacing_host(): void
    {
        config(['trustedproxy.proxies' => ['10.123.0.1']]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.123.0.1'])
            ->withHeaders([
                'Host' => 'api.pingofin.test',
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-For' => '203.0.113.5',
                'X-Forwarded-Host' => 'attacker.invalid',
            ])
            ->getJson('http://api.pingofin.test/api/v1/test-proxy-context')
            ->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('ip', '203.0.113.5')
            ->assertJsonPath('host', 'api.pingofin.test');
    }
}
