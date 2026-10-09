<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Redis;

class BootstrapSmoke implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 15;

    public int $backoff = 2;

    public function __construct(public string $executionId) {}

    public function handle(): void
    {
        // Technical marker only. Repeated processing writes the same bounded, expiring result.
        Redis::setex('bootstrap:smoke:'.$this->executionId, 120, 'processed');
    }
}
