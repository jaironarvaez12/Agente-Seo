<?php

namespace App\Console\Commands;

use App\Jobs\TrabajoAutoEnviarWordPressDominio;
use App\Models\DominiosModel;
use Illuminate\Console\Command;

class WpAutoRun extends Command
{
    protected $signature = 'wp:programacion-run {--dominio=} {--forzar}';
    protected $description = 'Dispara el proceso de envío a WordPress para dominios con WP auto activo (independiente de auto-generación).';

   public function handle(): int
    {
        $q = DominiosModel::query()
            ->where('wp_auto_activo', 1)
            ->whereNotNull('wp_siguiente_ejecucion')
            ->where('wp_siguiente_ejecucion', '<=', now());

        if ($this->option('dominio')) {
            $q->where('id_dominio', (int)$this->option('dominio'));
        }

        $dominios = $q->limit(200)->get();
        if ($dominios->isEmpty()) return self::SUCCESS;

        $forzar = (bool)$this->option('forzar');

        foreach ($dominios as $d) {
            TrabajoAutoEnviarWordPressDominio::dispatch((int)$d->id_dominio, $forzar)
                ->onConnection('database')
                ->onQueue('default');
        }

        return self::SUCCESS;
    }
}
