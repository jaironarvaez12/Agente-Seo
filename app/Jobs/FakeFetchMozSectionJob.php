<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FakeFetchMozSectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 1;

    public function __construct(public int $reportId) {}

    public function handle(): void
    {
        // Simula trabajo (sin consumir Moz)
        sleep(2);

        // Si quieres guardar algo fake en DB, hazlo aquí.
        // Yo lo dejo como no-op para que no falle si no tienes columnas.
    }
}
