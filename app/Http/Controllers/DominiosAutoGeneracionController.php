<?php

namespace App\Http\Controllers;

use App\Jobs\TrabajoAutoEnviarWordPressDominio;
use App\Jobs\TrabajoAutoGenerarContenidoDominio;
use App\Models\WpReglaProgramacion;
use App\Models\DominiosModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
class DominiosAutoGeneracionController extends Controller
{
    public function editar(int $idDominio)
    {
        $usuario = auth()->user();
        //dd($usuario );
        if (!$usuario) abort(403);

        $dominio = DominiosModel::where('id_dominio', (int)$idDominio)->firstOrFail();
        $this->validarPermisoDominio($usuario, $dominio);
         

        return view('Dominios.auto_generacion', compact('dominio'));
    }

    public function actualizar(Request $request, int $idDominio)
{
    $usuario = auth()->user();
    if (!$usuario) return back()->withError('Debes iniciar sesión.');

    $dominio = DominiosModel::where('id_dominio', (int)$idDominio)->firstOrFail();
    $this->validarPermisoDominio($usuario, $dominio);

    $datos = $request->validate([
        // AUTO-GENERACIÓN (lo tuyo igual)
        'auto_generacion_activa'    => 'nullable|boolean',
        'auto_frecuencia'           => 'required|in:daily,hourly,weekly,custom',
        'auto_cada_minutos'         => 'nullable|required_if:auto_frecuencia,custom|integer|min:1|max:10080',
        'auto_tareas_por_ejecucion' => 'required|integer|min:1|max:50',

        // AUTO WORDPRESS (existente)
        'wp_auto_activo'            => 'nullable|boolean',
        'wp_auto_modo'              => 'required|in:manual,publicar,programar',
        'wp_programar_cada_minutos' => 'nullable|required_if:wp_auto_modo,programar|integer|min:1|max:10080',

        // ✅ NUEVO: REGLAS WP
        'wp_regla_tipo' => 'required|in:manual,cada_n_dias,cada_x_minutos,diario,semanal',
        'wp_cada_dias'  => 'nullable|required_if:wp_regla_tipo,cada_n_dias|integer|min:1|max:365',
        'wp_cada_minutos' => 'nullable|required_if:wp_regla_tipo,cada_x_minutos|integer|min:1|max:10080',

        'wp_hora_del_dia' => 'nullable|required_if:wp_regla_tipo,diario,semanal|date_format:H:i',
        'wp_dias_semana'  => 'nullable|required_if:wp_regla_tipo,semanal|array',
        'wp_dias_semana.*'=> 'integer|min:1|max:7',

        'wp_excluir_fines_semana' => 'nullable|boolean',
        'wp_tareas_por_ejecucion' => 'required|integer|min:1|max:100',
    ], [
        'auto_frecuencia.in' => 'Frecuencia inválida.',
        'auto_cada_minutos.required_if' => 'Debes indicar cada cuántos minutos cuando la frecuencia es Personalizada.',
        'wp_auto_modo.in' => 'Modo de WordPress inválido.',
        'wp_programar_cada_minutos.required_if' => 'Debes indicar cada cuántos minutos cuando el modo de WordPress es Programar.',
        'wp_hora_del_dia.date_format' => 'Hora inválida. Usa formato HH:MM (ej: 09:30).',
    ]);

    // =========================
    // AUTO-GENERACIÓN (lo tuyo igual)
    // =========================
    $autoActivo = (bool) $request->input('auto_generacion_activa', false);
    $dominio->auto_generacion_activa = $autoActivo ? 1 : 0;
    $dominio->auto_frecuencia = $datos['auto_frecuencia'];

    if ($datos['auto_frecuencia'] === 'custom') {
        $dominio->auto_cada_minutos = (int) ($datos['auto_cada_minutos'] ?? 1);
    } else {
        $dominio->auto_cada_minutos = null;
    }

    $dominio->auto_tareas_por_ejecucion = (int) $datos['auto_tareas_por_ejecucion'];

    if ($autoActivo && empty($dominio->auto_siguiente_ejecucion)) $dominio->auto_siguiente_ejecucion = now();
    if (!$autoActivo) $dominio->auto_siguiente_ejecucion = null;

    // =========================
    // ✅ AUTO WORDPRESS (DESACOPLADO)
    // =========================
    $wpActivo = (bool) $request->input('wp_auto_activo', false);
    $dominio->wp_auto_activo = $wpActivo ? 1 : 0;

    $dominio->wp_auto_modo = $wpActivo ? $datos['wp_auto_modo'] : 'manual';
    $dominio->wp_programar_cada_minutos = (int) ($datos['wp_programar_cada_minutos'] ?? ($dominio->wp_programar_cada_minutos ?? 60));

    // NUEVOS CAMPOS REGLA
    $dominio->wp_regla_tipo = $datos['wp_regla_tipo'];
    $dominio->wp_cada_dias = $datos['wp_regla_tipo'] === 'cada_n_dias' ? (int)$datos['wp_cada_dias'] : null;
    $dominio->wp_cada_minutos = $datos['wp_regla_tipo'] === 'cada_x_minutos' ? (int)$datos['wp_cada_minutos'] : null;
    $dominio->wp_hora_del_dia = in_array($datos['wp_regla_tipo'], ['diario','semanal'], true) ? $datos['wp_hora_del_dia'] : null;
    $dominio->wp_dias_semana = $datos['wp_regla_tipo'] === 'semanal' ? array_values($datos['wp_dias_semana'] ?? []) : null;
    $dominio->wp_excluir_fines_semana = (bool) $request->input('wp_excluir_fines_semana', false);
    $dominio->wp_tareas_por_ejecucion = (int) ($datos['wp_tareas_por_ejecucion'] ?? 3);

    // ✅ próxima ejecución WP
    if ($wpActivo) {
        // si no hay, ponla pronto
        if (empty($dominio->wp_siguiente_ejecucion)) {
            $dominio->wp_siguiente_ejecucion = now()->addMinute();
        }
        // si cambiaron regla, conviene recalcular siempre:
        $dominio->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dominio, now());
    } else {
        $dominio->wp_siguiente_ejecucion = null;
    }

    // Mantén lo viejo si tu UI lo muestra:
    if ($wpActivo && $dominio->wp_auto_modo === 'programar') {
        if (empty($dominio->wp_siguiente_programacion)) $dominio->wp_siguiente_programacion = now()->addMinutes(10);
    }

    $dominio->save();

    return back()->withSuccess('Configuración guardada correctamente.');
}

    public function ejecutarAhora(int $idDominio)
    {
        $usuario = auth()->user();
        if (!$usuario) return back()->withError('Debes iniciar sesión.');

        $dominio = DominiosModel::where('id_dominio', (int)$idDominio)->firstOrFail();
        $this->validarPermisoDominio($usuario, $dominio);

        if (!(int)$dominio->auto_generacion_activa) {
            return back()->withError('Primero activa la auto-generación para poder ejecutar ahora.');
        }

        // ✅ Para UI + daemon (opcional): marcar que ya toca
        $dominio->auto_siguiente_ejecucion = now()->subSeconds(5);
        $dominio->save();

        // ✅ FORZAR: el job se ejecuta ya, aunque la fecha quede en futuro por el daemon
        TrabajoAutoGenerarContenidoDominio::dispatch((int)$dominio->id_dominio, true)
            ->onConnection('database')
            ->onQueue('default');

        return back()->withSuccess('Listo. Se ejecutó ahora mismo.');
    }

    private function validarPermisoDominio($usuario, $dominio): void
    {
        // ✅ EXCEPCIÓN: Administrador puede entrar siempre
       if ($usuario->hasRole('administrador')) return;

        $titular = $usuario->titularLicencia();
        if (!$titular) abort(403);

        $dominiosAsignados = DB::table('dominios_usuarios')
            ->where('id_usuario', (int) $titular->id)   // recomendado si manejas titular/subusuarios
            ->pluck('id_dominio')
            ->map(fn ($v) => (int) $v)
            ->all();

        $esTitular = is_null($usuario->id_usuario_padre);

        $esCreador = false;
        if ($esTitular) {
            $esCreador = ((int)$dominio->creado_por === (int)$titular->id);
        }

        if (!in_array((int)$dominio->id_dominio, $dominiosAsignados, true) && !$esCreador) {
            abort(403, 'No tienes permiso para configurar este dominio.');
        }
    }



 public function wpEjecutarAhora(int $idDominio)
{
    $usuario = auth()->user();
    if (!$usuario) return back()->withError('Debes iniciar sesión.');

    $dominio = DominiosModel::where('id_dominio', (int)$idDominio)->firstOrFail();
    $this->validarPermisoDominio($usuario, $dominio);

    if (!(int)$dominio->wp_auto_activo) {
        return back()->withError('Activa "Enviar automáticamente a WordPress" primero.');
    }

    // ✅ fuerza “ya toca”
    if (property_exists($dominio, 'wp_siguiente_ejecucion')) {
        $dominio->wp_siguiente_ejecucion = now()->subSeconds(5);
        $dominio->save();
    }

    TrabajoAutoEnviarWordPressDominio::dispatch((int)$dominio->id_dominio, true)
        ->onConnection('database')   // OJO: que coincida con tu worker
        ->onQueue('default');

    return back()->withSuccess('Listo. Se forzó el envío a WordPress ahora.');
}

/** ✅ Calcula la próxima ejecución WP según regla */
private function calcularProximaEjecucionWp($dominio, Carbon $now): Carbon
{
    return match ($dominio->wp_regla_tipo) {
        'manual' => $now->copy()->addYears(5),
        'cada_x_minutos' => $now->copy()->addMinutes(max(1, (int)$dominio->wp_cada_minutos)),
        'cada_n_dias' => (int)$dominio->wp_excluir_fines_semana
            ? $this->addBusinessDays($now, max(1, (int)$dominio->wp_cada_dias))
            : $now->copy()->addDays(max(1, (int)$dominio->wp_cada_dias)),
        'diario' => $this->proximaDiaria($now, (string)$dominio->wp_hora_del_dia),
        'semanal' => $this->proximaSemanal($now, (array)($dominio->wp_dias_semana ?? []), (string)$dominio->wp_hora_del_dia),
        default => $now->copy()->addMinutes(60),
    };
}

private function addBusinessDays(Carbon $date, int $days): Carbon
{
    $d = $date->copy();
    $added = 0;
    while ($added < $days) {
        $d->addDay();
        if ($d->isoWeekday() <= 5) $added++;
    }
    return $d;
}

private function proximaDiaria(Carbon $now, string $hora): Carbon
{
    [$h,$m] = array_map('intval', explode(':', $hora));
    $candidate = $now->copy()->setTime($h, $m, 0);
    if ($candidate->lessThanOrEqualTo($now)) $candidate->addDay();
    return $candidate;
}

private function proximaSemanal(Carbon $now, array $diasIso, string $hora): Carbon
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
