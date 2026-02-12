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
use App\Models\User;
use Illuminate\Support\Facades\Storage;

use App\Services\ServicioGenerarDominio;
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

        return view('Dominios.Dominio', compact('dominios', 'plan', 'maximo', 'usados', 'restantes'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $usuario = auth()->user();
        if (!$usuario) {
            return redirect()->back()->withError('Debes iniciar sesión.');
        }

        $esAdmin = $usuario->hasRole('administrador'); // ajusta nombre exacto

        // Valores para UI
        $plan = 'pro';
        $max  = (int) config("licenses.max_by_plan.$plan", 0);
        $used = 0;
        $remaining = 0;

        if ($esAdmin) {
            $plan = 'admin';
            $max = PHP_INT_MAX;
            $used = 0;
            $remaining = PHP_INT_MAX;

            return view('Dominios.DominioCreate', compact('plan','max','used','remaining','esAdmin'));
        }

        // Titular real (si es dependiente, es el padre)
        $titular = $usuario->titularLicencia();
        if (!$titular) {
            return redirect()->back()->withError('No se encontró el titular de la licencia.');
        }

        // Licencia efectiva (del titular)
        if ($titular->license_key) {
            $licensePlain = $titular->getLicenseKeyPlain();

            $used = (int) LicenciaDominiosActivacionModel::where('user_id', $titular->id)
                ->where('license_key', sha1($licensePlain))
                ->where('estatus', 'activo')
                ->count();

            $remaining = max(0, $max - $used);
        }

        return view('Dominios.DominioCreate', compact('plan','max','used','remaining','esAdmin'));
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

        $esAdmin = $user->hasRole('administrador'); // ajusta nombre exacto

        $request->validate([
            'nombre' => 'required|string|max:255',
            'url'    => 'required|string|max:2000|unique:dominios,url',
        ]);

        $host = $this->hostFromUrl($request->input('url'));

        // =======================
        // ✅ ADMIN (sin licencia)
        // =======================
        if ($esAdmin) {
            try {
                DB::transaction(function () use ($request, $user) {

                    // 1) Generar ID dominio manual con lock
                    $IdDominio = (int) DB::table('dominios')->lockForUpdate()->max('id_dominio') + 1;

                    // 2) Crear dominio
                    DominiosModel::create([
                        'id_dominio' => $IdDominio,
                        'url'        => $request->input('url'),
                        'nombre'     => strtoupper($request->input('nombre')),
                        'estatus'    => 'SI',
                        'creado_por' => (int) $user->id,
                    ]);

                    // 3) Asignación al usuario (opcional)
                    $existe = DB::table('dominios_usuarios')
                        ->where('id_usuario', (int) $user->id)
                        ->where('id_dominio', (int) $IdDominio)
                        ->exists();

                    if (!$existe) {
                        $nextId = (int) DB::table('dominios_usuarios')
                            ->lockForUpdate()
                            ->max('id_dominio_usuario') + 1;

                        DB::table('dominios_usuarios')->insert([
                            'id_dominio_usuario' => $nextId, // ✅ obligatorio
                            'id_usuario'         => (int) $user->id,
                            'id_dominio'         => (int) $IdDominio,
                            'fecha_creacion'     => now(),
                            'creado_por'         => (int) $user->id,
                        ]);
                    }
                });

            } catch (\Throwable $ex) {
                return back()
                    ->withError('Ocurrió un error al crear el dominio (admin): ' . $ex->getMessage())
                    ->withInput();
            }

            return redirect("dominios")
                ->withSuccess('El Dominio se ha creado exitosamente (admin: sin licencia).');
        }

        // =======================
        // -------- NO ADMIN ------
        // =======================

        // ✅ Titular real para compartir cupo
        $titular = $user->titularLicencia();
        if (!$titular) {
            return back()->withError('No se encontró el titular de la licencia.')->withInput();
        }

        $licensePlain = $titular->getLicenseKeyPlain();
        if (!$licensePlain) {
            return back()->withError('El titular no tiene licencia registrada.')->withInput();
        }

        $email = $titular->license_email ?? $titular->email;

        // (UI local opcional)
        $plan = 'pro';
        $max = (int) config("licenses.max_by_plan.$plan", 0);

        $usedLocal = (int) LicenciaDominiosActivacionModel::where('user_id', (int) $titular->id)
            ->where('license_key', sha1($licensePlain))
            ->where('estatus', 'activo')
            ->count();

        $remainingLocal = max(0, $max - $usedLocal);

        if ($max > 0 && $remainingLocal <= 0) {
            return back()
                ->withError("Ya alcanzaste el límite de tu plan ($plan): máximo $max dominios activos.")
                ->withInput();
        }

        // ✅ PROBE (fuente de verdad) con finally para no dejar slots ocupados
        $probe = 'probe-' . substr(sha1(uniqid('', true)), 0, 10) . '.ideiweb.com';

        try {
            $probeResp = $licenses->activate($licensePlain, $probe, $email);

            if (!data_get($probeResp, 'activated')) {
                $msg = data_get($probeResp, 'message', 'No hay cupo disponible.');
                return back()
                    ->withError("No tienes activaciones disponibles en el servidor de licencias. ($msg)")
                    ->withInput();
            }
        } catch (\Throwable $e) {
            return back()
                ->withError('No se pudo verificar cupo de activaciones: ' . $e->getMessage())
                ->withInput();
        } finally {
            try {
                $licenses->deactivate($licensePlain, $probe);
            } catch (\Throwable $e) {
                // opcional: log
            }
        }

        // Crear + asignar + activar
        try {
            DB::transaction(function () use ($request, $licenses, $user, $titular, $licensePlain, $host, $email) {

                // 1) ID dominio manual con lock
                $IdDominio = (int) DB::table('dominios')->lockForUpdate()->max('id_dominio') + 1;

                // 2) Crear dominio
                DominiosModel::create([
                    'id_dominio' => $IdDominio,
                    'url'        => $request->input('url'),
                    'nombre'     => strtoupper($request->input('nombre')),
                    'estatus'    => 'SI',
                    'creado_por' => (int) $user->id,
                ]);

                // 3) Asignar dominio al TITULAR (cupo compartido)
                $existe = DB::table('dominios_usuarios')
                    ->where('id_usuario', (int) $titular->id)
                    ->where('id_dominio', (int) $IdDominio)
                    ->exists();

                if (!$existe) {
                    $nextId = (int) DB::table('dominios_usuarios')
                        ->lockForUpdate()
                        ->max('id_dominio_usuario') + 1;

                    DB::table('dominios_usuarios')->insert([
                        'id_dominio_usuario' => $nextId, // ✅ obligatorio
                        'id_usuario'         => (int) $titular->id,
                        'id_dominio'         => (int) $IdDominio,
                        'fecha_creacion'     => now(),
                        'creado_por'         => (int) $user->id,
                    ]);
                }

                // 4) Activar + registrar con ID DEL TITULAR
                $resp = $licenses->activarYRegistrar(
                    (int) $titular->id,
                    $licensePlain,
                    $host,
                    $email
                );

                if (!data_get($resp, 'activated')) {
                    throw new \Exception(data_get($resp, 'message', 'No se pudo activar la licencia para este dominio.'));
                }
            });

        } catch (\Throwable $ex) {
            return back()
                ->withError('Ocurrió un error al crear/activar el dominio: ' . $ex->getMessage())
                ->withInput();
        }

        return redirect("dominios")->withSuccess('El Dominio se ha creado y activado exitosamente');
    }


    /**
     * Display the specified resource.
     */
    public function show(string $IdDominio)
    {
        $dominio = DominiosModel::find($IdDominio);
        $generadores=Dominios_ContenidoModel::Dominios($IdDominio);
       // dd($generadores);
       return view('Dominios.DominioShow',compact('dominio','generadores'));
    }

    /**
     * Show the form for editing the specified resource.
     */
 public function edit($id)
{
    $dominio = DominiosModel::findOrFail($id);

    $wpBase  = env('TESTINGSEO_WP_URL', 'https://testingseo.entornodedesarrollo.es');
    $secret  = (string) env('TSEO_TPL_SECRET', '');

    // =========================
    // 1) WP (como ya lo tienes)
    // =========================
    $plantillas = [];

    // Si no hay secret, evitamos llamar WP (no rompe)
    if (!empty($secret)) {
        try {
            $ts  = time();
            $sig = hash_hmac('sha256', $ts . '.templates', $secret);

            $res = Http::acceptJson()
                ->withOptions(['verify' => false])
                ->timeout(15)
                ->get(rtrim($wpBase, '/').'/', [
                    'tseo_templates' => 1,
                    'ts' => $ts,
                    'sig' => $sig,
                ]);

            if ($res->ok() && ($res->json('ok') === true)) {
                $plantillas = $res->json('items') ?? [];
            }
        } catch (\Throwable $e) {
            // NO rompas el edit: solo deja WP vacío
            $plantillas = [];
            // opcional: \Log::warning('WP templates error: '.$e->getMessage());
        }
    }

    // =========================
    // 2) Local (storage/app/elementor) SIN preview
    // =========================
    $plantillasLocal = [];
    $dir = storage_path('app/elementor');

    if (File::isDirectory($dir)) {
        foreach (File::files($dir) as $f) {
            if (strtolower($f->getExtension()) !== 'json') continue;

            $filename = $f->getFilename();
            $plantillasLocal[] = [
                'path' => 'elementor/' . $filename, // esto es lo que guardas en DB
                'name' => $filename,                 // nombre para mostrar
            ];
        }

        // Ordena por nombre
        usort($plantillasLocal, fn($a, $b) => strcmp($a['name'], $b['name']));
    }
            //dd($plantillas);
    return view('Dominios.DominioEdit', compact('dominio', 'plantillas', 'plantillasLocal'));
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
                'nombre' => ['required','string','max:255'],
                'password' => ['nullable','string','max:255'],
                'elementor_template_path' => ['nullable','string','max:255'],
                  'solo_html' => ['nullable','boolean'], // ✅ nuevo
            ]);
           $soloHtml = (bool) $request->input('solo_html', false);
            $path = $soloHtml ? null : $request->input('elementor_template_path');
            $path = ($path === '') ? null : $path;


            $dominios->fill([
                'nombre' =>$request['nombre'],
                'elementor_template_path' => $request->input('elementor_template_path'), // 👈 NUEVO
            ]);

             // solo si SÍ posee documentos (y tienes el nombre de la imagen) seteas la imagen
            if ($PosseDocumentos === 'SI' && !empty($NombreImagen)) {
                $dominios->imagen = $destino . $NombreCarpeta . '/' . $NombreImagen;
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









    public function Generador(string $IdDominio, \App\Services\ServicioGenerarDominio $servicioGenerador)
{
    $usuario = auth()->user();
    if (!$usuario) return back()->withError('Debes iniciar sesión.');

    [$ok, $mensaje, $jobs] = $servicioGenerador->iniciarGeneracion((int)$IdDominio, $usuario);

    if ($ok) {
        $servicioGenerador->despacharJobs($jobs);
        return back()->withSuccess($mensaje);
    }

    return back()->withError($mensaje);
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
        'schedule_at' => ['required', 'string'],
    ]);

    $it->estatus = 'en_proceso';
    $it->error = null;
    $it->save();

    try {
        $secret = (string) env('WP_WEBHOOK_SECRET');
        if ($secret === '') {
            throw new \RuntimeException('WP_WEBHOOK_SECRET no configurado en .env');
        }

        $scheduleAtRaw = (string) $request->input('schedule_at');

        try {
            $dtUtc = Carbon::parse($scheduleAtRaw)->setTimezone('UTC');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Fecha inválida en schedule_at: ' . $scheduleAtRaw);
        }

        // ✅ WordPress SAFE (GMT): "Y-m-d H:i:s"
        $scheduleAtUtcWp = $dtUtc->format('Y-m-d H:i:s');

        $wpBase      = rtrim((string) $dom->url, '/');
        $urlRest     = $wpBase . '/wp-json/lws/v1/upsert';
        $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

        $tipoNorm = strtolower(trim((string) $it->tipo));
        $type = ($tipoNorm === 'page') ? 'page' : 'post';

        if (empty($it->contenido_html)) {
            throw new \RuntimeException('contenido_html está vacío (no hay nada que programar).');
        }

        $templatePath = trim((string) ($dom->elementor_template_path ?? ''));
        $useElementor = ($templatePath !== '');

        $canvas = ($type === 'page') ? 'elementor_canvas' : '';

        $contentToSend = (string) $it->contenido_html;

        // Si NO usamos Elementor, asegúrate de enviar HTML (no JSON)
        if (!$useElementor) {
            $contentToSendTrim = ltrim($contentToSend);
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
                        $contentToSend = '<div>' . e($it->title ?: ($it->keyword ?: '')) . '</div>';
                    }
                }
            }

            if (!str_contains($contentToSend, '<')) {
                $contentToSend = '<div>' . e($contentToSend) . '</div>';
            }
        }

        $payload = [
            'type'    => $type,
            'wp_id'   => $it->wp_id ?: null,
            'title'   => $it->title ?: ($it->keyword ?: 'Sin título'),
            'content' => $contentToSend,

            'builder' => $useElementor ? 'elementor' : 'html',

            'wp_page_template' => $useElementor ? $canvas : '',
            'template'         => $useElementor ? $canvas : '',

            'status'      => 'future',

            // ✅ manda fecha en formato WP-safe
            'schedule_at' => $scheduleAtUtcWp,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('No se pudo serializar payload JSON');
        }

        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Timestamp'  => (string) $ts,
            'X-Signature'  => $sig,
        ];

        $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlRest, ['body' => $body]);

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

        $it->wp_id   = (int) ($json['wp_id'] ?? 0) ?: $it->wp_id;
        $it->wp_link = (string) ($json['link'] ?? '');

        $wpStatus = (string) ($json['status'] ?? '');

        if ($wpStatus === 'future') {
            $it->estatus = 'programado';

            if (!empty($json['scheduled_gmt'])) {
                $it->scheduled_at = Carbon::parse($json['scheduled_gmt'], 'UTC')->setTimezone(config('app.timezone'));
            } else {
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
        $usuario = auth()->user();
        $userId = $usuario->id;

        // Admin ve todo (sin roles[0])
        if ($usuario->hasRole('administrador')) {
            $dominios = DominiosModel::all();
            return view('Dominios.DominioIdentidad', compact('dominios'));
        }

        // Si es dependiente, el titular es el padre; si no, es el mismo usuario
        $idTitular = $usuario->id_usuario_padre ?? $usuario->id;

        // Dominios asignados al usuario logueado (si es dependiente, solo los suyos)
        $idsAsignados = Dominios_UsuariosModel::where('id_usuario', $userId)
            ->pluck('id_dominio');

        $consulta = DominiosModel::whereIn('id_dominio', $idsAsignados);

        // Si es titular, también ve los creados por él
        if (is_null($usuario->id_usuario_padre)) {
            $consulta->orWhere('creado_por', $idTitular);
        }

        $dominios = $consulta->get();

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
    $usuario = auth()->user();
    if (!$usuario) return back()->withError('Debes iniciar sesión.');

    $esAdmin = $usuario->hasRole('administrador'); // ajusta nombre exacto

    $dominio = DominiosModel::where('id_dominio', (int)$id)->first();
    if (!$dominio) return back()->withError('Dominio no encontrado.');

    // ✅ ADMIN: solo marca activo local
    if ($esAdmin) {
        try {
            $dominio->estatus = 'SI';
            $dominio->save();
        } catch (\Throwable $e) {
            return back()->withError('No se pudo activar (administrador): ' . $e->getMessage());
        }

        return back()->withSuccess('Dominio activado (admin: sin licencia).');
    }

    // Titular real (si es dependiente, usa el padre)
    $titular = $usuario->id_usuario_padre
        ? User::find($usuario->id_usuario_padre)
        : $usuario;

    if (!$titular) return back()->withError('No se encontró el usuario titular de la licencia.');

    $licensePlain = $titular->getLicenseKeyPlain();
    if (!$licensePlain) return back()->withError('El titular no tiene licencia registrada.');

    $plan = 'pro';
    $max = (int) config("licenses.max_by_plan.$plan", 0);

    $used = (int) LicenciaDominiosActivacionModel::where('user_id', $titular->id)
        ->where('license_key', sha1($licensePlain))
        ->where('estatus', 'activo')
        ->count();

    if ($max > 0 && $used >= $max) {
        return back()->withError("Límite alcanzado: $used / $max dominios activos.");
    }

    $host = $this->hostFromUrl($dominio->url);

    try {
        DB::transaction(function () use ($licenses, $titular, $licensePlain, $host, $dominio) {

            $emailLicencia = $titular->license_email ?? $titular->email;

            $resp = $licenses->activarYRegistrar(
                $titular->id,
                $licensePlain,
                $host,
                $emailLicencia
            );

            if (!data_get($resp, 'activated')) {
                throw new \Exception(data_get($resp, 'message', 'No se pudo activar la licencia.'));
            }

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
    $usuario = auth()->user();
    if (!$usuario) return back()->withError('Debes iniciar sesión.');

    $esAdmin = $usuario->hasRole('administrador'); // ajusta nombre exacto

    $dominio = DominiosModel::where('id_dominio', (int)$id)->first();
    if (!$dominio) return back()->withError('Dominio no encontrado.');

    // ✅ ADMIN: solo marca inactivo local
    if ($esAdmin) {
        try {
            $dominio->estatus = 'NO';
            $dominio->save();
        } catch (\Throwable $e) {
            return back()->withError('No se pudo desactivar (administrador): ' . $e->getMessage());
        }

        return back()->withSuccess('Dominio desactivado (admin: sin licencia).');
    }

    // Titular real (si es dependiente, usa el padre)
    $titular = $usuario->id_usuario_padre
        ? User::find($usuario->id_usuario_padre)
        : $usuario;

    if (!$titular) return back()->withError('No se encontró el usuario titular de la licencia.');

    $licensePlain = $titular->getLicenseKeyPlain();
    if (!$licensePlain) return back()->withError('El titular no tiene licencia registrada.');

    $host = $this->hostFromUrl($dominio->url);

    try {
        DB::transaction(function () use ($licenses, $titular, $licensePlain, $host, $dominio) {

            $resp = $licenses->desactivarYRegistrar(
                $titular->id,
                $licensePlain,
                $host
            );

            $msg = (string) data_get($resp, 'message', '');

            $ok = (bool) data_get($resp, 'deactivated', false)
                || (bool) data_get($resp, 'success', false)
                || str_contains(strtolower($msg), 'not activated');

            if (!$ok) {
                throw new \Exception($msg ?: 'No se pudo desactivar la licencia.');
            }

            $dominio->estatus = 'NO';
            $dominio->save();
        });

    } catch (\Throwable $e) {
        return back()->withError('No se pudo desactivar: ' . $e->getMessage());
    }

    return back()->withSuccess('Licencia desactivada para el dominio.');
}


// backlinks
    public function generarBacklinks($dominio, int $detalle): RedirectResponse
    {
        dd('SI LLEGÓ AL CONTROLADOR', $dominio, $detalle);
        $it = Dominios_Contenido_DetallesModel::findOrFail($detalle);

        // solo si ya está publicado y tiene URL
        if ($it->estatus !== 'publicado' || empty($it->wp_link)) {
            return back()->with('error', 'Primero debes publicar en WordPress (y tener wp_link).');
        }

        // evitar duplicados
        if (($it->estatus_backlinks ?? '') === 'en_proceso') {
            return back()->with('error', 'Ya hay backlinks en proceso para este contenido.');
        }

        $it->estatus_backlinks = 'en_proceso';
        $it->error_backlinks = null;
        $it->save();

        \App\Jobs\GenerarBacklinksContenidoJob::dispatch($it->id_dominio_contenido_detalle);

        return back()->with('exito', 'Backlinks en proceso.');
    }   

    public function CargarPlantillas()
    {
        $usuario = auth()->user();
      

        

      

        return view('Dominios.CargarPlantillas', compact('usuario'));
    }


     public function GuardarPlantilla(Request $request)
    {
         $request->validate([
            'archivo' => ['required', 'file', 'extensions:json', 'max:5120'],
        ]);

        // 1) Leer JSON subido
        $raw = json_decode(file_get_contents($request->file('archivo')->getRealPath()), true);
        if (!is_array($raw)) {
            return back()->with('error', 'El archivo subido no es un JSON válido.');
        }
    
        // 2) Cargar plantilla base tokenizada (colócala en: storage/app/templates/elementor-10.json)
        $basePath = storage_path('app/elementor/elementor-10.json');
   
        if (!file_exists($basePath)) {
            return back()->with('error', 'Falta la plantilla base tokenizada en storage/app/elementor/elementor-10.json');
        }

        $tmpl = json_decode(file_get_contents($basePath), true);
        if (!is_array($tmpl)) {
            return back()->with('error', 'La plantilla base tokenizada no es un JSON válido.');
        }

        // 3) Tokenizar por "espejo"
        $tokens = [];
        $tokenizado = $this->applyTokensByMirror($raw, $tmpl, $tokens);

  $outName = 'elementor_token_' . date('Ymd_His') . '_' . uniqid() . '.json';
$dir = storage_path('app/elementor');

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$fullPath = $dir . DIRECTORY_SEPARATOR . $outName;

file_put_contents(
    $fullPath,
    json_encode($tokenizado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

      return redirect("cargarplantillas")
   ->withSuccess('Plantilla guardada exitosamente en: ' . $fullPath);

    }
    private function applyTokensByMirror($raw, $tmpl, array &$tokens)
    {
        // Si ambos son arrays: recursivo
        if (is_array($raw) && is_array($tmpl)) {
            // lista
            if (array_is_list($raw) && array_is_list($tmpl)) {
                $len = min(count($raw), count($tmpl));
                $out = $raw;
                for ($i = 0; $i < $len; $i++) {
                    $out[$i] = $this->applyTokensByMirror($raw[$i], $tmpl[$i], $tokens);
                }
                return $out;
            }

            // asociativo
            $out = $raw;
            foreach ($raw as $k => $v) {
                if (array_key_exists($k, $tmpl)) {
                    $out[$k] = $this->applyTokensByMirror($v, $tmpl[$k], $tokens);
                }
            }
            return $out;
        }

        // Si en la plantilla hay un token puro, reemplaza el string raw por el token
        if (is_string($tmpl) && $this->looksLikeToken($tmpl) && is_string($raw)) {
            $tokens[] = $tmpl;
            return $tmpl;
        }

        // Si el token está dentro de HTML (ej: "<p>{{CONT_1}}</p>")
        if (is_string($tmpl) && $this->containsToken($tmpl) && is_string($raw)) {
            preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $tmpl, $m);
            foreach ($m[0] ?? [] as $t) $tokens[] = $t;
            return $tmpl;
        }

        return $raw;
    }

    private function looksLikeToken(string $s): bool
    {
        return (bool) preg_match('/^\{\{[A-Z0-9_]+\}\}$/', trim($s));
    }

    private function containsToken(string $s): bool
    {
        return (bool) preg_match('/\{\{[A-Z0-9_]+\}\}/', $s);
    }
}



 