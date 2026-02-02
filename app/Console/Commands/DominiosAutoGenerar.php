<?php

namespace App\Console\Commands;

use App\Jobs\TrabajoAutoGenerarContenidoDominio;
use App\Models\DominiosModel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DominiosAutoGenerar extends Command
{
    protected $signature = 'dominios:auto-generar';
    protected $description = 'Despacha jobs de auto-generación para dominios que ya toca ejecutar';

    public function handle(): int
    {
        $ahora = now();

        // 1) Traer candidatos (sin lock)
        $candidatos = DominiosModel::query()
            ->select('id_dominio')
            ->where('auto_generacion_activa', 1)
            ->where(function ($q) use ($ahora) {
                $q->whereNull('auto_siguiente_ejecucion')
                  ->orWhere('auto_siguiente_ejecucion', '<=', $ahora);
            })
            ->orderBy('auto_siguiente_ejecucion', 'asc')
            ->limit(50)
            ->pluck('id_dominio')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (!$candidatos) {
            return self::SUCCESS;
        }

        $idsParaDespachar = [];

        // 2) Por cada dominio, lock + re-check + reserva + guardar + marcar para despachar
        foreach ($candidatos as $idDominio) {

            DB::transaction(function () use ($idDominio, $ahora, &$idsParaDespachar) {

                $dominio = DominiosModel::where('id_dominio', (int)$idDominio)
                    ->lockForUpdate()
                    ->first();

                if (!$dominio) return;
                if (!(int)($dominio->auto_generacion_activa ?? 0)) return;

                // Re-check dentro del lock
                if (!empty($dominio->auto_siguiente_ejecucion) && $ahora->lt($dominio->auto_siguiente_ejecucion)) {
                    return;
                }

                // ✅ Reservar próxima ejecución antes de despachar (evita duplicados)
                $dominio->auto_siguiente_ejecucion = $this->calcularSiguienteEjecucion($dominio, $ahora);
                $dominio->save();

                $idsParaDespachar[] = (int)$dominio->id_dominio;
            });
        }

        // 3) Despachar fuera de transacción
        foreach ($idsParaDespachar as $idDominio) {
            TrabajoAutoGenerarContenidoDominio::dispatch((int)$idDominio, false)
                ->onConnection('database')
                ->onQueue('default');
        }

        Log::info('AUTO: comando ejecutado', [
            'cantidad_despachados' => count($idsParaDespachar),
        ]);

        return self::SUCCESS;
    }

    private function calcularSiguienteEjecucion(DominiosModel $dominio, Carbon $ahora): Carbon
    {
        $frecuencia = (string)($dominio->auto_frecuencia ?? 'daily');

        return match ($frecuencia) {
            'hourly' => $ahora->copy()->addHour(),
            'weekly' => $ahora->copy()->addWeek(),
            'custom' => $ahora->copy()->addMinutes(max(1, (int)($dominio->auto_cada_minutos ?? 1))),
            default  => $ahora->copy()->addDay(), // daily
        };
    }
}
