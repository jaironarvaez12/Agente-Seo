<?php

namespace App\Jobs;

use App\Models\DominiosModel;
use App\Models\Dominios_Contenido_DetallesModel;
use App\Services\ServicioWordPressContenido;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class TrabajoEnviarContenidoWordPress implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $idDominio,
        public int $idDetalle
    ) {}

    public function handle(ServicioWordPressContenido $wp)
    {
        $dom = DominiosModel::find($this->idDominio);
        $it  = Dominios_Contenido_DetallesModel::find($this->idDetalle);

        if (!$dom || !$it) return;

        // Solo si está activo
        if (!(int)($dom->wp_auto_activo ?? 0)) return;

        // Solo enviar si ya está generado (o si tú quieres permitir "generado" y "programado")
        if (!in_array($it->estatus, ['generado'], true)) return;
        
        // Si ya se envió antes (por si reintenta cola)
        if (!empty($it->wp_id) || in_array($it->estatus, ['publicado','programado'], true)) return;

        $modo = (string)($dom->wp_auto_modo ?? 'manual');
        if ($modo === 'manual') return;

        if ($modo === 'publicar') {
            [$ok, $msg] = $wp->publicarDetalle($dom->id_dominio, $it->id_dominio_contenido_detalle);
            Log::info('AUTO WP publicar', ['ok' => $ok, 'msg' => $msg, 'dominio' => $dom->id_dominio, 'detalle' => $it->id_dominio_contenido_detalle]);
            return;
        }

        if ($modo === 'programar') {
            $tz = config('app.timezone');

            // si no hay próxima programación, empezamos en "ahora + 10 min"
            $base = $dom->wp_siguiente_programacion
                ? Carbon::parse($dom->wp_siguiente_programacion, $tz)
                : now($tz)->addMinutes(10);

            [$ok, $msg] = $wp->programarDetalle($dom->id_dominio, $it->id_dominio_contenido_detalle, $base);

            if ($ok) {
                $intervalo = (int)($dom->wp_programar_cada_minutos ?? 60);
                $dom->wp_siguiente_programacion = $base->copy()->addMinutes(max(1, $intervalo));
                $dom->save();
            }

            Log::info('AUTO WP programar', ['ok' => $ok, 'msg' => $msg, 'dominio' => $dom->id_dominio, 'detalle' => $it->id_dominio_contenido_detalle, 'fecha' => $base->toDateTimeString()]);
            return;
        }
    }
}
