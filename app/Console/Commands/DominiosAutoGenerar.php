<?php

namespace App\Console\Commands;

use App\Jobs\TrabajoAutoGenerarContenidoDominio;
use App\Models\DominiosModel;
use Illuminate\Console\Command;

class DominiosAutoGenerar extends Command
{
    protected $signature = 'dominios:auto-generar';
    protected $description = 'Genera contenido automáticamente según la configuración del dominio';

    public function handle()
    {
        $ahora = now();

        $dominios = DominiosModel::query()
            ->where('auto_generacion_activa', 1)
            ->where(function ($q) use ($ahora) {
                $q->whereNull('auto_siguiente_ejecucion')
                  ->orWhere('auto_siguiente_ejecucion', '<=', $ahora);
            })
            ->get();

        foreach ($dominios as $dominio) {
            TrabajoAutoGenerarContenidoDominio::dispatch((int)$dominio->id_dominio)
                ->onConnection('database')
                ->onQueue('default');

            $dominio->auto_siguiente_ejecucion = $this->calcularSiguienteEjecucion($dominio);
            $dominio->save();
        }

        return self::SUCCESS;
    }

    private function calcularSiguienteEjecucion($dominio)
    {
        $base = now();

        return match ($dominio->auto_frecuencia) {
            'hourly' => $base->addHour(),
            'weekly' => $base->addWeek(),
            'custom' => $base->addMinutes((int)$dominio->auto_cada_minutos),
            default  => $base->addDay(), // daily
        };
    }
}
