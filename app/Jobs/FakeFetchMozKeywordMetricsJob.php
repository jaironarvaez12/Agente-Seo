<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FakeFetchMozKeywordMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 1;

    public function __construct(
        public int $reportId,
        public array $keywords = [],
        public string $device = 'desktop',
        public string $engine = 'google'
    ) {}

    public function handle(): void
    {
        // Simula trabajo (sin consumir Moz)
        sleep(2);

        // Opcional: podrías recortar keywords, generar métricas fake, etc.
        // No guardo nada para no depender de tu esquema.
    }
}