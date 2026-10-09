<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class InfrastructureHealth
{
    public function check(): array
    {
        $checks = ['application' => 'up', 'mysql' => 'down', 'redis' => 'down'];
        try {
            DB::select('SELECT 1');
            $checks['mysql'] = 'up';
        } catch (Throwable) {
            Log::warning('Health dependency unavailable', ['dependency' => 'mysql']);
        }
        try {
            $pong = (string) Redis::connection()->ping();
            $checks['redis'] = strtoupper($pong) === 'PONG' || $pong === '1' ? 'up' : 'down';
        } catch (Throwable) {
            Log::warning('Health dependency unavailable', ['dependency' => 'redis']);
        }
        $ready = $checks['mysql'] === 'up' && $checks['redis'] === 'up';

        return ['status' => $ready ? 'ready' : ($checks['mysql'] === 'up' ? 'degraded' : 'unavailable'), 'checks' => $checks];
    }
}
