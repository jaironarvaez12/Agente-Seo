<?php

namespace App\Http\Controllers;

use App\Models\Dominios_ContenidoModel;
use App\Models\Dominios_Contenido_DetallesModel;
use App\Models\DominiosModel;
use App\Models\Dominios_UsuariosModel;
use App\Models\LicenciaDominiosActivacionModel;
use App\Models\SeoReport;
use Illuminate\Http\Request;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Services\WordpressService;
use Illuminate\Support\Facades\Http;
use App\Jobs\GenerarContenidoKeywordJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Intervention\Image\Laravel\Facades\Image; 
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Services\LicenseService;
class DominiosController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    private function hostFromUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        // fallback
        return $host ?: rtrim(preg_replace('#^https?://#i', '', $url), '/');
    }
    private function countQueuedJobsForDomain(int $idDominio, \Carbon\Carbon $desde): int
    {
        if (!DB::getSchemaBuilder()->hasTable('jobs')) {
            return 0;
        }

        $rows = DB::table('jobs')
            ->select('payload', 'created_at')
            ->where('created_at', '>=', $desde->timestamp)
            // ✅ filtra rápido por nombre del job (esto SÍ está en texto plano)
            ->where('payload', 'like', '%GenerarContenidoKeywordJob%')
            ->get();

        $count = 0;

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            if (!is_array($payload)) continue;

            $commandB64 = data_get($payload, 'data.command');
            if (!is_string($commandB64) || $commandB64 === '') continue;

            try {
                $serialized = base64_decode($commandB64, true);
                if ($serialized === false) continue;

                $job = @unserialize($serialized);
                if (!is_object($job)) continue;

                // ✅ Asegura que sea TU job
                if (!$job instanceof \App\Jobs\GenerarContenidoKeywordJob) continue;

                // ✅ Ahora sí: leer idDominio real del job
                $jobDomainId = (int) ($job->idDominio ?? 0);

                if ($jobDomainId === $idDominio) {
                    $count++;
                }
            } catch (\Throwable $e) {
                // si algún payload está raro, lo ignoramos
                continue;
            }
        }

        return $count;
    }
    public function index()
{
    $userId = Auth::id();

    if (Auth::user()->roles[0]->name == 'administrador') {
        $dominios = DominiosModel::all();
    } else {

        // IDs de dominios asignados al usuario (tabla pivote)
        $idsAsignados = Dominios_UsuariosModel::where('id_usuario', $userId)
            ->pluck('id_dominio');

        // Dominios que (a) están asignados al usuario o (b) fueron creados por él
        $dominios = DominiosModel::whereIn('id_dominio', $idsAsignados)
            ->orWhere('creado_por', $userId)
            ->get();
    }

    // --- lo tuyo de licencias igual ---
    $user = auth()->user();

    $plan = 'pro';
    $max = (int) config("licenses.max_by_plan.$plan", 0);

    $used = 0;
    $remaining = 0;

    if ($user && $user->license_key) {
        $licensePlain = $user->getLicenseKeyPlain();

        $used = (int) LicenciaDominiosActivacionModel::where('user_id', $user->id)
            ->where('license_key', sha1($licensePlain))
            ->where('estatus', 'activo')
            ->count();

        $remaining = max(0, $max - $used);
    }

    return view('Dominios.Dominio', compact('dominios', 'plan', 'max', 'used', 'remaining'));
}


    /**
     * Show the form for creating a new resource.
     */
   public function create()
    {
        $user = auth()->user();

        $plan = 'pro'; // por ahora fijo. Luego lo guardamos en users (license_plan)
        $max = (int) config("licenses.max_by_plan.$plan", 0);

        $used = 0;
        $remaining = 0;

        if ($user && $user->license_key) {
            $licensePlain = $user->getLicenseKeyPlain();

            $used = (int) LicenciaDominiosActivacionModel::where('user_id', $user->id)
                ->where('license_key', sha1($licensePlain))
                ->where('estatus', 'activo')
                ->count();

            $remaining = max(0, $max - $used);
        }

        return view('Dominios.DominioCreate', compact('plan', 'max', 'used', 'remaining'));
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, LicenseService $licenses)
{
    $user = auth()->user();
    if (!$user) {
        return back()->withError('Debes iniciar sesión.')->withInput();
    }

    $licensePlain = $user->getLicenseKeyPlain();
    if (!$licensePlain) {
        return back()->withError('Tu usuario no tiene licencia registrada.')->withInput();
    }

    // Plan y máximo (local)
    $plan = 'pro';
    $max = (int) config("licenses.max_by_plan.$plan", 0);

    // Conteo local (sirve para UI, pero NO es fuente de verdad)
    $usedLocal = (int) LicenciaDominiosActivacionModel::where('user_id', $user->id)
        ->where('license_key', sha1($licensePlain))
        ->where('estatus', 'activo')
        ->count();

    $remainingLocal = max(0, $max - $usedLocal);

    if ($max > 0 && $remainingLocal <= 0) {
        return back()->withError("Ya alcanzaste el límite de tu plan ($plan): máximo $max dominios activos.")->withInput();
    }

    // Normalizar host del dominio a activar
    $host = $this->hostFromUrl($request->input('url'));

    // 🔥 PROBE (FUENTE DE VERDAD): verificar cupo real en el servidor
    // Si esto falla, no creamos el dominio ni tocamos BD.
    $email = $user->license_email ?? $user->email;
    $probe = 'probe-' . substr(sha1(uniqid('', true)), 0, 10) . '.ideiweb.com';

    try {
        $probeResp = $licenses->activate($licensePlain, $probe, $email);

        if (!data_get($probeResp, 'activated')) {
            $msg = data_get($probeResp, 'message', 'No hay cupo disponible.');
            return back()->withError("No tienes activaciones disponibles en el servidor de licencias. ($msg)")->withInput();
        }

        // Limpieza: desactivar probe para no consumir slot
        $licenses->deactivate($licensePlain, $probe);

    } catch (\Throwable $e) {
        return back()->withError('No se pudo verificar cupo de activaciones: ' . $e->getMessage())->withInput();
    }

    // Crear dominio + activar y registrar
    $IdDominio = (int) DominiosModel::max('id_dominio') + 1;

    try {
        DB::transaction(function () use ($request, $IdDominio, $licenses, $user, $licensePlain, $host, $email) {

            DominiosModel::create([
                'id_dominio' => $IdDominio,
                'url' => $request['url'],
                'nombre' => strtoupper($request['nombre']),
                'estatus' => strtoupper('SI'),
         
                'creado_por' => Auth::user()->id,
            ]);

            $resp = $licenses->activarYRegistrar(
                $user->id,
                $licensePlain,
                $host,
                $email
            );

            if (!data_get($resp, 'activated')) {
                throw new \Exception(data_get($resp, 'message', 'No se pudo activar la licencia para este dominio.'));
            }
        });

    } catch (\Throwable $ex) {
        return back()->withError('Ocurrió un error al crear/activar el dominio: ' . $ex->getMessage())->withInput();
    }

    return redirect("dominios")->withSuccess('El Dominio se ha creado y activado exitosamente');
}

    /**
     * Display the specified resource.
     */
    public function show(string $IdDominio)
    {
           $dominio = DominiosModel::find($IdDominio);
       $generadores=Dominios_ContenidoModel::all()->where('id_dominio','=',$IdDominio);
       return view('Dominios.DominioShow',compact('dominio','generadores'));
    }

    /**
     * Show the form for editing the specified resource.
     */
 public function edit($id)
{
    $dominio = DominiosModel::findOrFail($id);

    $wpBase  = env('TESTINGSEO_WP_URL', 'https://testingseo.entornodedesarrollo.es');
    $secret  = env('TSEO_TPL_SECRET');

    $plantillas = [];

    try {
        $ts  = time();
        $sig = hash_hmac('sha256', $ts.'.templates', (string)$secret);

        $res = Http::acceptJson()
            ->withOptions(['verify' => false])
            ->timeout(15)
            ->get($wpBase.'/', [
                'tseo_templates' => 1,
                'ts' => $ts,
                'sig' => $sig,
            ]);

        // 👇 DEBUG CLAVE
        // dd([
        //     'url' => $res->effectiveUri() ?? null,
        //     'status' => $res->status(),
        //     'content_type' => $res->header('content-type'),
        //     'body_snippet' => Str::limit($res->body(), 500),
        //     'json' => $res->json(),
        // ]);

        if ($res->ok() && ($res->json('ok') === true)) {
            $plantillas = $res->json('items') ?? [];
        }
    } catch (\Throwable $e) {
        dd(['exception' => $e->getMessage()]);
    }
     return view('Dominios.DominioEdit', compact('dominio', 'plantillas'));
    }




    /**
     * Update the specified resource in storage.
     */
   public function update(Request $request, string $id_dominio)
    {
         if ($request->hasFile('imagen'))
        {   
            $PosseDocumentos = 'SI';
        }
        else
        {
            $PosseDocumentos = 'NO';
            }
        try 
         {
         $destino = "images/dominios/dominio/";
             $NombreCarpeta = $id_dominio;
             
             $Ruta = public_path($destino . $NombreCarpeta);

             if (!File::exists($Ruta)) 
             {
                 File::makeDirectory($Ruta, 0777, true);
                 echo "La carpeta se ha creado correctamente.";
             } 

             //prueba
             if ($PosseDocumentos === 'SI') {
                $archivo      = $request->file('imagen');      // name="imagen"
                $NombreImagen = $id_dominio . '.jpg';

                // Mover al destino final
                $archivo->move($Ruta, $NombreImagen);

                // Redimensionar y sobrescribir (400x400, JPG calidad 85)
                $RutaImagen = $Ruta . DIRECTORY_SEPARATOR . $NombreImagen;
                $img   = Image::read($RutaImagen)->cover(400, 400);
                $bytes = $img->encodeByExtension('jpg', quality: 85);
                File::put($RutaImagen, $bytes);
            }
 
         } 
         catch (Exception $ex) 
         {
             return back()->withError('Ocurrio Un Error al Cargar La Fotografia: ' . $ex->getMessage())->withInput();
         }


        try {
            $dominios = DominiosModel::findOrFail($id_dominio);

            // (Opcional pero recomendado)
            $request->validate([
                'usuario' => ['nullable','string','max:255'],
                'password' => ['nullable','string','max:255'],
                'elementor_template_path' => ['nullable','string','max:255'],
            ]);

            $dominios->fill([
                'usuario' => $request->input('usuario'),
                'elementor_template_path' => $request->input('elementor_template_path'), // 👈 NUEVO
            ]);

             // solo si SÍ posee documentos (y tienes el nombre de la imagen) seteas la imagen
            if ($PosseDocumentos === 'SI' && !empty($NombreImagen)) {
                $dominios->imagen = $destino . $NombreCarpeta . '/' . $NombreImagen;
            }

            if ($request->filled('password')) {
                $dominios->password = Crypt::encryptString($request->input('password'));
            }

            $dominios->save();

        } catch (\Throwable $ex) {
            return redirect()->back()
                ->withError('Ha Ocurrido Un Error Al Actualizar El Dominio ' . $ex->getMessage())
                ->withInput();
        }

        return redirect()->route('dominios.edit', $id_dominio)->withSuccess('El Dominio Se Ha Actualizado Exitosamente');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
    public function Crearcontenido(string $IdDominio)
    {
        // Tipos permitidos
        $tiposPermitidos = ['POST', 'PAGINAS'];

        // Tipos que ya existen para ese dominio
        $tiposExistentes = Dominios_ContenidoModel::where('id_dominio', $IdDominio)
            ->pluck('tipo')            // trae solo la columna tipo
            ->map(fn($t) => strtoupper(trim($t)))
            ->toArray();

        // Tipos faltantes (los que NO tiene aún)
        $tiposDisponibles = array_values(array_diff($tiposPermitidos, $tiposExistentes));

        return view('Dominios.DominioCrearContenido', compact('IdDominio', 'tiposDisponibles'));
    }
    
    public function GeneradorContenido(Request $request,string  $IdDominio)
    {
        $palabras = json_decode($request['palabras_clave']); // ["seo","paginas"]
        if($palabras  == NULL) //Valida que el arreglo de las herramientas no este vacio
        {
            return back()->withErrors(['palabras_clave'=> 'Para  crea un tipo de generador debe seleccionar una o varias palabras clave'])->withInput();
        }
        $tipo=$request['tipo'];
        if($tipo  == 0) //Valida que el arreglo de las herramientas no este vacio
        {
            return back()->withErrors(['Este Dominio ya tiene ambos tipos de generadores de contenido'])->withInput();
        }
        $palabras_clave_cadena = implode(',', $palabras);   // "seo, paginas"
 
         try 
         {
           
                $IdDominioContenido= Dominios_ContenidoModel::max('id_dominio_contenido')+1;
                Dominios_ContenidoModel::create([
                    'id_dominio_contenido' =>    $IdDominioContenido,
                    'id_dominio' =>    $IdDominio,
                    'tipo' =>    $tipo,
                    'palabras_claves' => $palabras_clave_cadena,
                    'estatus' =>strtoupper('SI'),
                  
                ]);
            
 
         } 
         catch (Exception $ex) 
         {
             return back()->withError('Ocurrio Un Error al Crear el Generador de Contenido ' . $ex->getMessage())->withInput();
         }
          return redirect("dominios")->withSuccess('El Generador Contenido Se Ha Creado Exitosamente');


        
    }
public function verWp($id, WordpressService $wp)
{
    $dominio = DominiosModel::findOrFail($id);

    /**
     * ✅ CAMBIO PRINCIPAL:
     * - Ya NO usamos $dominio->wp_site_key
     * - Calculamos siteKey = md5(url_wp)
     * - Permitimos override ?site_key=... para debug
     */

    // 🔧 AJUSTA ESTE CAMPO al nombre real de tu columna con la URL del WP:
    $wpUrl = rtrim((string)($dominio->url ?? ''), '/'); // <-- CAMBIAR SI ES OTRO

    // Override por querystring para debug
    $override = (string) request()->query('site_key', '');
    $siteKey = $override !== '' ? $override : '';

    // Si no hay override, calculamos desde la URL guardada
    if ($siteKey === '') {
        if ($wpUrl === '') {
            return back()->with('error', 'Este dominio no tiene URL de WordPress guardada. Guarda la URL del sitio WP y vuelve a intentar.');
        }
        $siteKey = md5($wpUrl);
    }

    // Keys
    $kPosts  = "inv:{$siteKey}:post";
    $kPages  = "inv:{$siteKey}:page";
    $kMetaP  = "inv_meta:{$siteKey}:post";
    $kMetaPg = "inv_meta:{$siteKey}:page";
    $kCntP   = "inv_counts:{$siteKey}:post";
    $kCntPg  = "inv_counts:{$siteKey}:page";

    // Raw snapshots
    $postsRaw = Cache::get($kPosts, []);
    $pagesRaw = Cache::get($kPages, []);

    $postsRaw = is_array($postsRaw) ? $postsRaw : [];
    $pagesRaw = is_array($pagesRaw) ? $pagesRaw : [];

    // Meta
    $metaPosts = Cache::get($kMetaP, []);
    $metaPages = Cache::get($kMetaPg, []);

    $metaPosts = is_array($metaPosts) ? $metaPosts : [];
    $metaPages = is_array($metaPages) ? $metaPages : [];

    // Counts
    $countPosts = Cache::get($kCntP, []);
    $countPages = Cache::get($kCntPg, []);

    $countPosts = is_array($countPosts) ? $countPosts : [];
    $countPages = is_array($countPages) ? $countPages : [];

    foreach (['publish','draft','future','pending','private'] as $st) {
        $countPosts[$st] = (int)($countPosts[$st] ?? 0);
        $countPages[$st] = (int)($countPages[$st] ?? 0);
    }

    // Sync meta
    $syncPosts = [
        'has_data'    => !empty($postsRaw),
        'complete'    => (bool)($metaPosts['is_complete'] ?? false),
        'updated_at'  => $metaPosts['updated_at'] ?? null,
        'run_id'      => $metaPosts['run_id'] ?? null,
    ];

    $syncPages = [
        'has_data'    => !empty($pagesRaw),
        'complete'    => (bool)($metaPages['is_complete'] ?? false),
        'updated_at'  => $metaPages['updated_at'] ?? null,
        'run_id'      => $metaPages['run_id'] ?? null,
    ];

    // Ordenar por modified desc
    usort($postsRaw, fn($a, $b) => strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? '')));
    usort($pagesRaw, fn($a, $b) => strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? '')));

    // Mapear a formato Blade
    $posts = array_map(function ($x) {
        $title = $x['title'] ?? 'Sin título';
        return [
            'id'       => $x['wp_id'] ?? null,
            'slug'     => $x['slug'] ?? null,
            'status'   => $x['status'] ?? null,
            'date'     => $x['date'] ?? null,
            'link'     => $x['link'] ?? null,
            'title'    => ['rendered' => $title],
            'modified' => $x['modified'] ?? null,
        ];
    }, $postsRaw);

    $pages = array_map(function ($x) {
        $title = $x['title'] ?? 'Sin título';
        return [
            'id'       => $x['wp_id'] ?? null,
            'slug'     => $x['slug'] ?? null,
            'status'   => $x['status'] ?? null,
            'date'     => $x['date'] ?? null,
            'link'     => $x['link'] ?? null,
            'title'    => ['rendered' => $title],
            'modified' => $x['modified'] ?? null,
        ];
    }, $pagesRaw);

    // Log útil para debug
    Log::info('verWp debug', [
        'dominio_id' => $dominio->id_dominio ?? $dominio->id ?? null,
        'wpUrl_db'   => $wpUrl,
        'siteKey_used' => $siteKey,
        'cache_keys' => [$kPosts, $kPages, $kMetaP, $kMetaPg, $kCntP, $kCntPg],
        'counts' => [
            'posts_raw' => count($postsRaw),
            'pages_raw' => count($pagesRaw),
        ],
    ]);

    // Debug visible: /dominios/2/wp?debug=1
    if (request()->boolean('debug')) {
        dd([
            'wpUrl_db' => $wpUrl,
            'siteKey_used' => $siteKey,
            'keys' => [
                'posts' => $kPosts,
                'pages' => $kPages,
                'meta_posts' => $kMetaP,
                'meta_pages' => $kMetaPg,
                'count_posts' => $kCntP,
                'count_pages' => $kCntPg,
            ],
            'counts' => [
                'posts_raw' => count($postsRaw),
                'pages_raw' => count($pagesRaw),
            ],
            'meta_posts' => $metaPosts,
            'meta_pages' => $metaPages,
            'countPosts' => $countPosts,
            'countPages' => $countPages,
            'sample_post' => $postsRaw[0] ?? null,
            'sample_page' => $pagesRaw[0] ?? null,
        ]);
    }

    $perPagePosts = 50; $perPagePages = 50; $pagePosts = 1; $pagePages = 1;

    return view('Dominios.DominioContenido', compact(
        'dominio',
        'posts',
        'pages',
        'countPosts',
        'countPages',
        'syncPosts',
        'syncPages',
        'perPagePosts',
        'perPagePages',
        'pagePosts',
        'pagePages'
    ));
}









    public function Generador(string $IdDominio, LicenseService $licenses)
{
    $user = auth()->user();
    if (!$user) return back()->withError('Debes iniciar sesión.');

    $licensePlain = $user->getLicenseKeyPlain();
    if (!$licensePlain) return back()->withError('No tienes licencia registrada.');

    $jobsToDispatch = [];
    $msg = null;
    $ok = false;

    try {
        [$ok, $msg, $jobsToDispatch] = DB::transaction(function () use ($IdDominio, $licenses, $licensePlain, $user) {

            // ✅ Lock de dominio para evitar doble click simultáneo
            $dominio = DominiosModel::where('id_dominio', (int)$IdDominio)
                ->lockForUpdate()
                ->first();

            if (!$dominio) {
                return [false, 'Dominio no encontrado.', []];
            }

            $host = $this->hostFromUrl($dominio->url);

            // ✅ límites API (fresh)
            $planResp = $licenses->getPlanLimitsAuto($licensePlain, $host, $user->email);

            $plan   = (string) ($planResp['plan'] ?? 'free');
            $limits = $licenses->normalizeLimits($plan, (array) ($planResp['limits'] ?? []));

            $maxContent = (int) ($limits['max_content'] ?? 0);
            if ($maxContent <= 0) {
                return [false, "Tu plan ($plan) no permite generar contenido o el dominio no está activado.", []];
            }

            // ✅ rango real de vigencia (validity_start / validity_end)
           [$desde, $hasta, $w] = $licenses->licenseUsageRange($planResp);

            if (!$w['is_active']) {
                $endTxt = $w['end'] ? $w['end']->setTimezone(config('app.timezone'))->format('d/m/Y h:i A') : 'N/D';
                return [false, "Tu licencia no está activa o está vencida. Expira: {$endTxt}.", []];
            }

            // ✅ max dominios activos (tu API lo llama max_activations)
            $maxActiveDomains = (int) ($limits['max_activations'] ?? 0);
            if ($maxActiveDomains <= 0) {
                return [false, "Tu plan ($plan) no permite activar dominios (max_activations inválido).", []];
            }

            // =========================================================
            // 1) CUPO POR DOMINIO
            // =========================================================
            $ocupadosDominio = (int) Dominios_Contenido_DetallesModel::where('id_dominio', (int)$IdDominio)
                ->whereIn('tipo', ['post','page'])
                ->where('created_at', '>=', $desde)
                ->when($hasta, fn($q) => $q->where('created_at', '<', $hasta))
                ->whereIn('estatus', ['encolado','en_proceso','generado'])
                ->count();

            if ($ocupadosDominio >= $maxContent) {
                $tz = config('app.timezone');

                $dTxt = $desde->copy()->setTimezone($tz)->format('d/m/Y h:i A');
                $hTxt = $hasta ? $hasta->copy()->setTimezone($tz)->format('d/m/Y h:i A') : 'N/D';

                return [false, "Límite por dominio alcanzado: $ocupadosDominio / $maxContent. Valides: $dTxt → $hTxt (plan $plan).", []];
            }

            $remainingDominio = $maxContent - $ocupadosDominio;

            // =========================================================
            // 2) CUPO GLOBAL = max_activations * max_content
            // =========================================================
            $maxGlobal = $maxActiveDomains * $maxContent;

            $dominiosIdsDelUser = DB::table('dominios_usuarios')
                ->where('id_usuario', (int) $user->id)
                ->pluck('id_dominio')
                ->map(fn ($v) => (int) $v)
                ->all();

            $esCreadorDelDominio = DB::table('dominios')
                ->where('id_dominio', (int) $IdDominio)
                ->where('creado_por', (int) $user->id) // <-- ajusta si tu columna se llama distinto
                ->exists();

            if (!in_array((int) $IdDominio, $dominiosIdsDelUser, true) && !$esCreadorDelDominio) {
                return [false, 'No tienes permiso para generar contenido en este dominio.', []];
            }

            $ocupadosGlobal = (int) Dominios_Contenido_DetallesModel::whereIn('id_dominio', $dominiosIdsDelUser)
                ->whereIn('tipo', ['post','page'])
                ->where('created_at', '>=', $desde)
                ->when($hasta, fn($q) => $q->where('created_at', '<', $hasta))
                ->whereIn('estatus', ['encolado','en_proceso','generado'])
                ->count();

            if ($ocupadosGlobal >= $maxGlobal) {
                $dTxt = $desde->toDateTimeString();
                $hTxt = $hasta ? $hasta->toDateTimeString() : 'N/D';
                return [false, "Límite GLOBAL alcanzado: $ocupadosGlobal / $maxGlobal (= $maxActiveDomains dominios x $maxContent). Ventana: $dTxt → $hTxt (plan $plan).", []];
            }

            $remainingGlobal = $maxGlobal - $ocupadosGlobal;

            // ✅ remaining final: respeta ambos límites
            $remaining = min($remainingDominio, $remainingGlobal);

            if ($remaining <= 0) {
                return [false, "No hay cupo disponible. Dominio: $ocupadosDominio/$maxContent. Global: $ocupadosGlobal/$maxGlobal.", []];
            }

            // =========================================================
            // 3) configs
            // =========================================================
            $configs = Dominios_ContenidoModel::select('id_dominio_contenido','tipo','palabras_claves')
                ->where('id_dominio', (int)$IdDominio)
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
            // 4) construir tareas (keywords pueden repetirse)
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

                $palabras = array_slice($palabras, 0, 5);

                foreach ($palabras as $kw) {
                    $kw = trim((string)$kw);
                    if ($kw === '') continue;

                    $tareas[] = [
                        'id_dominio_contenido' => (int)$config->id_dominio_contenido,
                        'tipo' => (string)$tipo,
                        'keyword' => $kw,
                    ];
                }
            }

            if (!$tareas) {
                return [false, 'No hay palabras clave válidas para generar contenido.', []];
            }

            // ✅ cortar por remaining final (dominio + global)
            $tareas = array_slice($tareas, 0, $remaining);

            // =========================================================
            // 5) crear registros + lista jobs
            // =========================================================
            $jobs = [];
            foreach ($tareas as $t) {
                $jobUuid = (string) Str::uuid();

                $detalle = Dominios_Contenido_DetallesModel::create([
                    'job_uuid'             => $jobUuid,
                    'id_dominio_contenido' => (int) $t['id_dominio_contenido'],
                    'id_dominio'           => (int) $IdDominio,
                    'tipo'                 => (string) $t['tipo'],
                    'keyword'              => (string) $t['keyword'],
                    'estatus'              => 'encolado',
                    'modelo'               => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                ]);

                $jobs[] = [
                    'idDominio' => (int)$IdDominio,
                    'idDominioContenido' => (int)$t['id_dominio_contenido'],
                    'tipo' => (string)$t['tipo'],
                    'keyword' => (string)$t['keyword'],
                    'detalleId' => (int)$detalle->id_dominio_contenido_detalle,
                    'jobUuid' => (string)$jobUuid,
                ];
            }

            $enviadas = count($jobs);

            $ocupadosDespuesDominio = $ocupadosDominio + $enviadas;
            $ocupadosDespuesGlobal  = $ocupadosGlobal + $enviadas;

            $quedanDominio = max(0, $maxContent - $ocupadosDespuesDominio);
            $quedanGlobal  = max(0, $maxGlobal - $ocupadosDespuesGlobal);

            $dTxt = $desde->toDateTimeString();
            $hTxt = $hasta ? $hasta->toDateTimeString() : 'N/D';

            $msg = "Generación iniciada. Se enviaron $enviadas tareas. "
                . "Dominio: $ocupadosDespuesDominio/$maxContent (quedan $quedanDominio). "
                . "Global: $ocupadosDespuesGlobal/$maxGlobal (= $maxActiveDomains dominios x $maxContent) (quedan $quedanGlobal). "
                . "Ventana: $dTxt → $hTxt (plan $plan).";

            return [true, $msg, $jobs];
        });

        // ✅ DISPATCH fuera de transacción
        foreach ($jobsToDispatch as $j) {
            try {
                GenerarContenidoKeywordJob::dispatch(
                    $j['idDominio'],
                    $j['idDominioContenido'],
                    $j['tipo'],
                    $j['keyword'],
                    $j['detalleId'],
                    $j['jobUuid'],
                )->onConnection('database')->onQueue('default');
            } catch (\Throwable $e) {
                Dominios_Contenido_DetallesModel::where('id_dominio_contenido_detalle', (int)$j['detalleId'])
                    ->update([
                        'estatus' => 'error_final',
                        'error' => 'Dispatch falló: ' . $e->getMessage(),
                    ]);
            }
        }

        return $ok ? back()->withSuccess($msg) : back()->withError($msg);

    } catch (\Throwable $e) {
        return back()->withError('Error al iniciar generación: ' . $e->getMessage());
    }
}


private function promptHtml(string $tipo, string $keyword): string
{
    $base = "Devuelve SOLO HTML para pegar en WordPress.
NO incluyas: <!DOCTYPE>, <html>, <head>, <meta>, <title>, <body>, <main>.
Devuelve únicamente el contenido: <h1>, <h2>, <h3>, <p>, <ul><li>, etc.";

    if ($tipo === 'post') {
        return "{$base}
Escribe un POST SEO en español para: {$keyword}.
Reglas:
- No uses títulos genéricos como 'Introducción' o 'Conclusión'.
- Incluye H1 y secciones útiles con H2/H3.";
    }

    return "{$base}
Crea una PÁGINA/LANDING SEO en español para: {$keyword}.
Reglas:
- Enfocada a conversión: beneficios, proceso, FAQ, CTA.
- No uses 'Introducción', 'Conclusión' ni '¿Qué es...?'.";
}


private function promptAuditorHtml(string $tipo, string $keyword, string $draftHtml): string
{
    return "Eres un consultor SEO senior especializado en análisis técnico y de contenido.
Tu tarea es AUDITAR y MEJORAR el contenido entregado y devolver UNA VERSIÓN FINAL.

Devuelve SOLO HTML válido listo para WordPress.
NO incluyas <!DOCTYPE>, <html>, <head>, <meta>, <title>, <body>.
NO uses markdown.
NO expliques nada.
NO uses headings: Introducción, Conclusión, ¿Qué es...?
NO uses casos de éxito ni testimonios.
NO uses el título 'guía práctica' ni variantes.

Objetivo:
- Mejorar intención de búsqueda, profundidad semántica y estructura
- Reducir relleno y repetición
- Hacer headings más específicos y no genéricos
- Mejorar el gancho inicial (primeros 2 párrafos)
- Añadir FAQ (2-5) si aporta
- Añadir CTA breve al final

Tipo: {$tipo}
Keyword principal: {$keyword}

HTML A MEJORAR (reescribe y devuelve el HTML final):
{$draftHtml}";
}

private function openaiText(string $apiKey, string $model, string $prompt): string
{
    $resp = Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
        ])
        ->connectTimeout(10)
        ->timeout(120)
        ->retry(1, 500)
        ->post('https://api.openai.com/v1/responses', [
            'model' => $model,
            'input' => $prompt,
        ]);

    if (!$resp->successful()) {
        dd('Error OpenAI', $resp->status(), $resp->body());
    }

    $data = $resp->json();

    $text = '';
    foreach (($data['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $c) {
            if (($c['type'] ?? '') === 'output_text') {
                $text .= ($c['text'] ?? '');
            }
        }
    }

    if (trim($text) === '') {
        dd('No encontré texto en respuesta', $data);
    }

    return trim($text);
}
private function promptRedactor(string $tipo, string $keyword, string $enfoque): string
{
    $base = "Devuelve SOLO HTML válido listo para pegar en WordPress.
NO incluyas <!DOCTYPE>, <html>, <head>, <meta>, <title>, <body>.
NO uses markdown. NO expliques nada.
NO uses el texto 'guía práctica' ni variantes.
NO uses headings genéricos: 'Introducción', 'Conclusión', '¿Qué es...?'.
NO uses casos de éxito ni testimonios.
Usa lenguaje claro, semántico y profundo.";

    if ($tipo === 'post') {
        return "{$base}

Actúa como Redactor SEO profesional (España).
Keyword: {$keyword}
Enfoque: {$enfoque}

Estructura obligatoria:
- 1 <h1> (título atractivo y natural)
- 6 a 9 <h2> (no genéricos, distintos entre sí)
- 1 a 2 <h3> dentro de varios <h2>
- Párrafos reales (sin relleno)
- Usa <ul><li> cuando aporte claridad
- Añade 2 a 5 FAQs (preguntas + respuesta en <p>)
- Cierra con CTA breve en <p><strong>...</strong></p>

Reglas de estilo:
- No empieces con definiciones tipo diccionario.
- Evita frases: 'en este artículo veremos...'.
- No repitas encabezados entre secciones.";
    }

    // default: page/landing
    return "{$base}

Actúa como Redactor SEO experto en conversión (España).
Keyword: {$keyword}
Enfoque: {$enfoque}

Estructura obligatoria (landing):
- 1 <h1> potente
- 8 a 12 <h2> orientados a conversión (beneficios, servicios, proceso, objeciones, FAQ, CTA)
- Incluye varios <h3> para profundizar
- CTA al inicio, a mitad y al final
- Usa bloques con <div> si ayuda a maquetar (sin CSS, solo estructura)
- Añade 3 a 6 FAQs
- Cierra con CTA breve en <p><strong>...</strong></p>

Reglas:
- No uses 'Introducción'/'Conclusión'/'¿Qué es...?'
- No uses casos de éxito ni testimonios
- No repitas encabezados.";
}


public function ContenidoGenerado(Request $request, string $IdDominio)
{
    $tipo = $request->get('tipo');
    $estatus = $request->get('estatus');
    $dominio = DominiosModel::find($IdDominio);

    $query = Dominios_Contenido_DetallesModel::where('id_dominio', (int)$IdDominio)
        ->orderByDesc('id_dominio_contenido_detalle');

    if ($tipo) {
        $query->where('tipo', $tipo);
    }

    if ($estatus) {
        $query->where('estatus', $estatus);
    }

    $items = $query->get(); // ✅ DataTables se encarga de paginar

    return view('Dominios.ContenidoGenerado', compact('IdDominio', 'items', 'tipo', 'estatus', 'dominio'));
}
    public function EditarTipoGenerador(Request $request, $IdDominioGenerador)
{
    $generador = Dominios_ContenidoModel::findOrFail($IdDominioGenerador);

    $tiposPermitidos = ['POST', 'PAGINAS'];

    // Tipos existentes en el dominio de este generador
    $tiposExistentes = Dominios_ContenidoModel::where('id_dominio', $generador->id_dominio)
        ->pluck('tipo')
        ->map(fn($t) => strtoupper(trim($t)))
        ->toArray();

    // El tipo "otro" que se podría elegir
    $tipoActual = strtoupper(trim($generador->tipo));
    $otroTipo = $tipoActual === 'POST' ? 'PAGINAS' : 'POST';

    // Solo se puede cambiar al otro si NO existe ya en el dominio
    $puedeCambiar = !in_array($otroTipo, $tiposExistentes, true);

    // Opciones del select: siempre incluye el actual; incluye el otro solo si puede
    $tiposDisponibles = $puedeCambiar ? [$tipoActual, $otroTipo] : [$tipoActual];

    return view('Dominios.GeneradorEditar', compact('generador', 'tiposDisponibles', 'puedeCambiar'));
}

    



    public function GuardarEditarTipoGenerador(Request $request, $IdDominioGenerador)
    {
        $generador = Dominios_ContenidoModel::findOrFail($IdDominioGenerador);

        // 1) Validación básica
        $request->validate([
            'tipo' => ['required', 'in:POST,PAGINAS'],
            'palabras_claves' => ['nullable'], // viene en hidden como JSON normalmente
        ]);

        $nuevoTipo = strtoupper(trim($request->input('tipo')));

        // 2) Bloqueo de duplicados dentro del mismo dominio (excluyendo el mismo registro)
        $existeEnDominio = Dominios_ContenidoModel::where('id_dominio', $generador->id_dominio)
            ->where('tipo', $nuevoTipo)
            ->where('id_dominio_contenido', '!=', $generador->id_dominio_contenido)
            ->exists();

        if ($existeEnDominio) {
            return back()
                ->withErrors(['tipo' => 'Ese tipo ya existe para este dominio. No puedes duplicarlo.'])
                ->withInput();
        }

        $palabras = json_decode($request['palabras_claves']); // ["seo","paginas"]
        
        $palabras_clave_cadena = implode(',', $palabras);   // "seo, paginas"

        try 
        {
            $generador->fill([
                 'tipo' => $nuevoTipo,
                'palabras_claves' => $palabras_clave_cadena,
            ]);
            $generador->save(); //actualizar empresa
                
                
    
        } 
        catch (Exception $ex) 
        {
            return back()->withError('Ocurrio Un Error al Editar el Tipo Generador de Contenido ' . $ex->getMessage())->withInput();
        }
        return redirect()->route('dominios.show', $generador->id_dominio)->withSuccess('El Tipo de Generador Contenido Se Ha Editado Exitosamente');

    }









public function publicar($dominio, int $detalle): RedirectResponse
{
    $dom = DominiosModel::findOrFail($dominio);
    $it  = Dominios_Contenido_DetallesModel::findOrFail($detalle);

    $it->estatus = 'en_proceso';
    $it->error = null;
    $it->save();

    try {
        $secret = (string) env('WP_WEBHOOK_SECRET'); // DEBE ser el mismo que el plugin
        if ($secret === '') {
            throw new \RuntimeException('WP_WEBHOOK_SECRET no configurado en .env');
        }

        $wpBase = rtrim((string)$dom->url, '/');

        $urlRest     = $wpBase . '/wp-json/lws/v1/upsert';
        $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

        // ✅ Robustez: normaliza el tipo
        $tipoNorm = strtolower(trim((string) $it->tipo));
        $type = ($tipoNorm === 'page') ? 'page' : 'post';

        if (empty($it->contenido_html)) {
            throw new \RuntimeException('contenido_html está vacío (no hay nada que publicar).');
        }

        // ✅ Si NO hay plantilla seleccionada en el dominio => NO usar Elementor
        $templatePath = trim((string) ($dom->elementor_template_path ?? ''));
        $useElementor = ($templatePath !== '');

        // ✅ Canvas solo aplica a pages
        $canvas = ($type === 'page') ? 'elementor_canvas' : '';

        // ✅ Content: si no usamos Elementor, aseguramos enviar HTML (no JSON)
        $contentToSend = (string) $it->contenido_html;

        if (!$useElementor) {
            $contentToSendTrim = ltrim($contentToSend);

            // Si parece JSON, intentamos extraer un bloque HTML usable desde editor/content/text
            $looksLikeJson = ($contentToSendTrim !== '' && in_array($contentToSendTrim[0], ['{', '['], true));

            if ($looksLikeJson) {
                $decoded = json_decode($contentToSend, true);
                if (is_array($decoded)) {
                    $candidate = null;

                    $walk = function ($node) use (&$walk, &$candidate) {
                        if ($candidate) return;
                        if (is_array($node)) {
                            foreach ($node as $k => $v) {
                                if (is_string($k) && in_array($k, ['editor', 'content', 'text'], true) && is_string($v) && str_contains($v, '<')) {
                                    $candidate = $v;
                                    return;
                                }
                                $walk($v);
                            }
                        }
                    };

                    $walk($decoded);

                    if (is_string($candidate) && trim($candidate) !== '') {
                        $contentToSend = $candidate;
                    } else {
                        // fallback final: por si no encontramos nada dentro del JSON
                        $contentToSend = '<div>' . e($it->title ?: ($it->keyword ?: '')) . '</div>';
                    }
                }
            }

            // Si no contiene tags, lo envolvemos simple para que WP no quede vacío
            if (!str_contains($contentToSend, '<')) {
                $contentToSend = '<div>' . e($contentToSend) . '</div>';
            }
        }

        $payload = [
            'type'  => $type,
            'wp_id' => $it->wp_id ?: null,

            'title'   => $it->title ?: ($it->keyword ?: 'Sin título'),
            'content' => $contentToSend,

            // ✅ clave: builder según si hay plantilla o no
            'builder' => $useElementor ? 'elementor' : 'html',

            // ✅ Solo setear template/canvas cuando sea Elementor
            'wp_page_template' => $useElementor ? $canvas : '',
            'template'         => $useElementor ? $canvas : '',

            'status' => 'publish',
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('No se pudo serializar payload JSON');
        }

        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Timestamp'  => (string)$ts,
            'X-Signature'  => $sig,
        ];

        $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlRest, ['body' => $body]);

        // fallback si REST no existe / bloqueado
        if (in_array($resp->status(), [404, 405], true)) {
            $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlFallback, ['body' => $body]);
        }

        $json = $resp->json();

        if (!$resp->ok() || !is_array($json) || empty($json['ok'])) {
            $msg = is_array($json) ? ($json['message'] ?? 'Error desconocido') : ('HTTP ' . $resp->status());
            $it->estatus = 'error';
            $it->error = $msg;
            $it->save();

            return back()->with('error', 'No se pudo publicar: ' . $msg);
        }

        // OK
        $it->estatus = (($json['status'] ?? '') === 'publish') ? 'publicado' : 'generado';
        $it->wp_id   = (int)($json['wp_id'] ?? 0) ?: $it->wp_id;
        $it->wp_link = (string)($json['link'] ?? '');
        $it->save();

        return back()->with('exito', 'Contenido enviado y publicado en WordPress.');
    } catch (\Throwable $e) {
        $it->estatus = 'error';
        $it->error = $e->getMessage();
        $it->save();

        return back()->with('error', 'Error publicando en WordPress: ' . $e->getMessage());
    }
}




public function programar(Request $request, $dominio, int $detalle): RedirectResponse
{
    $dom = DominiosModel::findOrFail($dominio);
    $it  = Dominios_Contenido_DetallesModel::findOrFail($detalle);

    $request->validate([
        'schedule_at' => ['required', 'string'], // ISO UTC desde el hidden (ej: ...Z)
    ]);

    $it->estatus = 'en_proceso';
    $it->error = null;
    $it->save();

    try {
        $secret = (string) env('WP_WEBHOOK_SECRET');
        if ($secret === '') {
            throw new \RuntimeException('WP_WEBHOOK_SECRET no configurado en .env');
        }

        // schedule_at viene en ISO UTC (Z). Parse robusto y normalización.
        $scheduleAtRaw = (string) $request->input('schedule_at');

        try {
            $dtUtc = Carbon::parse($scheduleAtRaw)->setTimezone('UTC');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Fecha inválida en schedule_at: ' . $scheduleAtRaw);
        }

        // ISO limpio para enviar al plugin
        $scheduleAtUtcIso = $dtUtc->toIso8601String(); // 2025-12-19T16:30:00+00:00

        $wpBase = rtrim((string) $dom->url, '/');
        $urlRest = $wpBase . '/wp-json/lws/v1/upsert';
        $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

        $type = ($it->tipo === 'page') ? 'page' : 'post';

        $payload = [
            'type'        => $type,
            'wp_id'       => $it->wp_id ?: null,
            'title'       => $it->title ?: ($it->keyword ?: 'Sin título'),
            'content'     => $it->contenido_html ?: '',
            'status'      => 'future',
            'schedule_at' => $scheduleAtUtcIso,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Timestamp'  => (string) $ts,
            'X-Signature'  => $sig,
        ];

        $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlRest, ['body' => $body]);

        // fallback si wp-json está bloqueado
        if (in_array($resp->status(), [404, 405], true)) {
            $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlFallback, ['body' => $body]);
        }

        $json = $resp->json();

        if (!$resp->ok() || !is_array($json) || empty($json['ok'])) {
            $msg = is_array($json) ? ($json['message'] ?? 'Error desconocido') : ('HTTP ' . $resp->status());
            $it->estatus = 'error';
            $it->error = $msg;
            $it->save();
            return back()->with('error', 'No se pudo programar: ' . $msg);
        }

        // Guardar ID y link siempre que existan
        $it->wp_id   = (int) ($json['wp_id'] ?? 0) ?: $it->wp_id;
        $it->wp_link = (string) ($json['link'] ?? '');

        // Guardar estatus según WP + scheduled_at
        $wpStatus = (string) ($json['status'] ?? '');

        if ($wpStatus === 'future') {
            $it->estatus = 'programado';

            // ✅ ideal: usar fecha exacta que WP guardó (si plugin la devuelve)
            if (!empty($json['scheduled_gmt'])) {
                $it->scheduled_at = Carbon::parse($json['scheduled_gmt'], 'UTC')
                    ->setTimezone(config('app.timezone'));
            } else {
                // fallback: usa lo que tú mandaste
                $it->scheduled_at = $dtUtc->copy()->setTimezone(config('app.timezone'));
            }
        } elseif ($wpStatus === 'publish') {
            $it->estatus = 'publicado';
            $it->scheduled_at = null;
        } else {
            $it->estatus = 'generado';
            $it->scheduled_at = null;
        }

        $it->save();

        return back()->with('exito', 'Contenido programado correctamente en WordPress.');
    } catch (\Throwable $e) {
        $it->estatus = 'error';
        $it->error = $e->getMessage();
        $it->save();

        return back()->with('error', 'Error programando en WordPress: ' . $e->getMessage());
    }
}



    public function ReportesDominio($id_dominio)
    {
        $dominio = DominiosModel::find($id_dominio);

        $reportes = SeoReport::where('id_dominio', $dominio->id_dominio)->orderBy('id')->get();

     

        

        return view('Dominios.DominiosReportes', compact('dominio', 'reportes'));
    }
    public function IdentidadDominios()
    {
         $userId = Auth::id();
        if (Auth::user()->roles[0]->name == 'administrador') {
            $dominios = DominiosModel::all();
        } else {

            // IDs de dominios asignados al usuario (tabla pivote)
            $idsAsignados = Dominios_UsuariosModel::where('id_usuario', $userId)
                ->pluck('id_dominio');

            // Dominios que (a) están asignados al usuario o (b) fueron creados por él
            $dominios = DominiosModel::whereIn('id_dominio', $idsAsignados)
                ->orWhere('creado_por', $userId)
                ->get();
        }
        

        return view('Dominios.DominioIdentidad', compact('dominios'));
    }
    public function ActulizarIdentidadDominios(Request $request)
    {
    

        // 1) Leer JSON (tu lógica)
        $dominios = json_decode($request->input('datos'));

        if (!$dominios || !is_array($dominios)) {
            return back()->withError('El JSON de "datos" no es válido.')->withInput();
        }

        // 2) Detectar si vienen imágenes (tu lógica, pero con el nombre correcto)
        // En tu form: name="imagenes[{{ $id }}]"
        $tieneImagen = $request->hasFile('imagenes');

        foreach ($dominios as $dominio) {
            try {
                $destino = "images/dominios/dominio/";
                $carpeta = $dominio->id_dominio;

                $rutaCarpeta = public_path($destino . $carpeta);

                if (!File::exists($rutaCarpeta)) {
                    File::makeDirectory($rutaCarpeta, 0777, true);
                }

                // ✅ Buscar imagen de ESTE dominio (si vino)
                $jpgBytes = null;
                $nombreImagen = null;

                if ($tieneImagen && $request->hasFile("imagenes.$carpeta")) {
                    $file = $request->file("imagenes.$carpeta");

                    if (!$file->isValid()) {
                        return back()
                            ->withError('Upload falló para dominio ' . $carpeta . ': ' . $file->getErrorMessage())
                            ->withInput();
                    }

                    // Preparar imagen (misma lógica, pero por dominio)
                    $img = Image::read($file->getRealPath())->cover(400, 400);
                    $jpgBytes = $img->encodeByExtension('jpg', quality: 85);

                    // Guardar imagen
                    $nombreImagen = $carpeta . '.jpg';
                    $rutaImagen = $rutaCarpeta . DIRECTORY_SEPARATOR . $nombreImagen;
                    File::put($rutaImagen, $jpgBytes);
                }

                // 3) Actualizar BD (tu lógica)
                $dominioModel = DominiosModel::find($carpeta);

            

                $dominioModel->fill([
                    'color' => $dominio->color,
                    'direccion' => $dominio->direccion,
                ]);

                if ($nombreImagen) {
                    $dominioModel->imagen = $destino . $carpeta . '/' . $nombreImagen;
                }

                $dominioModel->save();

            } catch (\Exception $ex) {
                return back()
                    ->withError('Ocurrió un error al actualizar la identidad del Dominio ' . ($dominio->nombre_dominio ?? 'N/A') . ': ' . $ex->getMessage())
                    ->withInput();
            }
        }

        return redirect()->route('dominiosidentidad')->withSuccess('Los dominios se han actualizado exitosamente');
    }


public function activarLicencia($id, LicenseService $licenses)
{
    $user = auth()->user();
    if (!$user) return back()->withError('Debes iniciar sesión.');

    $licensePlain = $user->getLicenseKeyPlain();
    if (!$licensePlain) return back()->withError('Tu usuario no tiene licencia registrada.');

    $dominio = DominiosModel::where('id_dominio', $id)->first();
    if (!$dominio) return back()->withError('Dominio no encontrado.');

    $plan = 'pro';
    $max = (int) config("licenses.max_by_plan.$plan", 0);

    // Conteo local (para bloquear UI; servidor igual manda el máximo real)
    $used = (int) LicenciaDominiosActivacionModel::where('user_id', $user->id)
        ->where('license_key', sha1($licensePlain))
        ->where('estatus', 'activo')
        ->count();

    if ($max > 0 && $used >= $max) {
        return back()->withError("Límite alcanzado: $used / $max dominios activos.");
    }

    $host = $this->hostFromUrl($dominio->url);

    try {
        DB::transaction(function () use ($licenses, $user, $licensePlain, $host, $dominio) {
            $resp = $licenses->activarYRegistrar(
                $user->id,
                $licensePlain,
                $host,
                $user->license_email ?? $user->email
            );

            if (!data_get($resp, 'activated')) {
                throw new \Exception(data_get($resp, 'message', 'No se pudo activar la licencia.'));
            }

            // Opcional: reflejarlo en tu tabla dominios
            $dominio->estatus = 'SI';
            $dominio->save();
        });

    } catch (\Throwable $e) {
        return back()->withError('No se pudo activar: ' . $e->getMessage());
    }

    return back()->withSuccess('Licencia activada para el dominio.');
}

public function desactivarLicencia($id, LicenseService $licenses)
{
    $user = auth()->user();
    if (!$user) return back()->withError('Debes iniciar sesión.');

    $licensePlain = $user->getLicenseKeyPlain();
    if (!$licensePlain) return back()->withError('Tu usuario no tiene licencia registrada.');

    $dominio = DominiosModel::where('id_dominio', $id)->first();
    if (!$dominio) return back()->withError('Dominio no encontrado.');

    $host = $this->hostFromUrl($dominio->url);

    try {
        DB::transaction(function () use ($licenses, $user, $licensePlain, $host, $dominio) {

            $resp = $licenses->desactivarYRegistrar(
                $user->id,
                $licensePlain,
                $host
            );

            // Si no estaba activado, la API puede decir "not activated on this domain"
            // En ese caso igual lo marcamos inactivo localmente.
            $msg = (string) data_get($resp, 'message', '');

            $ok = (bool) data_get($resp, 'deactivated', false) || (bool) data_get($resp, 'success', false)
                || str_contains(strtolower($msg), 'not activated');

            if (!$ok) {
                throw new \Exception($msg ?: 'No se pudo desactivar la licencia.');
            }

            // Opcional: reflejarlo en tu tabla dominios
            $dominio->estatus = 'NO';
            $dominio->save();
        });

    } catch (\Throwable $e) {
        return back()->withError('No se pudo desactivar: ' . $e->getMessage());
    }

    return back()->withSuccess('Licencia desactivada para el dominio.');
}


}
