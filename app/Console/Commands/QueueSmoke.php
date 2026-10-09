<?php

namespace App\Console\Commands;

use App\Jobs\BootstrapSmoke;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class QueueSmoke extends Command
{
    protected $signature = 'infra:queue-smoke';

    protected $description = 'Verifica publicação e processamento assíncrono por um worker Redis real (local/teste).';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Smoke test restrito a desenvolvimento/testes.');

            return self::FAILURE;
        }
        $id = (string) Str::uuid();
        $key = 'bootstrap:smoke:'.$id;
        BootstrapSmoke::dispatch($id)->onConnection('redis')->onQueue('bootstrap-smoke');
        try {
            for ($attempt = 0; $attempt < 80; $attempt++) {
                if (Redis::get($key) === 'processed') {
                    $this->info('Worker real processou execução '.$id);

                    return self::SUCCESS;
                }
                usleep(250000);
            }
            $this->error('Worker não confirmou processamento em 20s: '.$id);

            return self::FAILURE;
        } finally {
            Redis::del($key);
        }
    }
}
