<?php

namespace App\Console\Commands;

use App\Jobs\TrabajoAutoEnviarWordPressDominio;
use App\Models\DominiosModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WpProgramacionRun extends Command
{
    protected $signature = 'wp:programacion-run {--dominio=} {--forzar}';
    protected $description = 'Auto-publicación / programación a WordPress (independiente de auto-generación).';

    public function handle(): int
{
    $q = DominiosModel::query()
        ->where('wp_auto_activo', 1)
        // ✅ solo dominios con fecha definida
        ->whereNotNull('wp_siguiente_ejecucion')
        // ✅ solo si YA toca
        ->where('wp_siguiente_ejecucion', '<=', now());

    if ($this->option('dominio')) {
        $q->where('id_dominio', (int) $this->option('dominio'));
    }

    $dominios = $q->limit(200)->get();

    if ($dominios->isEmpty()) {
        $this->info('No hay dominios listos para WP.');
        return self::SUCCESS;
    }

    $forzar = (bool) $this->option('forzar');

    foreach ($dominios as $d) {
        // ✅ si regla manual, no auto
        if (($d->wp_regla_tipo ?? 'manual') === 'manual' && !$forzar) {
            continue;
        }

        TrabajoAutoEnviarWordPressDominio::dispatch((int)$d->id_dominio, $forzar)
            ->onQueue('default');
    }

    Log::info('WP CMD programacion-run jobs=' . $dominios->count());
    $this->info('Jobs despachados: ' . $dominios->count());

    return self::SUCCESS;
}

}