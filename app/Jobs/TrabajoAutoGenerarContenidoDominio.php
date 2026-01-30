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
    Log::info('AUTO: job iniciado', ['id_dominio' => $this->idDominio]);

    $dominio = \App\Models\DominiosModel::where('id_dominio', (int)$this->idDominio)->first();
    if (!$dominio) {
        Log::warning('AUTO: dominio no encontrado', ['id_dominio' => $this->idDominio]);
        return;
    }

    if (!(int)($dominio->auto_generacion_activa ?? 0)) {
        Log::info('AUTO: auto_generacion_activa apagada', ['id_dominio' => $dominio->id_dominio]);
        return;
    }

    // Si tu lógica usa auto_siguiente_ejecucion para decidir si toca:
    // (ajusta si tu comando ya filtra esto)
    if (!empty($dominio->auto_siguiente_ejecucion) && now()->lt($dominio->auto_siguiente_ejecucion)) {
        Log::info('AUTO: aun no toca ejecutar', [
            'id_dominio' => $dominio->id_dominio,
            'auto_siguiente_ejecucion' => (string)$dominio->auto_siguiente_ejecucion,
        ]);
        return;
    }

    $actor = \App\Models\User::find((int)$dominio->creado_por);
    if (!$actor) {
        Log::warning('AUTO: actor no encontrado', ['id_dominio' => $dominio->id_dominio, 'creado_por' => $dominio->creado_por]);
        return;
    }

    $maxTareas = (int)($dominio->auto_tareas_por_ejecucion ?? 1);

    try {
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

    } catch (\Throwable $e) {
        Log::error('AUTO: error en job', [
            'id_dominio' => $dominio->id_dominio,
            'error' => $e->getMessage(),
        ]);
        throw $e; // para que caiga a failed_jobs si quieres verlo como fallo
    }
}
}
