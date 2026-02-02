<?php

namespace App\Jobs;

use App\Models\DominiosModel;
use App\Models\User;
use App\Services\ServicioGenerarDominio; // ajusta si tu servicio tiene otro nombre
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrabajoAutoGenerarContenidoDominio implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $idDominio;

    /**
     * IMPORTANTE:
     * No tipar para evitar romper jobs viejos serializados.
     */
    public $forzar = false;

    public function __construct(int $idDominio, $forzar = false)
    {
        $this->idDominio = $idDominio;
        $this->forzar = (bool) $forzar;
    }

    private function esForzado(): bool
    {
        // fallback ultra seguro para jobs viejos
        if (!property_exists($this, 'forzar')) return false;
        return (bool) ($this->forzar ?? false);
    }

    public function handle(ServicioGenerarDominio $servicioGenerador)
    {
        $forzado = $this->esForzado();

        Log::info('AUTO: job iniciado', [
            'id_dominio' => $this->idDominio,
            'forzar' => $forzado,
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

        // ✅ solo validar "aún no toca" si NO está forzado
        if (
            !$forzado
            && !empty($dominio->auto_siguiente_ejecucion)
            && now()->lt($dominio->auto_siguiente_ejecucion)
        ) {
            Log::info('AUTO: aun no toca ejecutar', [
                'id_dominio' => $dominio->id_dominio,
                'auto_siguiente_ejecucion' => (string)$dominio->auto_siguiente_ejecucion,
            ]);
            return;
        }

        // ✅ Actor: creado_por, y fallback a primer asignado
        $actor = null;

        if (!empty($dominio->creado_por)) {
            $actor = User::find((int)$dominio->creado_por);
        }

        if (!$actor) {
            $idAsignado = DB::table('dominios_usuarios')
                ->where('id_dominio', (int)$dominio->id_dominio)
                ->orderBy('id', 'asc')
                ->value('id_usuario');

            if ($idAsignado) {
                $actor = User::find((int)$idAsignado);
            }
        }

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

        } catch (\Throwable $e) {
            Log::error('AUTO: error en job', [
                'id_dominio' => $dominio->id_dominio,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
