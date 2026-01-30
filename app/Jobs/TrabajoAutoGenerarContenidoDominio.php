<?php

namespace App\Jobs;

use App\Models\DominiosModel;
use App\Models\User;
use App\Services\ServicioGeneradorDominio; // <- AJUSTA si tu servicio tiene otro nombre
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class TrabajoAutoGenerarContenidoDominio implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $idDominio,
        public bool $forzar = false
    ) {}

    public function handle(ServicioGeneradorDominio $servicioGenerador)
    {
        Log::info('AUTO: job iniciado', [
            'id_dominio' => $this->idDominio,
            'forzar' => $this->forzar,
        ]);

        $dominio = DominiosModel::where('id_dominio', (int)$this->idDominio)->first();

        if (!$dominio) {
            Log::warning('AUTO: dominio no encontrado', ['id_dominio' => $this->idDominio]);
            return;
        }

        if (!(int)($dominio->auto_generacion_activa ?? 0)) {
            Log::info('AUTO: auto_generacion_activa apagada', ['id_dominio' => $dominio->id_dominio]);
            return;
        }

        // ✅ Solo valida "aún no toca" cuando NO es forzado
        if (
            !$this->forzar
            && !empty($dominio->auto_siguiente_ejecucion)
            && now()->lt($dominio->auto_siguiente_ejecucion)
        ) {
            Log::info('AUTO: aun no toca ejecutar', [
                'id_dominio' => $dominio->id_dominio,
                'auto_siguiente_ejecucion' => (string)$dominio->auto_siguiente_ejecucion,
            ]);
            return;
        }

        // Actor: el usuario principal que creó el dominio
        $actor = User::find((int)($dominio->creado_por ?? 0));
        if (!$actor) {
            Log::warning('AUTO: actor no encontrado (creado_por)', [
                'id_dominio' => $dominio->id_dominio,
                'creado_por' => $dominio->creado_por,
            ]);
            return;
        }

        $maxTareas = (int)($dominio->auto_tareas_por_ejecucion ?? 1);
        $maxTareas = max(1, min($maxTareas, 50));

        try {
            // ✅ Aquí llamas a TU lógica actual de generación (licencias, límites, etc.)
            // Debe devolver: [ok, mensaje, jobs]
            // jobs = arreglo de payloads para despachar GenerarContenidoKeywordJob

            [$ok, $mensaje, $jobs] = $servicioGenerador->iniciarGeneracion(
                (int)$dominio->id_dominio,
                $actor,
                $maxTareas
            );

            Log::info('AUTO: resultado iniciarGeneracion', [
                'id_dominio' => $dominio->id_dominio,
                'ok' => $ok,
                'mensaje' => $mensaje,
                'cantidad_jobs' => is_array($jobs) ? count($jobs) : 0,
            ]);

            if ($ok && is_array($jobs) && count($jobs) > 0) {
                $servicioGenerador->despacharJobs($jobs);

                Log::info('AUTO: jobs despachados', [
                    'id_dominio' => $dominio->id_dominio,
                    'cantidad' => count($jobs),
                ]);
            }

            // ✅ (Opcional recomendado): actualizar siguiente ejecución aquí SOLO si quieres
            // Normalmente esto se maneja en el comando/daemon, no necesariamente en el job.

        } catch (\Throwable $e) {
            Log::error('AUTO: error en job', [
                'id_dominio' => $dominio->id_dominio,
                'error' => $e->getMessage(),
            ]);

            // Si quieres que aparezca en failed_jobs, lanza la excepción:
            throw $e;
        }
    }
}
