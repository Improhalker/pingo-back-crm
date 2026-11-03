<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicWelcomeTest extends TestCase
{
    public function test_welcome_is_public_without_database_access_or_session_cookies(): void
    {
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1]);
        DB::purge('mysql');

        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="pt-BR">', false)
            ->assertSee('Oi, eu sou')
            ->assertSee('/brand/pingo.png', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeaderMissing('Set-Cookie')
            ->assertHeaderMissing('Location');
    }
}
