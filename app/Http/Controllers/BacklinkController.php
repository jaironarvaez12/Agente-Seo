<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LicenciaDominiosActivacionModel;
use App\Models\SeoReport;
use App\Models\User;
use App\Models\DominiosModel;
use App\Models\Dominios_UsuariosModel;
use App\Models\Dominios_Contenido_DetallesModel;
use Exception;
use Carbon\Carbon;
class BacklinkController extends Controller
{
    public function index()
    {
        $usuario = auth()->user();

        // Titular real
        $idTitular = $usuario->id_usuario_padre ?? $usuario->id;
        $titular = $usuario->id_usuario_padre ? User::find($idTitular) : $usuario;

        // ---------------- DOMINIOS ----------------
        if ($usuario->hasRole('administrador')) {
            $dominios = DominiosModel::all();
        } else {

            // Dominios asignados AL USUARIO LOGUEADO (si es dependiente, solo los suyos)
            $idsAsignados = Dominios_UsuariosModel::where('id_usuario', $usuario->id)
                ->pluck('id_dominio');

            $consulta = DominiosModel::whereIn('id_dominio', $idsAsignados);

            // Si es titular, también ve los creados por él
            if (is_null($usuario->id_usuario_padre)) {
                $consulta->orWhere('creado_por', $idTitular);
            }

            $dominios = $consulta->get();
        }

        // ---------------- LICENCIA ----------------
        $plan = 'pro';
        $maximo = (int) config("licenses.max_by_plan.$plan", 0);

        $usados = 0;
        $restantes = 0;

        if ($titular && $titular->license_key) {
            $licenciaPlano = $titular->getLicenseKeyPlain();

            // Importante: contar por el titular, NO por el dependiente
            $usados = (int) LicenciaDominiosActivacionModel::where('user_id', $titular->id)
                ->where('license_key', sha1($licenciaPlano))
                ->where('estatus', 'activo')
                ->count();

            $restantes = max(0, $maximo - $usados);
        }

        return view('Backlinks.Dominio', compact('dominios', 'plan', 'maximo', 'usados', 'restantes'));
    }


    public function Ver(Request $request, $IdDominio)
    {
       
       

        $contenidos = Dominios_Contenido_DetallesModel::with(['backlinksRuns' => function ($q) {
            $q->orderByDesc('created_at');
        }])
        ->where('id_dominio', $IdDominio)
        ->where('estatus', 'publicado')
        ->orderByDesc('id_dominio_contenido_detalle')
        ->get();
      
       

        return view('Backlinks.ContenidoPublicado', compact('contenidos'));
    }

    public function VerBacklinksGenerados(Request $request, int $IdDominio)
    {
        $contenidos = Dominios_Contenido_DetallesModel::with(['backlinksRuns' => function ($q) {
                $q->orderByDesc('created_at'); // historial, más nuevo primero
            }])
            ->where('id_dominio', $IdDominio)
            ->where('estatus', 'publicado')
            ->orderByDesc('id_dominio_contenido_detalle')
            ->get();

        $backlinks = [];

        foreach ($contenidos as $c) {

            // ✅ recorrer TODAS las corridas (no solo el último resultado)
            foreach (($c->backlinksRuns ?? collect()) as $run) {

                $res = $run->respuesta;
                if (empty($res)) continue;

                if (!is_array($res)) {
                    $res = json_decode($res, true);
                }
                if (!is_array($res)) continue;

                $r0 = $res['data']['results'][0] ?? null;
                if (!$r0) continue;

                // publicados
                foreach (($r0['published_urls'] ?? []) as $p) {
                    $backlinks[] = (object) [
                        'id_dominio_contenido_detalle' => $c->id_dominio_contenido_detalle,
                        'plataforma' => $p['platform'] ?? '—',
                        'url' => $p['url'] ?? '—',
                        'estatus' => $p['status'] ?? 'published',
                        'error' => null,

                        // info extra útil
                        'title' => $c->title,
                        'run_id' => $run->id_backlink_run ?? null,
                        'run_estatus' => $run->estatus,
                        'fecha' => $run->created_at ?? $c->fecha_backlinks ?? $c->updated_at,
                    ];
                }

                // fallidos
                foreach (($r0['failed_platforms'] ?? []) as $f) {
                    $backlinks[] = (object) [
                        'id_dominio_contenido_detalle' => $c->id_dominio_contenido_detalle,
                        'plataforma' => $f['platform'] ?? '—',
                        'url' => '—',
                        'estatus' => $f['status'] ?? 'failed',
                        'error' => $f['error'] ?? null,

                        'title' => $c->title,
                        'run_id' => $run->id_backlink_run ?? null,
                        'run_estatus' => $run->estatus,
                        'fecha' => $run->created_at ?? $c->fecha_backlinks ?? $c->updated_at,
                    ];
                }
            }
        }

        // ordenar por fecha desc
        usort($backlinks, function ($a, $b) {
            // 1) Fecha DESC (más nuevo primero)
            $ta = strtotime((string)($a->fecha ?? '1970-01-01'));
            $tb = strtotime((string)($b->fecha ?? '1970-01-01'));
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }

            // 2) Estatus: published primero, luego failed/otros
            $rank = function ($s) {
                $s = strtolower((string)$s);
                return $s === 'published' ? 0 : 1;
            };

            return $rank($a->estatus ?? '') <=> $rank($b->estatus ?? '');
        });

        return view('Backlinks.BacklinksPublicados', compact('backlinks', 'IdDominio'));
    }   
}
