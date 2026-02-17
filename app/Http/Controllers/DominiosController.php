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
        'nombre'  => ['required','string','max:80'],
        'archivo' => ['required','file','max:5120'],
    ]);

    $rawStr = file_get_contents($request->file('archivo')->getRealPath());
    $raw = json_decode($rawStr, true);

    if (!is_array($raw)) {
        return redirect("cargarplantillas")
            ->withErrors(['archivo' => 'El archivo no es un JSON válido.']);
    }

    // 1) Detectar widgets especiales (NO bloquear, solo no tokenizar dentro)
    $widgetTypes = $this->scanWidgetTypes($raw);
    $unsupportedSet = $this->getUnsupportedWidgetSet($widgetTypes);

    // 2) Contar SOLO lo tokenizable
    $need = $this->countTokenizableFieldsSmart($raw, $unsupportedSet);

    $max = 70;
    if ($need > $max) {
        return redirect("cargarplantillas")->withErrors([
            'archivo' => "Plantilla demasiado larga. Campos tokenizables: $need. Máximo permitido: $max."
        ]);
    }

    // 3) Tokenizar
    $usedTokens = [];
    $tokenizado = $this->tokenizeTemplateSmart($raw, $unsupportedSet, $usedTokens);

    // 4) Guardar con nombre del usuario (sanitizado)
    $nombre = trim((string)$request->input('nombre'));
    $slugNombre = Str::slug($nombre);
    if ($slugNombre === '') $slugNombre = 'plantilla';

    $outName = 'plantilla_' . $slugNombre . '.json';

    $dir = storage_path('app/elementor');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . $outName,
        json_encode($tokenizado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    return redirect("cargarplantillas")
        ->withSuccess("Plantilla '{$nombre}' guardada en: storage/app/elementor/$outName (tokens usados: " . count($usedTokens) . ")");
}



private function tokenizeTemplateSmart(array $json, array $unsupportedSet, array &$usedTokens): array
{
    $usedTokens = [];

    // contadores separados para no mezclar TITLE/P
    $secTitleN = 1;
    $secPN     = 1;
    $btnN      = 1;

    // 2 botones “fijos” (primero que aparezcan)
    $heroBtnUsed = false;
    $packBtnUsed = false;

    $nextSectionTitleToken = function(bool $short) use (&$secTitleN, &$usedTokens) {
        $tok = $short
            ? '{{SECTION_'.$secTitleN.'_TITLE_SHORT}}'
            : '{{SECTION_'.$secTitleN.'_TITLE}}';
        $usedTokens[] = $tok;
        $secTitleN++;
        return $tok;
    };

    $nextSectionPToken = function(bool $short) use (&$secPN, &$usedTokens) {
        $tok = $short
            ? '{{SECTION_'.$secPN.'_P_SHORT}}'
            : '{{SECTION_'.$secPN.'_P}}';
        $usedTokens[] = $tok;
        $secPN++;
        return $tok;
    };

    $nextBtnTextToken = function() use (&$btnN, &$usedTokens, &$heroBtnUsed, &$packBtnUsed) {
        if (!$heroBtnUsed) {
            $heroBtnUsed = true;
            $usedTokens[] = '{{HERO_BTN_TEXT}}';
            return '{{HERO_BTN_TEXT}}';
        }
        if (!$packBtnUsed) {
            $packBtnUsed = true;
            $usedTokens[] = '{{PACK_BTN_TEXT}}';
            return '{{PACK_BTN_TEXT}}';
        }
        $tok = '{{BTN_'.$btnN.'_TEXT}}';
        $usedTokens[] = $tok;
        $btnN++;
        return $tok;
    };

    $wrapIfHtml = function(string $original, string $token): string {
        // si el original tenía HTML, mantenlo simple para Elementor
        if (preg_match('/<[^>]+>/', $original)) return '<p>'.$token.'</p>';
        return $token;
    };

    $walk = function($node) use (&$walk, $unsupportedSet, $wrapIfHtml, $nextSectionTitleToken, $nextSectionPToken, $nextBtnTextToken) {
        if (!is_array($node)) return $node;

        if (($node['elType'] ?? null) === 'widget') {
            $wt = strtolower((string)($node['widgetType'] ?? ''));

            // ✅ Widget especial => NO tokenizar settings (copiar tal cual)
            if ($wt !== '' && isset($unsupportedSet[$wt])) {
                // igual recorremos hijos por seguridad (normalmente widget no tiene)
                foreach (['elements','content'] as $k) {
                    if (!empty($node[$k]) && is_array($node[$k])) {
                        foreach ($node[$k] as $i => $child) $node[$k][$i] = $walk($child);
                    }
                }
                return $node;
            }

            $settings = $node['settings'] ?? [];
            if (is_array($settings)) {

                // Heading -> TITLE token
                if ($wt === 'heading' && !empty($settings['title']) && is_string($settings['title'])) {
                    $orig = $settings['title'];

                    // ❌ no tokenizar si es número puro
                    if (!$this->isNumericOnly($orig)) {
                        $short = $this->isShortMicrocopy($orig, 'heading', 'title');
                        $tok   = $nextSectionTitleToken($short);
                        $settings['title'] = $wrapIfHtml($orig, $tok);
                    }
                }

                // Text editor -> P token
                if ($wt === 'text-editor' && !empty($settings['editor']) && is_string($settings['editor'])) {
                    $orig = $settings['editor'];

                    if (!$this->isNumericOnly($orig)) {
                        $short = $this->isShortMicrocopy($orig, 'text-editor', 'editor');
                        $tok   = $nextSectionPToken($short);
                        $settings['editor'] = $wrapIfHtml($orig, $tok);
                    }
                }

                // Button -> tokenizar SOLO texto, NO URL
                if ($wt === 'button') {
                    if (!empty($settings['text']) && is_string($settings['text'])) {
                        $orig = $settings['text'];
                        if (!$this->isNumericOnly($orig)) {
                            $tok = $nextBtnTextToken();
                            $settings['text'] = $wrapIfHtml($orig, $tok);
                        }
                    } elseif (!empty($settings['button_text']) && is_string($settings['button_text'])) {
                        $orig = $settings['button_text'];
                        if (!$this->isNumericOnly($orig)) {
                            $tok = $nextBtnTextToken();
                            $settings['button_text'] = $wrapIfHtml($orig, $tok);
                        }
                    }

                    // ✅ NO tocar link.url
                }

                $node['settings'] = $settings;
            }
        }

        // recorrer hijos (secciones/columnas)
        foreach (['elements','content'] as $k) {
            if (!empty($node[$k]) && is_array($node[$k])) {
                foreach ($node[$k] as $i => $child) $node[$k][$i] = $walk($child);
            }
        }

        return $node;
    };

    return $walk($json);
}


private function wrapIfHtml(string $original, string $token): string
{
    // Si parece HTML, lo dejamos en <p>{{TOKEN}}</p> (como venías haciendo)
    if (preg_match('/<[^>]+>/', $original)) return '<p>' . $token . '</p>';
    return $token;
}


private function shouldTokenizeText(string $value, string $kind = 'p'): bool
{
    $t = trim(strip_tags($value));
    if ($t === '') return false;

    // números (incluye 01, 1.5, 1,000, 10%)
    if ($this->isNumericLike($t)) return false;

    return true;
}

private function isNumericLike(string $t): bool
{
    $t = trim($t);

    // "01" o "1" o "123"
    if (preg_match('/^\d+$/', $t)) return true;

    // "1.5" "1,5" "1,000" "1.000" "10%" "10 %"
    if (preg_match('/^\d+(?:[.,]\d+)*\s*%?$/', $t)) return true;

    // "01." "02)" etc (muy típico en steps)
    if (preg_match('/^\d+\s*[\.\)]$/', $t)) return true;

    return false;
}

/**
 * Cuenta tokenizable real (por tipo), SIN tocar links.url y SIN números.
 */
private function countTokenizableFieldsSmart(array $json, array $unsupportedSet): int
{
    $count = 0;

    $walk = function($node) use (&$walk, &$count, $unsupportedSet) {
        if (!is_array($node)) return;

        if (($node['elType'] ?? null) === 'widget') {
            $wt = strtolower((string)($node['widgetType'] ?? ''));

            // widget especial => no tokenizar dentro
            if ($wt !== '' && isset($unsupportedSet[$wt])) {
                return;
            }

            $settings = $node['settings'] ?? [];
            if (is_array($settings)) {

                if ($wt === 'heading' && !empty($settings['title']) && is_string($settings['title'])) {
                    if (!$this->isNumericOnly($settings['title'])) $count++;
                }

                if ($wt === 'text-editor' && !empty($settings['editor']) && is_string($settings['editor'])) {
                    if (!$this->isNumericOnly($settings['editor'])) $count++;
                }

                if ($wt === 'button') {
                    $btnText = null;
                    if (!empty($settings['text']) && is_string($settings['text'])) $btnText = $settings['text'];
                    elseif (!empty($settings['button_text']) && is_string($settings['button_text'])) $btnText = $settings['button_text'];

                    if (is_string($btnText) && $btnText !== '' && !$this->isNumericOnly($btnText)) {
                        $count++;
                    }

                    // ❌ NO contamos URL
                }
            }
        }

        foreach (['elements','content'] as $k) {
            if (!empty($node[$k]) && is_array($node[$k])) {
                foreach ($node[$k] as $child) $walk($child);
            }
        }
    };

    $walk($json);
    return $count;
}



private function plainText(string $s): string
{
    $s = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    $s = preg_replace('~\s+~u', ' ', $s);
    return trim((string)$s);
}

private function isNumericOnly(string $s): bool
{
    $t = $this->plainText($s);
    if ($t === '') return false;

    // "01", "1", "2026", "3.14", "1,5"
    return (bool)preg_match('~^\d+(?:[.,]\d+)?$~u', $t);
}

private function wordCount(string $s): int
{
    $t = $this->plainText($s);
    if ($t === '') return 0;
    $w = preg_split('~\s+~u', $t, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($w) ? count($w) : 0;
}

private function isShortMicrocopy(string $original, string $widgetType, string $fieldKey): bool
{
    $len = mb_strlen($this->plainText($original));
    if ($len === 0) return false;

    $wt = strtolower($widgetType);

    // Heading micro (labels)
    if ($wt === 'heading' && $fieldKey === 'title') {
        if ($len <= 18) return true;
        if ($this->wordCount($original) <= 3) return true;
        return false;
    }

    // Text editor micro (badges, numeritos, labels)
    if ($wt === 'text-editor' && $fieldKey === 'editor') {
        if ($len <= 55) return true;
        $plain = $this->plainText($original);
        if ($len <= 75 && !preg_match('~[.!?]~u', $plain)) return true;
        return false;
    }

    return false;
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








    private function extractAllowedTokensFromBase(string $basePath): array
{
    if (!file_exists($basePath)) return [];

    $base = json_decode(file_get_contents($basePath), true);
    if (!is_array($base)) return [];

    $tokens = [];

    $walk = function($node) use (&$walk, &$tokens) {
        if (is_array($node)) {
            foreach ($node as $v) $walk($v);
            return;
        }
        if (is_string($node)) {
            if (preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $node, $m)) {
                foreach ($m[0] as $t) $tokens[$t] = true;
            }
        }
    };

    $walk($base);

    // Orden estable (alfabético)
    $list = array_keys($tokens);
    sort($list);

    return $list;
}


private function validateNoSpecialPlugins(array $widgetTypes): array
{
    // Permite solo widgets “core” típicos (ajústalo a tu gusto)
    $allowedCore = [
        'heading','text-editor','image','button','divider','spacer','icon','icon-list',
        'image-box','icon-box','tabs','accordion','toggle','video','html','shortcode',
        'menu-anchor','google_maps','social-icons','counter','testimonial',
    ];

    // Bloquea (Pro / Woo / etc.)
    $blockedExact = [
        'form','posts','portfolio','nav-menu','login','search-form',
        'woocommerce-products','woocommerce-product','woocommerce-add-to-cart',
        'woocommerce-cart','woocommerce-checkout','woocommerce-menu-cart',
    ];

    // Bloquea addons por prefijo
    $blockedPrefixes = [
        'jet-','uael-','premium-','bdt-','theplus-','elementskit-','qi-','ae-'
    ];

    $notAllowed = [];

    foreach ($widgetTypes as $wt) {
        $w = strtolower($wt);

        if (in_array($w, $blockedExact, true)) {
            $notAllowed[] = $wt;
            continue;
        }
        foreach ($blockedPrefixes as $p) {
            if (str_starts_with($w, $p)) {
                $notAllowed[] = $wt;
                continue 2;
            }
        }

        // si quieres ser estricto: bloquea lo desconocido
        if (!in_array($w, $allowedCore, true)) {
            $notAllowed[] = $wt;
        }
    }

    return array_values(array_unique($notAllowed));
}
private function countTokenizableFields(array $json, array $unsupportedSet): int
{
    $count = 0;

    $walk = function($node) use (&$walk, &$count, $unsupportedSet) {
        if (!is_array($node)) return;

        if (($node['elType'] ?? null) === 'widget') {
            $wt = strtolower((string)($node['widgetType'] ?? ''));

            // ✅ si es widget especial → lo ignoramos (no cuenta)
            if ($wt !== '' && isset($unsupportedSet[$wt])) {
                return;
            }

            $settings = $node['settings'] ?? [];
            if (is_array($settings)) {
                if ($wt === 'heading') {
                    if (!empty($settings['title']) && is_string($settings['title'])) $count++;
                }

                if ($wt === 'text-editor') {
                    if (!empty($settings['editor']) && is_string($settings['editor'])) $count++;
                }

                if ($wt === 'button') {
                    // texto
                    if (!empty($settings['text']) && is_string($settings['text']) && !$this->isNumericOnlyString($settings['text'])) {
                        $count++;
                    } elseif (!empty($settings['button_text']) && is_string($settings['button_text']) && !$this->isNumericOnlyString($settings['button_text'])) {
                        $count++;
                    }

                    // ❌ NO contar URL (se deja tal cual)
                    // if (!empty($settings['link']['url'])) $count++;  <-- QUITAR
                }
            }
        }

        foreach (['elements','content'] as $k) {
            if (!empty($node[$k]) && is_array($node[$k])) {
                foreach ($node[$k] as $child) $walk($child);
            }
        }
    };

    $walk($json);
    return $count;
}
private function tokenizeWithAllowedPool(
    array $json,
    array $allowedTokens,
    array $unsupportedSet,
    array &$usedTokens,
    array &$metaOut
): array

{
    $i = 0;
    $metaOut = [];
    $usedTokens = [];

    $nextToken = function() use (&$i, $allowedTokens, &$usedTokens) {
        if ($i >= count($allowedTokens)) return null;
        $t = $allowedTokens[$i];
        $i++;
        $usedTokens[] = $t;
        return $t;
    };

    $wrapIfHtml = function(string $original, string $token): string {
        if (preg_match('/<[^>]+>/', $original)) return '<p>'.$token.'</p>';
        return $token;
    };

    $walk = function($node) use (&$walk, $nextToken, $wrapIfHtml, $unsupportedSet) {
        if (!is_array($node)) return $node;

        if (($node['elType'] ?? null) === 'widget') {
            $wt = strtolower((string)($node['widgetType'] ?? ''));

            // ✅ Widget especial → copiar tal cual (no tocar settings)
            if ($wt !== '' && isset($unsupportedSet[$wt])) {
                // Igual recorre hijos si existieran (normalmente widgets no tienen, pero por seguridad)
                foreach (['elements','content'] as $k) {
                    if (!empty($node[$k]) && is_array($node[$k])) {
                        foreach ($node[$k] as $idx => $child) {
                            $node[$k][$idx] = $walk($child);
                        }
                    }
                }
                return $node;
            }

            $settings = $node['settings'] ?? [];

            if (is_array($settings)) {
                if ($wt === 'heading' && !empty($settings['title']) && is_string($settings['title'])) {

                    // 🚫 No tokenizar títulos numéricos tipo "01"
                    if (!$this->isNumericOnlyText($settings['title'])) {
                        $t = $nextToken();
                        if ($t) {
                            $hs = strtolower((string)($settings['header_size'] ?? 'h3'));
                            if (!in_array($hs, ['h1','h2','h3','h4','h5','h6'], true)) $hs = 'h3';

                            $settings['title'] = $wrapIfHtml($settings['title'], $t);

                            // meta del token (tag real)
                            $metaOut[$t] = [
                                'tag' => $hs,
                                'widget' => 'heading',
                                'field' => 'title',
                            ];
                        }
                    }
                }


               if ($wt === 'text-editor' && !empty($settings['editor']) && is_string($settings['editor'])) {

                    // 🚫 No tokenizar textos numéricos
                    if (!$this->isNumericOnlyText($settings['editor'])) {
                        $t = $nextToken();
                        if ($t) {
                            $settings['editor'] = $wrapIfHtml($settings['editor'], $t);

                            $metaOut[$t] = [
                                'tag' => 'p',
                                'widget' => 'text-editor',
                                'field' => 'editor',
                            ];
                        }
                    }
                }


                if ($wt === 'button') {

                    // Texto botón
                    if (!empty($settings['text']) && is_string($settings['text'])) {
                        if (!$this->isNumericOnlyText($settings['text'])) {
                            $t = $nextToken();
                            if ($t) {
                                $settings['text'] = $wrapIfHtml($settings['text'], $t);
                                $metaOut[$t] = ['tag'=>'span','widget'=>'button','field'=>'text'];
                            }
                        }
                    } elseif (!empty($settings['button_text']) && is_string($settings['button_text'])) {
                        if (!$this->isNumericOnlyText($settings['button_text'])) {
                            $t = $nextToken();
                            if ($t) {
                                $settings['button_text'] = $wrapIfHtml($settings['button_text'], $t);
                                $metaOut[$t] = ['tag'=>'span','widget'=>'button','field'=>'text'];
                            }
                        }
                    }

                    // URL botón: no tocar hashes
                    if (!empty($settings['link']) && is_array($settings['link']) && !empty($settings['link']['url']) && is_string($settings['link']['url'])) {
                        if (!$this->isAnchorOrHash($settings['link']['url'])) {
                            $t = $nextToken();
                            if ($t) {
                                $settings['link']['url'] = $t;
                                $metaOut[$t] = ['tag'=>'a','widget'=>'button','field'=>'url'];
                            }
                        }
                    } elseif (!empty($settings['link']) && is_string($settings['link'])) {
                        if (!$this->isAnchorOrHash($settings['link'])) {
                            $t = $nextToken();
                            if ($t) {
                                $settings['link'] = $t;
                                $metaOut[$t] = ['tag'=>'a','widget'=>'button','field'=>'url'];
                            }
                        }
                    }
                }


                $node['settings'] = $settings;
            }
        }

        // Recorrer hijos para secciones/columnas
        foreach (['elements','content'] as $k) {
            if (!empty($node[$k]) && is_array($node[$k])) {
                foreach ($node[$k] as $idx => $child) {
                    $node[$k][$idx] = $walk($child);
                }
            }
        }

        return $node;
    };

    return $walk($json);
}






private function buildTokenPoolMejorada(int $need): array
{
    $pool = [];

    // Base fija (normal)
    $fixed = [
        '{{HERO_H1}}', '{{HERO_P}}',
        '{{PACK_H2}}', '{{PACK_P}}',
        '{{HERO_BTN_TEXT}}', '{{HERO_BTN_URL}}',
        '{{PACK_BTN_TEXT}}', '{{PACK_BTN_URL}}',
    ];
    foreach ($fixed as $t) $pool[] = $t;

    // También soporta variantes SHORT para microcopy
    // (solo las que tienen sentido)
    $fixedShort = [
        '{{HERO_H1_SHORT}}', '{{HERO_P_SHORT}}',
        '{{PACK_H2_SHORT}}', '{{PACK_P_SHORT}}',
    ];
    foreach ($fixedShort as $t) $pool[] = $t;

    // Sections: Title/P normales + SHORT
    $n = 1;
    while (count($pool) < $need) {
        $pool[] = '{{SECTION_'.$n.'_TITLE}}';
        if (count($pool) >= $need) break;
        $pool[] = '{{SECTION_'.$n.'_TITLE_SHORT}}';
        if (count($pool) >= $need) break;

        $pool[] = '{{SECTION_'.$n.'_P}}';
        if (count($pool) >= $need) break;
        $pool[] = '{{SECTION_'.$n.'_P_SHORT}}';
        $n++;
    }

    return $pool;
} 



private function isNumericOnlyString(mixed $v): bool
{
    if (!is_string($v)) return false;
    $s = trim($v);
    if ($s === '') return false;
    return preg_match('~^-?\d+(?:\.\d+)?$~', $s) === 1;
}

private function isUrlToken(string $token): bool
{
    // {{HERO_BTN_URL}} etc
    return (bool) preg_match('~^\{\{[A-Z0-9_]+_URL\}\}$~', trim($token));
}

private function isNumericOnlyText(string $s): bool
{
    $t = trim(strip_tags($s));
    if ($t === '') return false;

    // "01", "1", "1.2", "1,2" (según tus plantillas)
    return (bool) preg_match('~^\d+(?:[.,]\d+)?$~', $t);
}

private function isAnchorOrHash(string $url): bool
{
    $u = trim($url);
    if ($u === '') return true;
    // #contacto, #solicitar-precio, etc.
    if (str_starts_with($u, '#')) return true;
    return false;
}

private function metaPathForTokenized(string $tokenizedFileName): string
{
    $dir = storage_path('app/elementor/meta');
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $base = preg_replace('~\.json$~i', '', $tokenizedFileName);
    return $dir . DIRECTORY_SEPARATOR . $base . '.meta.json';
}




private function scanWidgetTypes(array $json): array
    {
        $types = [];

        $walk = function ($node) use (&$walk, &$types) {
            if (!is_array($node)) return;

            if (($node['elType'] ?? null) === 'widget') {
                $wt = $node['widgetType'] ?? null;
                if (is_string($wt) && $wt !== '') $types[$wt] = true;
            }

            foreach (['elements', 'content'] as $k) {
                if (!empty($node[$k]) && is_array($node[$k])) {
                    foreach ($node[$k] as $child) $walk($child);
                }
            }
        };

        $walk($json);

        $list = array_keys($types);
        sort($list);
        return $list;
    }

    /**
     * Set de widgets "especiales" (requieren plugins/pro/addons) => NO tokenizar, copiar tal cual.
     */
    private function getUnsupportedWidgetSet(array $widgetTypes): array
    {
        // Core permitidos (ajusta a tu gusto)
        $allowedCore = [
            'heading', 'text-editor', 'image', 'button', 'divider', 'spacer', 'icon', 'icon-list',
            'image-box', 'icon-box', 'tabs', 'accordion', 'toggle', 'video', 'html', 'shortcode',
            'menu-anchor', 'google_maps', 'social-icons', 'counter', 'testimonial',
        ];

        // Exactos típicos Pro/Woo/etc.
        $blockedExact = [
            'form', 'posts', 'portfolio', 'nav-menu', 'login', 'search-form',
            'woocommerce-products', 'woocommerce-product', 'woocommerce-add-to-cart',
            'woocommerce-cart', 'woocommerce-checkout', 'woocommerce-menu-cart',
        ];

        // Prefijos addons
        $blockedPrefixes = [
            'jet-', 'uael-', 'premium-', 'bdt-', 'theplus-', 'elementskit-', 'qi-', 'ae-',
        ];

        $unsupported = [];

        foreach ($widgetTypes as $wt) {
            $w = strtolower((string)$wt);

            if (in_array($w, $blockedExact, true)) {
                $unsupported[$w] = true;
                continue;
            }

            foreach ($blockedPrefixes as $p) {
                if (str_starts_with($w, $p)) {
                    $unsupported[$w] = true;
                    continue 2;
                }
            }

            // Si quieres estricto: todo lo desconocido se considera especial
            if (!in_array($w, $allowedCore, true)) {
                $unsupported[$w] = true;
            }
        }

        return $unsupported; // set: ['form'=>true, 'jet-...'=>true...]
    }

    // ============================================================
    //  CONTEO DETALLADO (para pools separados)
    // ============================================================
    private function countTokenizableFieldsDetailed(array $json, array $unsupportedSet): array
    {
        $counts = [
            'hero_h1' => 0,
            'hero_p'  => 0,

            'titles_long'  => 0,  // h2/h3/h4/...
            'titles_short' => 0,  // p/span/div

            'editors' => 0,

            'btn_texts' => 0,
            'btn_urls'  => 0,

            'total' => 0,
        ];

        $seenHeroH1 = false;
        $seenHeroP  = false;

        $walk = function ($node) use (&$walk, &$counts, $unsupportedSet, &$seenHeroH1, &$seenHeroP) {
            if (!is_array($node)) return;

            if (($node['elType'] ?? null) === 'widget') {
                $wt = strtolower((string)($node['widgetType'] ?? ''));

                // ✅ widget especial → ignorar (no cuenta)
                if ($wt !== '' && isset($unsupportedSet[$wt])) {
                    return;
                }

                $settings = $node['settings'] ?? [];
                if (is_array($settings)) {

                    // HEADING
                    if ($wt === 'heading' && !empty($settings['title']) && is_string($settings['title'])) {
                        $plain = $this->plainText($settings['title']);

                        // ✅ numeraciones no
                        if (!$this->isNumericOnly($plain)) {
                            $tag = $this->getHeadingTag($settings);

                            if (!$seenHeroH1 && $tag === 'h1') {
                                $counts['hero_h1']++;
                                $seenHeroH1 = true;
                            } else {
                                if (in_array($tag, ['p', 'span', 'div'], true)) $counts['titles_short']++;
                                else $counts['titles_long']++;
                            }
                        }
                    }

                    // TEXT EDITOR
                    if ($wt === 'text-editor' && !empty($settings['editor']) && is_string($settings['editor'])) {
                        $plain = $this->plainText($settings['editor']);

                        // ✅ numeraciones no
                        if (!$this->isNumericOnly($plain)) {
                            if (!$seenHeroP) {
                                $counts['hero_p']++;
                                $seenHeroP = true;
                            } else {
                                $counts['editors']++;
                            }
                        }
                    }

                    // BUTTON
                    if ($wt === 'button') {
                        $txt = $settings['text'] ?? $settings['button_text'] ?? null;
                        if (is_string($txt) && trim($txt) !== '') {
                            $plain = $this->plainText($txt);
                            if (!$this->isNumericOnly($plain)) $counts['btn_texts']++;
                        }

                        $link = $settings['link'] ?? null;
                        if (is_array($link) && !empty($link['url']) && is_string($link['url'])) {
                            $counts['btn_urls']++;
                        } elseif (is_string($link) && trim($link) !== '') {
                            $counts['btn_urls']++;
                        }
                    }
                }
            }

            foreach (['elements', 'content'] as $k) {
                if (!empty($node[$k]) && is_array($node[$k])) {
                    foreach ($node[$k] as $child) $walk($child);
                }
            }
        };

        $walk($json);

        $counts['total'] =
            $counts['hero_h1'] + $counts['hero_p'] +
            $counts['titles_long'] + $counts['titles_short'] +
            $counts['editors'] + $counts['btn_texts'] + $counts['btn_urls'];

        return $counts;
    }

    // ============================================================
    //  POOLS SEPARADOS (NO se cruzan)
    // ============================================================
    private function buildTokenPoolsMejorada(array $counts): array
    {
        $pool = [
            'hero_h1' => ['{{HERO_H1}}'],
            'hero_p'  => ['{{HERO_P}}'],

            // Botones: primero tus fijos
            'btn_texts' => ['{{HERO_BTN_TEXT}}', '{{PACK_BTN_TEXT}}'],
            'btn_urls'  => ['{{HERO_BTN_URL}}',  '{{PACK_BTN_URL}}'],

            'titles_long'  => [],
            'titles_short' => [],
            'editors'      => [],
        ];

        // Completar BTN_TEXT_n y BTN_URL_n si hace falta
        while (count($pool['btn_texts']) < (int)($counts['btn_texts'] ?? 0)) {
            $pool['btn_texts'][] = '{{BTN_TEXT_' . (count($pool['btn_texts']) + 1) . '}}';
        }
        while (count($pool['btn_urls']) < (int)($counts['btn_urls'] ?? 0)) {
            $pool['btn_urls'][] = '{{BTN_URL_' . (count($pool['btn_urls']) + 1) . '}}';
        }

        // Titles largos (h2/h3/..)
        $nLong = 1;
        while (count($pool['titles_long']) < (int)($counts['titles_long'] ?? 0)) {
            $pool['titles_long'][] = '{{SECTION_' . $nLong . '_TITLE}}';
            $nLong++;
        }

        // Titles cortos (p/span/div) => un token distinto (para generar 1-3 palabras después)
        $nShort = 1;
        while (count($pool['titles_short']) < (int)($counts['titles_short'] ?? 0)) {
            $pool['titles_short'][] = '{{SECTION_SHORT_' . $nShort . '_TITLE}}';
            $nShort++;
        }

        // Text editors (párrafos)
        $nP = 1;
        while (count($pool['editors']) < (int)($counts['editors'] ?? 0)) {
            $pool['editors'][] = '{{SECTION_' . $nP . '_P}}';
            $nP++;
        }

        return $pool;
    }

    // ============================================================
    //  TOKENIZAR (respeta pools + números + especiales)
    // ============================================================
    private function tokenizeWithPools(array $json, array $pools, array $unsupportedSet, array &$usedTokens): array
    {
        $usedTokens = [];

        $idx = [
            'hero_h1' => 0,
            'hero_p'  => 0,
            'titles_long'  => 0,
            'titles_short' => 0,
            'editors' => 0,
            'btn_texts' => 0,
            'btn_urls'  => 0,
        ];

        $next = function (string $key) use (&$idx, $pools, &$usedTokens) {
            if (!isset($pools[$key])) return null;

            $i = (int)($idx[$key] ?? 0);
            if ($i >= count($pools[$key])) return null;

            $t = $pools[$key][$i];
            $idx[$key] = $i + 1;

            $usedTokens[] = $t;
            return $t;
        };

        $walk = function ($node) use (&$walk, $unsupportedSet, $next) {
            if (!is_array($node)) return $node;

            if (($node['elType'] ?? null) === 'widget') {
                $wt = strtolower((string)($node['widgetType'] ?? ''));

                // ✅ Widget especial → copiar tal cual
                if ($wt !== '' && isset($unsupportedSet[$wt])) {
                    return $node;
                }

                $settings = $node['settings'] ?? [];
                if (is_array($settings)) {

                    // ---------- HEADING ----------
                    if ($wt === 'heading' && !empty($settings['title']) && is_string($settings['title'])) {
                        $plain = $this->plainText($settings['title']);

                        // ✅ no tokenizar numeraciones
                        if (!$this->isNumericOnly($plain)) {
                            $tag = $this->getHeadingTag($settings);

                            // h1 => HERO_H1 (1 vez)
                            if ($tag === 'h1') {
                                $t = $next('hero_h1');
                                if ($t) $settings['title'] = $t; // ❗sin <p>
                            } else {
                                $bucket = in_array($tag, ['p', 'span', 'div'], true) ? 'titles_short' : 'titles_long';
                                $t = $next($bucket);
                                if ($t) $settings['title'] = $t; // ❗sin <p>
                            }
                        }
                    }

                    // ---------- TEXT EDITOR ----------
                    if ($wt === 'text-editor' && !empty($settings['editor']) && is_string($settings['editor'])) {
                        $plain = $this->plainText($settings['editor']);

                        if (!$this->isNumericOnly($plain)) {
                            // HERO_P: primer text-editor
                            $tHero = $next('hero_p');
                            if ($tHero) {
                                $settings['editor'] = '<p>' . $tHero . '</p>'; // ✅ HTML válido
                            } else {
                                $t = $next('editors');
                                if ($t) $settings['editor'] = '<p>' . $t . '</p>'; // ✅ HTML válido
                            }
                        }
                    }

                    // ---------- BUTTON ----------
                    if ($wt === 'button') {
                        // Texto
                        $textKey = null;
                        if (!empty($settings['text']) && is_string($settings['text'])) $textKey = 'text';
                        elseif (!empty($settings['button_text']) && is_string($settings['button_text'])) $textKey = 'button_text';

                        if ($textKey) {
                            $plain = $this->plainText($settings[$textKey]);
                            if (!$this->isNumericOnly($plain)) {
                                $t = $next('btn_texts');
                                if ($t) $settings[$textKey] = $t; // ❗sin <p>
                            }
                        }

                        // URL
                        if (!empty($settings['link']) && is_array($settings['link']) && !empty($settings['link']['url']) && is_string($settings['link']['url'])) {
                            $t = $next('btn_urls');
                            if ($t) $settings['link']['url'] = $t;
                        } elseif (!empty($settings['link']) && is_string($settings['link'])) {
                            $t = $next('btn_urls');
                            if ($t) $settings['link'] = $t;
                        }
                    }

                    $node['settings'] = $settings;
                }
            }

            // Recorrer hijos (sections/columns/etc.)
            foreach (['elements', 'content'] as $k) {
                if (!empty($node[$k]) && is_array($node[$k])) {
                    foreach ($node[$k] as $i => $child) {
                        $node[$k][$i] = $walk($child);
                    }
                }
            }

            return $node;
        };

        return $walk($json);
    }

    // ============================================================
    //  HELPERS
    // ============================================================
    private function getHeadingTag(array $settings): string
    {
        // Elementor usual: header_size = h1/h2/h3/p/span...
        $tag = strtolower((string)($settings['header_size'] ?? $settings['tag'] ?? ''));
        if ($tag === '') $tag = 'h2'; // fallback
        return $tag;
    }

   



    private function plainTextLen(string $s): int
{
    $s = trim((string)$s);
    if ($s === '') return 0;

    // quita tags y entidades típicas
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    $s = preg_replace('~\s+~u', ' ', $s);
    $s = trim($s);

    return mb_strlen($s);
}



}



 