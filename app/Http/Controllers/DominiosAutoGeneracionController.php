<?php

namespace App\Http\Controllers;

use App\Jobs\TrabajoAutoGenerarContenidoDominio;
use App\Models\DominiosModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            // AUTO-GENERACIÓN
            'auto_generacion_activa'    => 'nullable|boolean',
            'auto_frecuencia'           => 'required|in:daily,hourly,weekly,custom',
            'auto_cada_minutos'         => 'nullable|required_if:auto_frecuencia,custom|integer|min:1|max:10080',
            'auto_tareas_por_ejecucion' => 'required|integer|min:1|max:50',

            // AUTO WORDPRESS
            'wp_auto_activo'            => 'nullable|boolean',
            'wp_auto_modo'              => 'required|in:manual,publicar,programar',
            'wp_programar_cada_minutos' => 'nullable|required_if:wp_auto_modo,programar|integer|min:1|max:10080',
        ], [
            'auto_frecuencia.in' => 'Frecuencia inválida.',
            'auto_cada_minutos.required_if' => 'Debes indicar cada cuántos minutos cuando la frecuencia es Personalizada.',
            'wp_auto_modo.in' => 'Modo de WordPress inválido.',
            'wp_programar_cada_minutos.required_if' => 'Debes indicar cada cuántos minutos cuando el modo de WordPress es Programar.',
        ]);

        // =========================
        // AUTO-GENERACIÓN
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

        if ($autoActivo && empty($dominio->auto_siguiente_ejecucion)) {
            $dominio->auto_siguiente_ejecucion = now();
        }

        if (!$autoActivo) {
            $dominio->auto_siguiente_ejecucion = null;
        }

        // =========================
        // AUTO WORDPRESS
        // =========================
        $wpActivo = (bool) $request->input('wp_auto_activo', false);
        $dominio->wp_auto_activo = $wpActivo ? 1 : 0;

        $dominio->wp_auto_modo = $wpActivo ? $datos['wp_auto_modo'] : 'manual';

        if ($dominio->wp_auto_modo === 'programar') {
            $dominio->wp_programar_cada_minutos = (int) ($datos['wp_programar_cada_minutos'] ?? 60);

            if (empty($dominio->wp_siguiente_programacion)) {
                $dominio->wp_siguiente_programacion = now()->addMinutes(10);
            }
        } else {
            $dominio->wp_programar_cada_minutos = (int) ($dominio->wp_programar_cada_minutos ?? 60);
            // opcional:
            // $dominio->wp_siguiente_programacion = null;
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
}
