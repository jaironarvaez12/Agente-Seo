<?php

namespace App\Jobs;

use App\Models\DominiosModel;
use App\Models\User;
use App\Services\ServicioGenerarDominio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class TrabajoAutoGenerarContenidoDominio implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public int $idDominio) {}

    public function handle(\App\Services\ServicioGenerarDominio $servicioGenerador)
    {
        $dominio = \App\Models\DominiosModel::where('id_dominio', (int)$this->idDominio)->first();
        if (!$dominio) {
            Log::warning('AUTO: dominio no encontrado', ['id_dominio' => $this->idDominio]);
            return;
        }

        $actor = \App\Models\User::find((int)$dominio->creado_por);
        if (!$actor) {
            Log::warning('AUTO: actor no encontrado', ['id_dominio' => $dominio->id_dominio, 'creado_por' => $dominio->creado_por]);
            return;
        }

        $maxTareas = (int)($dominio->auto_tareas_por_ejecucion ?? 2);

        [$ok, $mensaje, $jobs] = $servicioGenerador->iniciarGeneracion((int)$dominio->id_dominio, $actor, $maxTareas);

        Log::info('AUTO: resultado iniciarGeneracion', [
            'id_dominio' => $dominio->id_dominio,
            'ok' => $ok,
            'mensaje' => $mensaje,
            'cantidad_jobs' => is_array($jobs) ? count($jobs) : 0,
        ]);

        if ($ok && !empty($jobs)) {
            $servicioGenerador->despacharJobs($jobs);
            Log::info('AUTO: jobs despachados', ['id_dominio' => $dominio->id_dominio, 'cantidad' => count($jobs)]);
        }
    }
}
