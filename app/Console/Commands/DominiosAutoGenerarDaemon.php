<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DominiosAutoGenerarDaemon extends Command
{
    protected $signature = 'dominios:auto-generar-daemon {--segundos=60}';
    protected $description = 'Ejecuta la auto-generación en bucle (sin cron)';

    public function handle()
    {
        $segundos = max(10, (int)$this->option('segundos'));
        $this->info("Daemon iniciado. Ejecuta dominios:auto-generar cada {$segundos}s.");

        while (true) {
            // llama tu comando normal
            $this->call('dominios:auto-generar');

            sleep($segundos);
        }
    }
}
