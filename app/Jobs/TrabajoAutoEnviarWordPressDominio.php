<?php
namespace App\Jobs;

use App\Models\DominiosModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class TrabajoAutoEnviarWordPressDominio implements ShouldQueue
{
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $idDominio, public bool $forzar = false) {}

    public function handle(): void
    {
        $dominio = DominiosModel::where('id_dominio', $this->idDominio)->first();
        if (!$dominio) return;
        if (!(int)$dominio->wp_auto_activo) return;

        // Si no es forzar, respeta la fecha (el command ya filtra, pero por seguridad)
        if (!$this->forzar && $dominio->wp_siguiente_ejecucion && now()->lt($dominio->wp_siguiente_ejecucion)) {
            return;
        }

        // ✅ Cantidad por corrida (ej: 3)
        $limit = max(1, (int)($dominio->wp_tareas_por_ejecucion ?? 3));

        // ✅ Buscar contenidos "generado" PENDIENTES DE WP (ajusta esto a tu tabla real)
        // TODO: cambia 'contenidos', 'estatus', 'wp_estado' según tu esquema
        $contenidos = DB::table('contenidos')
            ->where('id_dominio', $this->idDominio)
            ->where('estatus', 'generado')
            ->where(function ($q) {
                $q->whereNull('wp_estado')->orWhere('wp_estado', 'pendiente');
            })
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get();

        // Si no hay contenido, solo recalcula próxima ejecución y salir
        if ($contenidos->isEmpty()) {
            $dominio->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dominio);
            $dominio->save();
            return;
        }

        // ✅ Publicar o Programar
        if ($dominio->wp_auto_modo === 'publicar') {
            foreach ($contenidos as $c) {
                // TODO: enviar a WP publish
                // $this->wpService->publicar($dominio, $c);

                DB::table('contenidos')->where('id', $c->id)->update([
                    'wp_estado' => 'enviado',
                    'updated_at' => now(),
                ]);
            }
        } elseif ($dominio->wp_auto_modo === 'programar') {
            $base = now()->addMinutes(10);
            $step = max(1, (int)($dominio->wp_programar_cada_minutos ?? 60));

            foreach ($contenidos as $i => $c) {
                $fecha = $base->copy()->addMinutes($i * $step);

                // TODO: enviar a WP future con $fecha
                // $this->wpService->programar($dominio, $c, $fecha);

                DB::table('contenidos')->where('id', $c->id)->update([
                    'wp_estado' => 'programado',
                    'wp_programado_para' => $fecha,
                    'updated_at' => now(),
                ]);
            }

            // opcional: mantener tu campo existente
            $dominio->wp_siguiente_programacion = now()->addMinutes($step);
        }

        // ✅ Recalcular próxima ejecución según regla
        $dominio->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dominio);
        $dominio->save();
    }

    private function calcularProximaEjecucionWp($dominio)
    {
        $now = now();
        switch ($dominio->wp_regla_tipo) {
            case 'cada_x_minutos':
                return $now->addMinutes(max(1, (int)$dominio->wp_cada_minutos));

            case 'cada_n_dias':
                $n = max(1, (int)$dominio->wp_cada_dias);
                return (int)$dominio->wp_excluir_fines_semana ? $this->addBusinessDays($now, $n) : $now->addDays($n);

            case 'diario':
                return $this->proximaDiaria($now, (string)$dominio->wp_hora_del_dia);

            case 'semanal':
                return $this->proximaSemanal($now, (array)($dominio->wp_dias_semana ?? []), (string)$dominio->wp_hora_del_dia);

            case 'manual':
            default:
                return $now->addYears(5);
        }
    }

    private function addBusinessDays($date, int $days)
    {
        $d = $date->copy();
        $added = 0;
        while ($added < $days) {
            $d->addDay();
            if ($d->isoWeekday() <= 5) $added++;
        }
        return $d;
    }

    private function proximaDiaria($now, string $hora)
    {
        [$h,$m] = array_map('intval', explode(':', $hora));
        $candidate = $now->copy()->setTime($h, $m, 0);
        if ($candidate->lessThanOrEqualTo($now)) $candidate->addDay();
        return $candidate;
    }

    private function proximaSemanal($now, array $diasIso, string $hora)
    {
        $diasIso = array_values(array_unique(array_map('intval', $diasIso)));
        sort($diasIso);
        if (empty($diasIso)) return $this->proximaDiaria($now, $hora);

        [$h,$m] = array_map('intval', explode(':', $hora));

        for ($add=0; $add<7; $add++) {
            $d = $now->copy()->addDays($add);
            if (in_array((int)$d->isoWeekday(), $diasIso, true)) {
                $candidate = $d->copy()->setTime($h, $m, 0);
                if ($candidate->greaterThan($now)) return $candidate;
            }
        }

        $d = $now->copy()->addWeek();
        $first = $diasIso[0];
        while ((int)$d->isoWeekday() !== $first) $d->addDay();
        return $d->setTime($h, $m, 0);
    }

}
