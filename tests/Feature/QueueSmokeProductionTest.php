<?php

namespace Tests\Feature;

use App\Jobs\BootstrapSmoke;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class QueueSmokeProductionTest extends TestCase
{
    public function test_production_requires_explicit_opt_in_without_dispatching(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Bus::fake();

        $this->artisan('infra:queue-smoke')->assertFailed();

        Bus::assertNothingDispatched();
    }

    public function test_explicit_production_check_only_dispatches_the_technical_job(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        Bus::fake();
        Redis::shouldReceive('get')->once()->andReturn('processed');
        Redis::shouldReceive('del')->once()->andReturn(1);

        $this->artisan('infra:queue-smoke', ['--production' => true])->assertSuccessful();

        Bus::assertDispatched(BootstrapSmoke::class, fn (BootstrapSmoke $job) => $job->queue === 'bootstrap-smoke' && $job->connection === 'redis');
        Bus::assertDispatchedTimes(BootstrapSmoke::class, 1);
    }
}
