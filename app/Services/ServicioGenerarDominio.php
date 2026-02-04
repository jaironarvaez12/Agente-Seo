<?php

namespace App\Services;

use App\Jobs\GenerarContenidoKeywordJob;
use App\Models\DominiosModel;
use App\Models\Dominios_ContenidoModel;
use App\Models\Dominios_Contenido_DetallesModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ServicioGenerarDominio
{
    public function __construct(private LicenseService $servicioLicencias) {}

    private function obtenerHostDesdeUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = preg_replace('/^www\./i', '', $host);
        return strtolower(trim($host));
    }

    /**
     * Crea tareas "encolado" y devuelve la lista de trabajos para despachar.
     * - $actor: usuario que ejecuta (manual: auth(), automático: creado_por)
     * - $maxTareas: límite adicional para automático (sin saltarse licencias)
     */
    public function iniciarGeneracion(int $idDominio, $actor, ?int $maxTareas = null): array
    {
         if (!$actor) return [false, 'Debes iniciar sesión.', []];

    // ✅ ADMIN por ROL (Spatie)
        $esAdmin = $actor->hasRole('administrador'); // ajusta el nombre exacto: 'Administrador', 'superadmin', etc.

        $titular = null;
        $licenciaPlano = null;
        $emailLicencia = null;

        // Solo pedir licencia si NO es admin
        if (!$esAdmin) {
            $titular = $actor->titularLicencia();
            if (!$titular) return [false, 'No se encontró el titular de la licencia.', []];

            $licenciaPlano = $titular->getLicenseKeyPlain();
            if (!$licenciaPlano) return [false, 'El titular no tiene licencia registrada.', []];

            $emailLicencia = $titular->license_email ?? $titular->email;
        }
        try {
            [$ok, $mensaje, $trabajosParaDespachar] = DB::transaction(function () use (
                $idDominio, $actor, $titular, $licenciaPlano, $emailLicencia, $maxTareas, $esAdmin
            ) {
                $dominio = DominiosModel::where('id_dominio', (int)$idDominio)
                    ->lockForUpdate()
                    ->first();

                if (!$dominio) return [false, 'Dominio no encontrado.', []];

                // =========================
                // ✅ LICENCIA / CUPOS
                // =========================
                if ($esAdmin) {
                    $plan = 'admin';
                    $desde = now()->subYears(50);
                    $hasta = null;
                    $maxContenido = PHP_INT_MAX;
                    $maxDominiosActivos = PHP_INT_MAX;
                } else {
                    $host = $this->obtenerHostDesdeUrl($dominio->url);

                    $respPlan = $this->servicioLicencias->getPlanLimitsAuto($licenciaPlano, $host, $emailLicencia);
                    $plan = (string)($respPlan['plan'] ?? 'free');
                    $limites = $this->servicioLicencias->normalizeLimits($plan, (array)($respPlan['limits'] ?? []));

                    $maxContenido = (int)($limites['max_content'] ?? 0);
                    if ($maxContenido <= 0) {
                        return [false, "Tu plan ($plan) no permite generar contenido o el dominio no está activado.", []];
                    }

                    [$desde, $hasta, $infoVentana] = $this->servicioLicencias->licenseUsageRange($respPlan);
                    if (!$infoVentana['is_active']) {
                        $finTxt = $infoVentana['end']
                            ? $infoVentana['end']->setTimezone(config('app.timezone'))->format('d/m/Y h:i A')
                            : 'N/D';
                        return [false, "Tu licencia no está activa o está vencida. Expira: {$finTxt}.", []];
                    }

                    $maxDominiosActivos = (int)($limites['max_activations'] ?? 0);
                    if ($maxDominiosActivos <= 0) {
                        return [false, "Tu plan ($plan) no permite activar dominios (max_activations inválido).", []];
                    }
                }

                // =========================================================
                // 1) CUPO POR DOMINIO (solo NO admin)
                // =========================================================
                if (!$esAdmin) {
                    $ocupadosDominio = (int) Dominios_Contenido_DetallesModel::where('id_dominio', (int)$idDominio)
                        ->whereIn('tipo', ['post','page'])
                        ->where('created_at', '>=', $desde)
                        ->when($hasta, fn($q) => $q->where('created_at', '<', $hasta))
                        ->whereIn('estatus', ['encolado','en_proceso','generado','programado','publicado'])
                        ->count();

                    if ($ocupadosDominio >= $maxContenido) {
                        $tz = config('app.timezone');
                        $desdeTxt = $desde->copy()->setTimezone($tz)->format('d/m/Y h:i A');
                        $hastaTxt = $hasta ? $hasta->copy()->setTimezone($tz)->format('d/m/Y h:i A') : 'N/D';
                        return [false, "Límite por dominio alcanzado: $ocupadosDominio / $maxContenido. Vigencia: $desdeTxt → $hastaTxt (plan $plan).", []];
                    }

                    $restanteDominio = $maxContenido - $ocupadosDominio;

                    // =========================================================
                    // 2) CUPO GLOBAL (solo NO admin)
                    // =========================================================
                    $maxGlobal = $maxDominiosActivos * $maxContenido;

                    $dominiosDelTitular = DB::table('dominios_usuarios')
                        ->where('id_usuario', (int)$titular->id)
                        ->pluck('id_dominio')
                        ->map(fn ($v) => (int)$v)
                        ->all();

                    $ocupadosGlobal = (int) Dominios_Contenido_DetallesModel::whereIn('id_dominio', $dominiosDelTitular)
                        ->whereIn('tipo', ['post','page'])
                        ->where('created_at', '>=', $desde)
                        ->when($hasta, fn($q) => $q->where('created_at', '<', $hasta))
                        ->whereIn('estatus', ['encolado','en_proceso','generado','programado','publicado'])
                        ->count();

                    if ($ocupadosGlobal >= $maxGlobal) {
                        return [false, "Límite GLOBAL alcanzado: $ocupadosGlobal / $maxGlobal. (plan $plan).", []];
                    }

                    $restanteGlobal = $maxGlobal - $ocupadosGlobal;

                    $restante = min($restanteDominio, $restanteGlobal);
                } else {
                    $restante = PHP_INT_MAX; // admin sin cupo
                }

                if ($maxTareas !== null && $maxTareas > 0) {
                    $restante = min($restante, $maxTareas);
                }


                // =========================================================
                // 3) CONFIGURACIONES
                // =========================================================
                $configs = Dominios_ContenidoModel::select('id_dominio_contenido','tipo','palabras_claves')
                    ->where('id_dominio', (int)$idDominio)
                    ->orderByDesc('id_dominio_contenido')
                    ->get();

                if ($configs->isEmpty()) {
                    return [false, 'No hay configuración para este dominio.', []];
                }

                $configs = $configs->map(function ($c) {
                    $tipoRaw = strtolower(trim((string)$c->tipo));
                    $tipo = match ($tipoRaw) {
                        'post', 'posts' => 'post',
                        'page', 'pagina', 'página', 'paginas', 'páginas' => 'page',
                        default => null,
                    };
                    $c->tipo_normalizado = $tipo;
                    return $c;
                })->filter(fn($c) => in_array($c->tipo_normalizado, ['post','page'], true));

                if ($configs->isEmpty()) {
                    return [false, 'No hay configuraciones válidas (post/page) para este dominio.', []];
                }

                // =========================================================
                // 4) CONSTRUIR TAREAS
                // =========================================================
               $tareas = [];

                foreach ($configs as $config) {
                    $tipo = $config->tipo_normalizado;

                    $raw = (string)$config->palabras_claves;
                    $palabras = json_decode($raw, true);

                    if (!is_array($palabras)) {
                        $palabras = array_values(array_filter(array_map('trim', explode(',', $raw))));
                    }

                    if (!$palabras) continue;

                    foreach ($palabras as $kw) {
                        $kw = trim((string)$kw);
                        if ($kw === '') continue;

                        $tareas[] = [
                            'id_dominio_contenido' => (int)$config->id_dominio_contenido,
                            'tipo' => (string)$tipo,
                            'keyword' => $kw,
                        ];

                        // ✅ Si NO es admin, parar cuando se complete el cupo ($restante)
                        if ($restante !== PHP_INT_MAX && count($tareas) >= $restante) {
                            break 2; // sale del foreach de palabras y del foreach de configs
                        }
                    }
                }

                if (!$tareas) return [false, 'No hay palabras clave válidas para generar contenido.', []];

                // cortar por restante
                $tareas = array_slice($tareas, 0, $restante);

                // =========================================================
                // 5) CREAR REGISTROS + LISTA DE JOBS
                // =========================================================
                $jobs = [];

                foreach ($tareas as $t) {
                    $uuidJob = (string) Str::uuid();

                    $detalle = Dominios_Contenido_DetallesModel::create([
                        'job_uuid'             => $uuidJob,
                        'id_dominio_contenido' => (int) $t['id_dominio_contenido'],
                        'id_dominio'           => (int) $idDominio,
                        'tipo'                 => (string) $t['tipo'],
                        'keyword'              => (string) $t['keyword'],
                        'estatus'              => 'encolado',
                        'modelo'               => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                    ]);

                    $jobs[] = [
                        'idDominio' => (int)$idDominio,
                        'idDominioContenido' => (int)$t['id_dominio_contenido'],
                        'tipo' => (string)$t['tipo'],
                        'keyword' => (string)$t['keyword'],
                        'detalleId' => (int)$detalle->id_dominio_contenido_detalle,
                        'uuidJob' => (string)$uuidJob,
                    ];
                }

                $cantidad = count($jobs);
                $mensaje = "Generación iniciada. Se enviaron $cantidad tareas. (plan $plan)";

                return [true, $mensaje, $jobs];
            });

            return [$ok, $mensaje, $trabajosParaDespachar];

        } catch (\Throwable $e) {
            return [false, 'Error al iniciar generación: ' . $e->getMessage(), []];
        }
    }

    public function despacharJobs(array $jobs): void
    {
        foreach ($jobs as $j) {
            try {
                GenerarContenidoKeywordJob::dispatch(
                    $j['idDominio'],
                    $j['idDominioContenido'],
                    $j['tipo'],
                    $j['keyword'],
                    $j['detalleId'],
                    $j['uuidJob'],
                )->onConnection('database')->onQueue('default');
            } catch (\Throwable $e) {
                Dominios_Contenido_DetallesModel::where('id_dominio_contenido_detalle', (int)$j['detalleId'])
                    ->update([
                        'estatus' => 'error_final',
                        'error' => 'Falló el dispatch: ' . $e->getMessage(),
                    ]);
            }
        }
    }
}
