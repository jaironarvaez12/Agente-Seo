<?php

namespace App\Console\Commands;

use App\Jobs\TrabajoAutoGenerarContenidoDominio;
use App\Models\DominiosModel;
use Illuminate\Console\Command;

class DominiosAutoGenerar extends Command
{
    protected $signature = 'dominios:auto-generar';
    protected $description = 'Despacha jobs de auto-generación para dominios que ya toca ejecutar';

    public function handle(): int
    {
        $ahora = now();

        $dominios = DominiosModel::query()
            ->where('auto_generacion_activa', 1)
            ->where(function ($q) use ($ahora) {
                $q->whereNull('auto_siguiente_ejecucion')
                  ->orWhere('auto_siguiente_ejecucion', '<=', $ahora);
            })
            ->limit(50)
            ->get();

        foreach ($dominios as $dominio) {
            TrabajoAutoGenerarContenidoDominio::dispatch((int)$dominio->id_dominio, false)
                ->onConnection('database')
                ->onQueue('default');
        }

        return self::SUCCESS;
    }
}
