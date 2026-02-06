<?php
namespace App\Jobs;

use App\Models\DominiosModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
class TrabajoAutoEnviarWordPressDominio implements ShouldQueue
{
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $idDominio, public bool $forzar = false) {}

   public function handle(): void
{
    $dominio = DominiosModel::where('id_dominio', $this->idDominio)->first();
    if (!$dominio) {
        Log::warning("WP JOB: dominio {$this->idDominio} no existe");
        return;
    }

    Log::info("WP JOB START dominio={$this->idDominio} forzar=" . ($this->forzar ? '1' : '0'));

    if (!(int)$dominio->wp_auto_activo) {
        Log::info("WP JOB: wp_auto_activo=0 dominio={$this->idDominio}");
        return;
    }

    if (!$this->forzar && $dominio->wp_siguiente_ejecucion && now()->lt($dominio->wp_siguiente_ejecucion)) {
        Log::info("WP JOB: aun no toca dominio={$this->idDominio} next={$dominio->wp_siguiente_ejecucion}");
        return;
    }

    $limit = max(1, (int)($dominio->wp_tareas_por_ejecucion ?? 3));

    $query = DB::table('dominios_contenido_detalles')
        ->where('id_dominio', $this->idDominio)
        ->where('estatus', 'generado')
        ->where(function ($q) {
            $q->whereNull('wp_post_id')->orWhere('wp_post_id', 0);
        })
        ->orderBy('id_dominio_contenido_detalle', 'asc')
        ->limit($limit);

    // Si vas a programar, evita reprogramar los que ya tienen fecha:
    if ($dominio->wp_auto_modo === 'programar') {
        $query->whereNull('scheduled_at');
    }

    $contenidos = $query->get();

    Log::info("WP JOB: encontrados=" . $contenidos->count() . " dominio={$this->idDominio} modo={$dominio->wp_auto_modo} regla={$dominio->wp_regla_tipo}");

    if ($contenidos->isEmpty()) {
        $dominio->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dominio);
        $dominio->save();
        Log::info("WP JOB: sin contenido, next=" . $dominio->wp_siguiente_ejecucion);
        return;
    }

    // Aquí ya deberías llamar tu servicio WP real.
    // Mientras tanto, deja log por cada item para confirmar ciclo
    foreach ($contenidos as $c) {
        Log::info("WP JOB ITEM id_detalle={$c->id_dominio_contenido_detalle} title=" . substr((string)$c->title, 0, 40));
    }

    // ✅ IMPORTANTE: al final siempre recalcula next
    $dominio->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dominio);
    $dominio->save();

    Log::info("WP JOB END dominio={$this->idDominio} next=" . $dominio->wp_siguiente_ejecucion);
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
