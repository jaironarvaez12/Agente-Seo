<?php

namespace App\Http\Controllers;

use App\Models\DominiosModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DominiosAutoGeneracionController extends Controller
{
    public function editar(int $idDominio)
    {
        $usuario = auth()->user();
        if (!$usuario) abort(403);

        $dominio = DominiosModel::where('id_dominio', (int)$idDominio)->firstOrFail();

        $this->validarPermisoDominio($usuario, $dominio);

        return view('dominios.auto_generacion', compact('dominio'));
    }

    public function actualizar(Request $request, int $idDominio)
    {
        $usuario = auth()->user();
        if (!$usuario) return back()->withError('Debes iniciar sesión.');

        $dominio = DominiosModel::where('id_dominio', (int)$idDominio)->firstOrFail();

        $this->validarPermisoDominio($usuario, $dominio);

        $datos = $request->validate([
            'auto_generacion_activa' => 'nullable|boolean',
            'auto_frecuencia' => 'required|in:daily,hourly,weekly,custom',
            'auto_cada_minutos' => 'nullable|required_if:auto_frecuencia,custom|integer|min:1|max:10080', // hasta 7 días
            'auto_tareas_por_ejecucion' => 'required|integer|min:1|max:50',
        ], [
            'auto_frecuencia.in' => 'Frecuencia inválida.',
            'auto_cada_minutos.required_if' => 'Debes indicar cada cuántos minutos cuando la frecuencia es Personalizada.',
        ]);

        // checkbox: si no viene, es false
        $activo = (bool) ($request->input('auto_generacion_activa', false));

        $dominio->auto_generacion_activa = $activo ? 1 : 0;
        $dominio->auto_frecuencia = $datos['auto_frecuencia'];

        // Solo guardamos auto_cada_minutos si custom, si no -> null
        if ($datos['auto_frecuencia'] === 'custom') {
            $dominio->auto_cada_minutos = (int) $datos['auto_cada_minutos'];
        } else {
            $dominio->auto_cada_minutos = null;
        }

        $dominio->auto_tareas_por_ejecucion = (int) $datos['auto_tareas_por_ejecucion'];

        // Si lo activas y no hay siguiente ejecución, lo ponemos para que arranque pronto
        if ($activo && empty($dominio->auto_siguiente_ejecucion)) {
            $dominio->auto_siguiente_ejecucion = now(); // ejecuta en el siguiente ciclo del daemon
        }

        // Si lo desactivas, opcionalmente puedes limpiar la siguiente ejecución
        if (!$activo) {
            $dominio->auto_siguiente_ejecucion = null;
        }

        $dominio->save();

        return back()->withSuccess('Configuración de auto-generación guardada correctamente.');
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

        // Forzar que toque ya
        $dominio->auto_siguiente_ejecucion = now();
        $dominio->save();

        return back()->withSuccess('Listo. Se marcó para ejecutar en el próximo ciclo del daemon.');
    }

    private function validarPermisoDominio($usuario, $dominio): void
    {
        // Misma idea que tu Generador(): dependiente solo en asignados; titular en asignados o creador

        $titular = $usuario->titularLicencia();
        if (!$titular) abort(403);

        $dominiosAsignados = DB::table('dominios_usuarios')
            ->where('id_usuario', (int) $usuario->id)
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
