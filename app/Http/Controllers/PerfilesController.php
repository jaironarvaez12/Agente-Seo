<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PerfilModel;

use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Models\ScheduledInstagramPost;
use App\Models\ScheduledFacebookPost;
use App\Jobs\PublishToInstagramJob;
use Carbon\Carbon;
class PerfilesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
          $perfiles = PerfilModel::all();
  
       
        return view('Perfiles.Perfil',compact('perfiles'));
    }
     private function api(string $path): string
    {
        return "https://graph.facebook.com/v20.0/{$path}";
    }
    private function maskTok(?string $t): string 
    {
        if (!$t) return '[null]';
        $len = strlen($t);
        return substr($t,0,6).'…'.substr($t,-6).' (len:'.$len.')';
    }
        /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
         return view('Perfiles.PerfilCreate');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $r)
    {
        $r->validate([
            'nombre'             => 'required|string|max:255',
            'fb_page_id'         => 'required|string',
            'id_instagram'       => 'required|string',
            'fb_page_name'       => 'nullable|string',
            'fb_system_user_token' => 'required|string', // obligatorio
            'fb_page_token'      => 'nullable|string', // opcional
        ]);

        $perfil = PerfilModel::create([
            'nombre'              => $r->nombre,
            'fb_page_id'          => $r->fb_page_id,
            'fb_page_name'        => $r->fb_page_name,
            'fb_system_user_token'=> $r->fb_system_user_token, // global
            'fb_page_token'       => $r->fb_page_token, // puede quedar vacío
            'ig_business_id'      => $r->id_instagram, 
        ]);

        // 🔄 Sincronizar token inmediatamente después de crear el perfil
        $error = null;
        if (!$this->syncPerfilToken($perfil, $error)) {
            return redirect()
                ->route('perfiles.index')
                ->with('warning', 'Perfil creado, pero NO se pudo sincronizar el token: '.$error);
        }

        return redirect()
            ->route('perfiles.index')
            ->with('success', 'Perfil creado y token sincronizado correctamente.');
    }
    private function syncPerfilToken(PerfilModel $perfil, &$error = null): bool
    {
        if (!$perfil) {
            $error = 'Perfil no encontrado.';
            return false;
        }

        $sys = $perfil->fb_system_user_token;   // <-- SIEMPRE este para /me/accounts
        if (!$sys) {
            $error = 'Falta fb_system_user_token en el perfil.';
            return false;
        }

        Log::info('[FB] sync: PAGE_ID='.$perfil->fb_page_id.' sys='.$this->maskTok($sys).' pageTok='.$this->maskTok($perfil->fb_page_token));

        // (Opcional) Verificar tipo de token con /debug_token si tienes APP_ID/SECRET
        $appId     = env('FB_APP_ID');
        $appSecret = env('FB_APP_SECRET');

        if ($appId && $appSecret) {
            $appTok   = $appId.'|'.$appSecret;
            $debugUrl = $this->api('debug_token')
                        .'?input_token='.urlencode($sys)
                        .'&access_token='.urlencode($appTok);

            $debug = Http::get($debugUrl)->json();

            if (isset($debug['error'])) {
                $error = '/debug_token error: '.json_encode($debug['error']);
                return false;
            }

            $type = strtoupper((string) data_get($debug, 'data.type'));
            Log::info('[FB] /debug_token type='.$type);

            if ($type === 'PAGE') {
                $error = 'fb_system_user_token es de TIPO PAGE. Debe ser el token del System User (regénéralo y guárdalo en fb_system_user_token).';
                return false;
            }
        }

        // FORZAR el uso del System User token en la URL (sin arrays que puedan mezclar campos)
        $url = $this->api('me/accounts')
            .'?access_token='.urlencode($sys)
            .'&fields='.urlencode('id,name,access_token,tasks');

        $resp = Http::get($url)->json();

        if (isset($resp['error'])) {
            // Si esto vuelve con #100, SEGURO se envió un token de página.
            $error = 'Error en /me/accounts con System User token: '.json_encode($resp['error']);
            return false;
        }

        $pages = collect($resp['data'] ?? []);
        $page  = $pages->firstWhere('id', (string) $perfil->fb_page_id);

        if (!$page || empty($page['access_token'])) {
            $ids   = $pages->pluck('id')->implode(',');
            $error = "No se encontró la PAGE_ID {$perfil->fb_page_id} en /me/accounts. IDs visibles: [{$ids}]. Asegura que la Página esté asignada como activo al System User con permisos de publicación.";
            return false;
        }

        // Guardar el TOKEN DE LA PÁGINA (para usar luego en /{PAGE_ID}/feed)
        $perfil->update([
            'fb_page_token' => $page['access_token'],
        ]);

        Log::info('[FB] Page token actualizado: '.$this->maskTok($perfil->fresh()->fb_page_token));

        return true;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }



    private function sanitizeFilename(string $originalName, string $defaultExt = 'jpg'): string
    {
        $name = pathinfo($originalName, PATHINFO_FILENAME) ?: 'image';
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: $defaultExt);

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $safe  = Str::slug($ascii); // “diseño ux.png” -> “diseno-ux.png”

        return $safe.'-'.Str::random(6).'.'.$ext;
    }

    public function listFacebookPosts(\App\Models\PerfilModel $perfil)
    {
        // 1) Validaciones y token (solo para "publicadas")
        abort_if(!$perfil->fb_page_id, 400, 'Falta fb_page_id');

        // 2) ¿Solo programadas (pendientes)?
        $scheduledRaw  = request()->query('scheduled', request()->input('scheduled', null));
        $showScheduled = ($scheduledRaw === '1' || $scheduledRaw === 1 || $scheduledRaw === true || $scheduledRaw === 'true');

        // 3) Si piden PROGRAMADAS (pendientes), usamos TU BD como fuente de verdad y NO llamamos a Graph
        if ($showScheduled) {
            $appTz = config('app.timezone', 'UTC');

            // Pendientes: status=queued y fecha futura (damos 60s de margen)
            $rows = \App\Models\ScheduledFacebookPost::query()
                ->where('perfil_id', $perfil->id)
                ->where('status', 'queued')
                ->where('scheduled_at', '>', \Carbon\Carbon::now($appTz)->subSeconds(60))
                ->orderBy('scheduled_at', 'asc')
                ->limit(50)
                ->get();

            // Normalizamos a la misma "shape" que la vista espera
            $items = [];
            foreach ($rows as $row) {
                // Miniatura tentativa: si el primer source es URL, úsala; si es file, intenta asset('storage/...')
                $thumb = null;
                $sources = (array)($row->image_sources ?? []);
                if (!empty($sources)) {
                    $first = $sources[0];
                    $type  = $first['type'] ?? null;
                    $val   = $first['value'] ?? null;
                    if ($type === 'url' && is_string($val)) {
                        $thumb = $val;
                    } elseif ($type === 'file' && is_string($val)) {
                        // esto puede o no ser público; si no lo es, igual la vista lo tolera
                        $thumb = asset('storage/'.$val);
                    }
                }

                $items[] = [
                    'id'                      => 'local-'.$row->id,
                    'message'                 => (string)($row->message ?? ''),
                    'scheduled_publish_time'  => optional($row->scheduled_at)->timezone('UTC')->toIso8601String(),
                    'created_time'            => null,
                    'is_published'            => false,
                    'permalink_url'           => null,
                    'full_picture'            => $thumb,
                    'attachments'             => ['data' => []],
                    'status_type'             => 'SCHEDULED',
                ];
            }

            // Sin cursores cuando viene de BD
            $next = null;
            $prev = null;

        return view('Perfiles.PerfilPublicaciones', compact('perfil', 'items', 'next', 'prev', 'showScheduled'));
    }

    // 4) Si NO piden programadas → PUBLICADAS desde Graph
    $pageToken = $perfil->fb_page_token ?: $perfil->fb_system_user_token;
    abort_if(!$pageToken, 400, 'Falta token de página o token del System User');

    $after  = request('after');
    $before = request('before');

    $fields = implode(',', [
        'id',
        'message',
        'created_time',
        'scheduled_publish_time',
        'is_published',
        'permalink_url',
        'full_picture',
        'attachments{media_type,media,url,subattachments}',
        'status_type',
    ]);

    $params = [
        'fields'       => $fields,
        'limit'        => 12,
        'access_token' => $pageToken,
    ];
    if (!empty($after) && empty($before))  $params['after']  = $after;
    if (!empty($before) && empty($after))  $params['before'] = $before;

    $edge = "{$perfil->fb_page_id}/posts";

    $resp = \Illuminate\Support\Facades\Http::withHeaders([
        'Cache-Control' => 'no-cache, no-store, must-revalidate',
        'Pragma'        => 'no-cache',
        'Expires'       => '0',
    ])->get($this->api($edge), $params);

    if (!$resp->ok()) {
        \Illuminate\Support\Facades\Log::error('[FB list] '.$resp->body());
        return back()->with('error', 'Facebook list: '.$resp->body());
    }

    $data  = $resp->json();
    $raw   = $data['data'] ?? [];
    // Por seguridad: asegura que en "publicadas" mostramos is_published=true
    $items = array_values(array_filter($raw, fn($it) => (bool)($it['is_published'] ?? true)));
    $next  = $data['paging']['cursors']['after']  ?? null;
    $prev  = $data['paging']['cursors']['before'] ?? null;

    return view('Perfiles.PerfilPublicaciones', compact('perfil', 'items', 'next', 'prev', 'showScheduled'));
}


 public function listInstagramMedia(\App\Models\PerfilModel $perfil)
{
    // ✅ Validaciones "bonitas"
    if (!$perfil->fb_page_token) {
        return back()->with('error', 'Este perfil aún no tiene conexión con Facebook (falta fb_page_token). Conéctalo primero.');
    }

    if (!$perfil->ig_business_id) {
        return back()->with('error', 'Este perfil no tiene Instagram Business vinculado (falta ig_business_id). Vincula IG primero.');
    }

    $after = request('after'); // ?after=XYZ

    $params = [
        'fields'       => 'id,media_type,media_url,thumbnail_url,permalink,caption,timestamp,children{id,media_type,media_url,thumbnail_url,permalink}',
        'limit'        => 12,
        'access_token' => $perfil->fb_page_token,
    ];

    if ($after) $params['after'] = $after;

    $resp = Http::get($this->api("{$perfil->ig_business_id}/media"), $params);

    if (!$resp->ok()) {
        \Log::error('[IG list] '.$resp->body());

        $msg = $resp->json('error.message') ?? $resp->body();

        // Extra: mensajes comunes más “humanos”
        if (str_contains($msg, 'Permissions error')) {
            $msg .= ' (Parece que al token le faltan permisos de Instagram. Re-conecta el perfil con permisos IG).';
        }

        return back()->with('error', 'Instagram list error: '.$msg);
    }

    $data = $resp->json();

    return view('Perfiles.PerfilPublicacionesInsta', [
        'perfil' => $perfil,
        'items'  => $data['data'] ?? [],
        'next'   => data_get($data, 'paging.cursors.after'),
        'prev'   => data_get($data, 'paging.cursors.before'),
    ]);
}




     public function publishForm(PerfilModel $perfil)
    {
        return view('Perfiles.PerfilCrearPublicacion', compact('perfil'));
    }






   public function publish(\Illuminate\Http\Request $r, \App\Models\PerfilModel $perfil)
{
    // 0) Validación
    $r->validate([
        'message'      => 'nullable|string|max:2000',
        'images.*'     => 'nullable|image|mimes:jpg,jpeg,png|max:8192',
        'image_urls.*' => 'nullable|url',
    ]);
    abort_if(!$perfil->fb_page_id, 400, 'Perfil no configurado (falta PAGE_ID).');

    $message = (string) $r->input('message', '');

    // Archivos y URLs de entrada
    $filesInput = $r->file('images');
    $files = is_array($filesInput) ? $filesInput : ($filesInput ? [$filesInput] : []);
    $urlInputs = array_values(array_filter((array) $r->input('image_urls', [])));

    // 1) Page Access Token (Facebook)
    $pageToken = null;
   abort_if(!$perfil->fb_page_id, 400, 'Perfil no configurado (falta PAGE_ID).');

    // ✅ Token de Facebook Page (nuevo flujo)
    $pageToken = trim((string) $perfil->fb_page_token);
    if ($pageToken === '') {
        return back()->with('error', 'Este perfil no tiene fb_page_token. Conecta Facebook primero.');
    }

    // ✅ Token para IG (nuevo flujo: usa el mismo page token)
    $igAccessToken = $pageToken;

    // 1.b) Token IG (solo System User)
    $igAccessToken = trim((string) $perfil->fb_system_user_token);

    // 2) Procesar imágenes → public/ig_out
    $igUrls = [];
    $saveProcessed = function (string $src) {
        $name = $this->sanitizeFilename('ig.jpg', 'jpg');
        $dir  = public_path('ig_out');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        $img = \Intervention\Image\Laravel\Facades\Image::read($src);
        if (method_exists($img, 'orientate')) { $img->orientate(); }
        $img->cover(1080, 1080);
        $bytes = $img->toJpeg(85)->toString();

        file_put_contents($dir.'/'.$name, $bytes);
        $url = asset('ig_out/'.$name);

        // Sonda rápida para IG (evita 9004)
        $probe = \Illuminate\Support\Facades\Http::withHeaders([
            'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        ])->timeout(10)->head($url);

        if (!$probe->ok() || stripos((string)$probe->header('Content-Type',''), 'image/jpeg') !== 0) {
            throw new \RuntimeException('URL pública no accesible o sin image/jpeg ('.$probe->status().')');
        }
        return $url;
    };

    // Archivos subidos
    foreach ($files as $file) {
        try {
            $igUrls[] = $saveProcessed($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo procesar la imagen: '.$e->getMessage());
        }
    }

    // URLs remotas
    foreach ($urlInputs as $u) {
        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(20)->get($u);
            if (!$resp->ok()) throw new \RuntimeException('HTTP '.$resp->status());
            $igUrls[] = $saveProcessed($resp->body());
        } catch (\Throwable $e) {
            return back()->with('error', "No se pudo preparar la URL de imagen: $u");
        }
    }

    $totalImgs = count($igUrls);

    // 3) Publicar en Facebook
    try {
        if ($totalImgs === 0) {
            if ($message === '') return back()->with('error', 'Debes enviar un mensaje o al menos una imagen.');
            $fb = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), [
                'message'      => $message,
                'access_token' => $pageToken,
            ]);
            if (!$fb->ok()) throw new \RuntimeException($fb->body());
            $fbMsg = 'FB: publicación de texto creada.';
        } elseif ($totalImgs === 1) {
            $fb = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/photos"), [
                'caption'      => $message,
                'url'          => $igUrls[0],
                'published'    => true,
                'access_token' => $pageToken,
            ]);
            if (!$fb->ok()) throw new \RuntimeException($fb->body());
            $fbMsg = 'FB: publicación con 1 imagen creada.';
        } else {
            $mediaFbids = [];
            foreach ($igUrls as $u) {
                $up = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/photos"), [
                    'url'          => $u,
                    'published'    => false,
                    'access_token' => $pageToken,
                ]);
                if (!$up->ok()) throw new \RuntimeException($up->body());
                $mediaFbids[] = $up->json('id');
            }
            $attached = [];
            foreach ($mediaFbids as $i => $fbid) {
                $attached["attached_media[$i]"] = json_encode(['media_fbid' => $fbid]);
            }
            $payload = array_merge($attached, [
                'message'      => $message,
                'access_token' => $pageToken,
            ]);
            $fb = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), $payload);
            if (!$fb->ok()) throw new \RuntimeException($fb->body());
            $fbMsg = 'FB: carrusel creado.';
        }
    } catch (\Throwable $e) {
        return back()->with('error', 'Facebook: '.$e->getMessage());
    }

    // 4) Publicar en Instagram (opcional si hay token y cuenta)
    $igMsg = null;
    if (!empty($perfil->ig_business_id) && $igAccessToken !== '' && $totalImgs > 0) {
        try {
            if ($totalImgs === 1) {
                // /media con params en la URL + retry corto en /media_publish
                $createUrl = $this->api("{$perfil->ig_business_id}/media")
                    . '?image_url=' . urlencode(trim($igUrls[0]))
                    . '&caption='   . urlencode($message)
                    . '&access_token=' . urlencode($igAccessToken);

                $media = \Illuminate\Support\Facades\Http::withOptions(['http_errors' => false])->post($createUrl);
                if (!$media->ok() || $media->json('error')) throw new \RuntimeException($media->body());
                $creationId = $media->json('id');

                $attempts = 5; $ok = false;
                for ($i = 0; $i < $attempts; $i++) {
                    sleep(2);
                    $publishUrl = $this->api("{$perfil->ig_business_id}/media_publish")
                        . '?creation_id=' . urlencode($creationId)
                        . '&access_token=' . urlencode($igAccessToken);
                    $pub = \Illuminate\Support\Facades\Http::withOptions(['http_errors' => false])->post($publishUrl);
                    $err = $pub->json('error');
                    if ($pub->ok() && !$err) { $ok = true; break; }
                    if (($err['code'] ?? null) != 9007 && ($err['error_subcode'] ?? null) != 2207027) {
                        throw new \RuntimeException($pub->body());
                    }
                }
                if (!$ok) throw new \RuntimeException('El media no estuvo listo para publicar.');

                $igMsg = 'IG: publicación creada.';
            } else {
                // Carrusel IG
                $children = [];
                foreach ($igUrls as $u) {
                    $child = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->ig_business_id}/media"), [
                        'image_url'        => $u,
                        'is_carousel_item' => true,
                        'access_token'     => $igAccessToken,
                    ]);
                    if (!$child->ok()) throw new \RuntimeException($child->body());
                    $children[] = $child->json('id');
                }
                $parent = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->ig_business_id}/media"), [
                    'caption'      => $message,
                    'children'     => implode(',', $children),
                    'media_type'   => 'CAROUSEL',
                    'access_token' => $igAccessToken,
                ]);
                if (!$parent->ok()) throw new \RuntimeException($parent->body());

                $creationId = $parent->json('id');

                // Espera corta a FINISHED
                for ($i = 0; $i < 10; $i++) {
                    sleep(1);
                    $st = \Illuminate\Support\Facades\Http::get($this->api("{$creationId}"), [
                        'fields'       => 'status_code',
                        'access_token' => $igAccessToken,
                    ]);
                    if ($st->ok() && $st->json('status_code') === 'FINISHED') break;
                }

                $pub = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->ig_business_id}/media_publish"), [
                    'creation_id'  => $creationId,
                    'access_token' => $igAccessToken,
                ]);
                if (!$pub->ok()) throw new \RuntimeException($pub->body());

                $igMsg = 'IG: carrusel publicado.';
            }
        } catch (\Throwable $e) {
            $igMsg = 'IG: error (ver logs).';
        }
    }

    $finalMsg = trim(($fbMsg ?? '') . ' ' . ($igMsg ?? ''));
    return back()->with('success', $finalMsg ?: 'Publicación creada.');
}



    public function Programar(PerfilModel $perfil)
    {
        return view('Perfiles.PerfilProgramar', compact('perfil'));
    }


public function schedule(Request $r, PerfilModel $perfil)
{
    if ($r->has('posts')) {
        return $this->scheduleMultiple($r, $perfil);
    }
    return $this->scheduleSingle($r, $perfil); // <-- tu método original
}

// ====== Tu método original (sin cambios funcionales) ======
protected function scheduleSingle(Request $r, PerfilModel $perfil)
{
    // 0) Validación básica de contenido y archivos + fecha/hora
    $r->validate([
        'message'       => 'nullable|string|max:2000',
        'images.*'      => 'nullable|image|mimes:jpg,jpeg,png|max:8192',
        'image_urls.*'  => 'nullable|url',
        'schedule_date' => 'required|date_format:Y-m-d',
        'schedule_time' => 'required|date_format:H:i',
    ]);
    abort_if(!$perfil->fb_page_id, 400, 'Perfil no configurado (falta PAGE_ID).');

    // 0.a) Epoch o TZ
    $appTz = config('app.timezone', 'UTC');
    $epoch = (int) $r->input('scheduled_epoch', 0);

    if ($epoch > 0) {
        $nowUtc = \Carbon\Carbon::now('UTC')->timestamp;
        $ahead  = $epoch - $nowUtc;

        if ($ahead < 600) {
            return back()->with('error', 'La fecha/hora debe ser al menos 10 minutos en el futuro.');
        }
        if ($epoch > \Carbon\Carbon::now('UTC')->addMonths(6)->timestamp) {
            return back()->with('error', 'La fecha/hora no puede exceder 6 meses en el futuro.');
        }

        if (method_exists(\Carbon\Carbon::class, 'createFromTimestampUTC')) {
            $scheduledAt = \Carbon\Carbon::createFromTimestampUTC($epoch)->setTimezone($appTz);
        } else {
            $scheduledAt = \Carbon\Carbon::createFromTimestamp($epoch, 'UTC')->setTimezone($appTz);
        }
        $ts = (int) $epoch;
    } else {
        $clientTz = (string) $r->input('client_tz', 'UTC');
        try {
            $scheduledLocal = \Carbon\Carbon::createFromFormat(
                'Y-m-d H:i',
                $r->schedule_date.' '.$r->schedule_time,
                $clientTz
            );
            $scheduledAt = $scheduledLocal->clone()->setTimezone($appTz);
        } catch (\Throwable $e) {
            return back()->with('error', 'Fecha u hora inválidas.');
        }

        $now = \Carbon\Carbon::now($appTz)->startOfMinute();
        $aheadSeconds = $now->diffInSeconds($scheduledAt, false);
        if ($aheadSeconds < 600) {
            return back()->with('error', 'La fecha/hora debe ser al menos 10 minutos en el futuro.');
        }
        if ($scheduledAt->greaterThan($now->copy()->addMonths(6))) {
            return back()->with('error', 'La fecha/hora no puede exceder 6 meses en el futuro.');
        }
        $ts = $scheduledAt->clone()->timezone('UTC')->timestamp;
    }

    $message = (string) $r->input('message', '');

    // 1) Page Access Token
    $pageToken = null;
    if (!empty($perfil->fb_system_user_token)) {
        $tokRes = Http::get($this->api("{$perfil->fb_page_id}"), [
            'fields'       => 'access_token',
            'access_token' => $perfil->fb_system_user_token,
        ]);
        if ($tokRes->ok() && ($tok = $tokRes->json('access_token'))) {
            $pageToken = $tok;
            try { $perfil->update(['fb_page_token' => $pageToken]); } catch (\Throwable $e) {}
        } else {
            return back()->with('error', 'No pude obtener el Page Access Token. Resp: '.$tokRes->body());
        }
    } elseif (!empty($perfil->fb_page_token)) {
        $pageToken = $perfil->fb_page_token;
    } else {
        return back()->with('error', 'No hay token de Página ni token de System User configurado.');
    }

    // 2) Procesar imágenes (genera URL pública en ig_out)
    $filesInput = $r->file('images');
    $files      = is_array($filesInput) ? $filesInput : ($filesInput ? [$filesInput] : []);
    $urlInputs  = array_values(array_filter((array) $r->input('image_urls', [])));

    $saveProcessed = function (string $src) {
        $name = $this->sanitizeFilename('ig.jpg', 'jpg');
        $dir  = public_path('ig_out');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        $img = \Intervention\Image\Laravel\Facades\Image::read($src);
        if (method_exists($img, 'orientate')) { $img->orientate(); }
        $img->cover(1080, 1080);
        $bytes = $img->toJpeg(85)->toString();

        file_put_contents($dir.'/'.$name, $bytes);
        $url = asset('ig_out/'.$name);

        $probe = Http::withHeaders([
            'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        ])->timeout(10)->head($url);
        if (!$probe->ok() || stripos((string)$probe->header('Content-Type',''), 'image/jpeg') !== 0) {
            throw new \RuntimeException('URL pública no accesible o sin image/jpeg ('.$probe->status().')');
        }
        return $url;
    };

    $igUrls = [];
    foreach ($files as $file) {
        try {
            $igUrls[] = $saveProcessed($file->getRealPath());
        } catch (\Throwable $e) {
            return back()->with('error', 'No se pudo procesar la imagen: '.$e->getMessage());
        }
    }
    foreach ($urlInputs as $u) {
        try {
            $resp = Http::timeout(20)->get($u);
            if (!$resp->ok()) throw new \RuntimeException('HTTP '.$resp->status());
            $igUrls[] = $saveProcessed($resp->body());
        } catch (\Throwable $e) {
            return back()->with('error', "No se pudo preparar la URL de imagen: $u");
        }
    }
    $totalImgs = count($igUrls);

    // 3) Programar en Facebook
    $fbMsg = '';
    try {
        if ($totalImgs === 0) {
            if ($message === '') return back()->with('error', 'Debes enviar un mensaje o al menos una imagen.');
            $fb = Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), [
                'message'                => $message,
                'published'              => false,
                'scheduled_publish_time' => $ts,
                'access_token'           => $pageToken,
            ]);
            if (!$fb->ok()) throw new \RuntimeException($fb->body());
            $fbMsg = 'FB: publicación de texto programada.';
        } else {
            $mediaFbids = [];
            foreach ($igUrls as $u) {
                $up = Http::asForm()->post($this->api("{$perfil->fb_page_id}/photos"), [
                    'url'          => $u,
                    'published'    => false,
                    'access_token' => $pageToken,
                ]);
                if (!$up->ok()) throw new \RuntimeException($up->body());
                $mediaFbids[] = $up->json('id');
            }
            $attached = [];
            foreach ($mediaFbids as $i => $fbid) {
                $attached["attached_media[$i]"] = json_encode(['media_fbid' => $fbid]);
            }
            $payload = array_merge($attached, [
                'message'                => $message,
                'published'              => false,
                'scheduled_publish_time' => $ts,
                'access_token'           => $pageToken,
            ]);
            $fb = Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), $payload);
            if (!$fb->ok()) throw new \RuntimeException($fb->body());
            $fbMsg = $totalImgs === 1
                ? 'FB: publicación con 1 imagen programada.'
                : 'FB: carrusel programado.';
        }
    } catch (\Throwable $e) {
        return back()->with('error', 'Facebook: '.$e->getMessage());
    }

    // 4) Programar IG mediante Job (usa URLs públicas)
    $igQueueMsg = 'IG: no se programó (sin IG o sin imágenes).';
    if (!empty($perfil->ig_business_id) && trim((string)$perfil->fb_system_user_token) !== '' && count($igUrls) > 0) {
        try {
            $igScheduled = ScheduledInstagramPost::create([
                'perfil_id'    => $perfil->id,
                'message'      => $message,
                'image_urls'   => $igUrls, // <-- públicas
                'scheduled_at' => $scheduledAt,
                'status'       => 'pending',
            ]);

            PublishToInstagramJob::dispatch($igScheduled->id)->delay($scheduledAt);

            $igQueueMsg = sprintf(
                'IG: job #%d encolado para %s (estado inicial: pending).',
                $igScheduled->id,
                $scheduledAt->format('Y-m-d H:i')
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'IG Queue: '.$e->getMessage());
        }
    }

    // 5) Advertencia de colas
    $warning = null;
    if (config('queue.default') === 'sync' || config('queue.connection') === 'sync') {
        $warning = 'Atención: QUEUE_CONNECTION=sync. El Job se ejecutará inmediatamente y no quedará realmente en cola. '
                 . 'Configura QUEUE_CONNECTION=database y corre php artisan queue:work para programación real.';
    }

    // 6) Respuesta
    $redirect = back()->with('success', trim($fbMsg.' | '.$igQueueMsg));
    if ($warning) {
        $redirect->with('warning', $warning);
    }
    return $redirect;
}

// ====== MODO MÚLTIPLE (CORREGIDO: IG guarda URLs públicas) ======
protected function scheduleMultiple(Request $r, PerfilModel $perfil)
{
    $r->validate([
        'posts'                        => 'required|array|min:1',
        'posts.*.message'              => 'nullable|string|max:2000',
        'posts.*.images'               => 'nullable|array',
        'posts.*.images.*'             => 'nullable|image|mimes:jpg,jpeg,png|max:8192',
        'posts.*.image_urls'           => 'nullable|array',
        'posts.*.image_urls.*'         => 'nullable|url',
        'posts.*.schedule_date'        => 'required_without:posts.*.scheduled_epoch|date_format:Y-m-d',
        'posts.*.schedule_time'        => 'required_without:posts.*.scheduled_epoch|date_format:H:i',
        'posts.*.scheduled_epoch'      => 'nullable|integer',
        'posts.*.client_tz'            => 'nullable|string',
    ]);

    abort_if(!$perfil->fb_page_id, 400, 'Perfil no configurado (falta PAGE_ID).');

    $appTz = config('app.timezone', 'UTC');
    $ok = 0; $fail = 0; $msgs = [];

    // mismo $saveProcessed que en single (genera URL pública 1080x1080)
    $saveProcessed = function (string $src) {
        $name = $this->sanitizeFilename('ig.jpg', 'jpg');
        $dir  = public_path('ig_out');
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

        $img = \Intervention\Image\Laravel\Facades\Image::read($src);
        if (method_exists($img, 'orientate')) { $img->orientate(); }
        $img->cover(1080, 1080);
        $bytes = $img->toJpeg(85)->toString();

        file_put_contents($dir.'/'.$name, $bytes);
        $url = asset('ig_out/'.$name);

        $probe = Http::withHeaders([
            'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        ])->timeout(10)->head($url);
        if (!$probe->ok() || stripos((string)$probe->header('Content-Type',''), 'image/jpeg') !== 0) {
            throw new \RuntimeException('URL pública no accesible o sin image/jpeg ('.$probe->status().')');
        }
        return $url;
    };

    foreach ((array)$r->input('posts', []) as $i => $post) {
        try {
            // 1) schedule
            [$scheduledAt, $epoch] = $this->resolveScheduleForPost($r, $i, $appTz);

            // 2) fuentes para FB job (rutas/urls crudas para que el job procese)
            $sources = [];
            $filesInput = $r->file("posts.$i.images");
            $files      = is_array($filesInput) ? $filesInput : ($filesInput ? [$filesInput] : []);
            foreach ($files as $file) {
                $stored = $file->store("facebook_queue/{$perfil->id}", 'public');
                $sources[] = ['type' => 'file', 'value' => $stored];
            }
            $urlInputs = array_values(array_filter((array)($post['image_urls'] ?? [])));
            foreach ($urlInputs as $u) {
                $sources[] = ['type' => 'url', 'value' => $u];
            }

            // === IG: construir URLs públicas finales ===
            $igPublicUrls = [];
            foreach ($files as $file) {
                $igPublicUrls[] = $saveProcessed($file->getRealPath());
            }
            foreach ($urlInputs as $u) {
                $resp = Http::timeout(20)->get($u);
                if (!$resp->ok()) throw new \RuntimeException("No se pudo descargar $u (HTTP ".$resp->status().")");
                $igPublicUrls[] = $saveProcessed($resp->body());
            }

            // 3) Crear registro y encolar job inmediato de FB (programa en FB)
            $row = ScheduledFacebookPost::create([
                'perfil_id'       => $perfil->id,
                'message'         => (string)($post['message'] ?? ''),
                'image_sources'   => $sources,
                'scheduled_at'    => $scheduledAt,
                'scheduled_epoch' => $epoch,
                'client_tz'       => (string)($post['client_tz'] ?? ''),
                'status'          => 'queued',
            ]);

            \App\Jobs\CreateFacebookScheduledPostJob::dispatch($row->id);

            // 4) IG (usa SOLO las públicas)
            if (!empty($perfil->ig_business_id) && trim((string)$perfil->fb_system_user_token) !== '' && count($igPublicUrls) > 0) {
                $igRow = ScheduledInstagramPost::create([
                    'perfil_id'    => $perfil->id,
                    'message'      => (string)($post['message'] ?? ''),
                    'image_urls'   => $igPublicUrls, // <-- públicas
                    'scheduled_at' => $scheduledAt,
                    'status'       => 'pending',
                ]);
                PublishToInstagramJob::dispatch($igRow->id)->delay($scheduledAt);
                $msgs[] = "Post #".($i+1).": IG job #{$igRow->id} encolado para ".$scheduledAt->format('Y-m-d H:i');
            } else {
                $msgs[] = "Post #".($i+1).": IG no programado (sin IG o sin imágenes).";
            }

            $ok++;
            $msgs[] = "Post #".($i+1).": FB programar → encolado (aparecerá programado en Facebook).";
        } catch (\Throwable $e) {
            $fail++;
            $msgs[] = "Post #".($i+1).": ERROR → ".$e->getMessage();
        }
    }

    $warn = (config('queue.default') === 'sync' || config('queue.connection') === 'sync')
        ? 'Atención: QUEUE_CONNECTION=sync. Usa database/redis + queue:work para respuesta realmente inmediata.'
        : null;

    return back()->with([
        'success' => $ok ? "Publicaciones encoladas: $ok" : null,
        'error'   => $fail ? "Con error: $fail" : null,
        'info'    => implode("\n", $msgs),
        'warning' => $warn,
    ]);
}

protected function resolveScheduleForPost(Request $r, int $index, string $appTz): array
{
    $epoch = (int) $r->input("posts.$index.scheduled_epoch", 0);

    if ($epoch > 0) {
        $nowUtc = Carbon::now('UTC')->timestamp;
        if (($epoch - $nowUtc) < 600) {
            throw new \RuntimeException('La fecha/hora debe ser al menos 10 minutos en el futuro.');
        }
        if ($epoch > Carbon::now('UTC')->addMonths(6)->timestamp) {
            throw new \RuntimeException('La fecha/hora no puede exceder 6 meses en el futuro.');
        }
        $scheduledAt = method_exists(Carbon::class, 'createFromTimestampUTC')
            ? Carbon::createFromTimestampUTC($epoch)->setTimezone($appTz)
            : Carbon::createFromTimestamp($epoch, 'UTC')->setTimezone($appTz);

        return [$scheduledAt, (int)$epoch];
    }

    $clientTz = (string) $r->input("posts.$index.client_tz", 'UTC');
    $date     = (string) $r->input("posts.$index.schedule_date");
    $time     = (string) $r->input("posts.$index.schedule_time");

    try {
        $scheduledLocal = Carbon::createFromFormat('Y-m-d H:i', "$date $time", $clientTz);
        $scheduledAt    = $scheduledLocal->clone()->setTimezone($appTz);
    } catch (\Throwable $e) {
        throw new \RuntimeException('Fecha u hora inválidas.');
    }

    $now = Carbon::now($appTz)->startOfMinute();
    $aheadSeconds = $now->diffInSeconds($scheduledAt, false);
    if ($aheadSeconds < 600) {
        throw new \RuntimeException('La fecha/hora debe ser al menos 10 minutos en el futuro.');
    }
    if ($scheduledAt->greaterThan($now->copy()->addMonths(6))) {
        throw new \RuntimeException('La fecha/hora no puede exceder 6 meses en el futuro.');
    }

    $ts = $scheduledAt->clone()->timezone('UTC')->timestamp;
    return [$scheduledAt, $ts];
}

public function generarTextoIA(Request $request, PerfilModel $perfil)
{
    $validated = $request->validate([
        'prompt' => ['required', 'string', 'max:2000'],
    ]);

    $prompt = $validated['prompt'];

    $webhookUrl = (string) env('N8N_WEBHOOK_URL', '');

    Log::info('Llamando a n8n', [
        'url' => $webhookUrl,
        'perfil_id' => $perfil->id,
    ]);

    if ($webhookUrl === '') {
        return response()->json([
            'message' => 'Configura N8N_WEBHOOK_URL en .env',
        ], 500);
    }

    try {
        $response = Http::timeout(30)->post($webhookUrl, [
            'prompt'    => $prompt,
            'page_name' => $perfil->fb_page_name ?? $perfil->nombre,
            'perfil_id' => $perfil->id,
        ]);

        Log::info('Respuesta de n8n', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Error al comunicarse con el servicio de IA.',
            ], 500);
        }

        $data = $response->json();

        $textoGenerado = $data['texto'] ?? $data['descripcion'] ?? $data['descripcionMejorada'] ?? null;

        if (!$textoGenerado) {
            return response()->json([
                'message' => 'La IA no devolvió un texto válido.',
            ], 500);
        }

        return response()->json([
            'texto' => $textoGenerado,
        ]);
    } catch (\Throwable $e) {
        Log::error('Error llamando a n8n', [
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'message' => 'No se pudo generar el texto con IA.',
        ], 500);
    }
}


public function facebookRedirect(Request $request)
{
    $request->validate([
        'nombre' => 'required|string|max:255',
        'descripcion' => 'nullable|string|max:500',
    ]);

    // Guardar datos del formulario temporalmente
    session([
        'perfil_tmp' => [
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
        ],
        'fb_oauth_state' => csrf_token(),
    ]);

    $clientId    = env('FACEBOOK_APP_ID', '1002802425345267');
    $redirectUri = route('perfiles.facebook.callback'); // IMPORTANTÍSIMO que coincida con Meta
    $apiVersion  = env('FACEBOOK_API_VERSION', 'v21.0');

    // ✅ Permisos para: listar páginas + publicar en Facebook Page
    $scopes = [
        'public_profile',
        'email',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
    ];

    $query = http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'state'         => session('fb_oauth_state'),
        'scope'         => implode(',', $scopes),
        'response_type' => 'code',
    ]);

    return redirect("https://www.facebook.com/{$apiVersion}/dialog/oauth?{$query}");
}

public function facebookCallback(Request $request)
{
    // Si el usuario cancela, Facebook manda error
    if ($request->has('error')) {
        return redirect()->route('perfiles.create')
            ->with('error', 'Facebook canceló o devolvió error: '.$request->input('error_description', $request->input('error')));
    }

    if (!$request->has('code')) {
        return redirect()->route('perfiles.create')->with('error', 'No llegó el code de Facebook.');
    }

    // Validar state
    $expectedState = session('fb_oauth_state');
    if (!$expectedState || $request->input('state') !== $expectedState) {
        return redirect()->route('perfiles.create')->with('error', 'State inválido o sesión expirada. Intenta de nuevo.');
    }

    $tmp = session('perfil_tmp');
    if (!$tmp) {
        return redirect()->route('perfiles.create')->with('error', 'Sesión expirada. Vuelve a crear el perfil.');
    }

    $code         = $request->input('code');
    $clientId     = env('FACEBOOK_APP_ID', '1002802425345267');
    $clientSecret = env('FACEBOOK_APP_SECRET');
    $redirectUri  = route('perfiles.facebook.callback');
    $apiVersion   = env('FACEBOOK_API_VERSION', 'v21.0');

    // 1) code -> user access token
    $tokenResp = Http::get("https://graph.facebook.com/{$apiVersion}/oauth/access_token", [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'code'          => $code,
    ])->json();

    if (!isset($tokenResp['access_token'])) {
        return redirect()->route('perfiles.create')->with('error', 'No se pudo obtener el token: '.json_encode($tokenResp));
    }

    $userToken = $tokenResp['access_token'];

    // 2) páginas con page token
    $accounts = Http::get("https://graph.facebook.com/{$apiVersion}/me/accounts", [
        'access_token' => $userToken,
        'fields'       => 'id,name,access_token',
        'limit'        => 100,
    ])->json();

    $pages = $accounts['data'] ?? [];

    if (count($pages) === 0) {
        return redirect()->route('perfiles.create')
            ->with('error', 'No se encontraron páginas administradas por esta cuenta.');
    }

    // Guardar páginas en sesión para selección
    session(['fb_pages_tmp' => $pages]);

    // Si solo hay una página, guardar directo
    if (count($pages) === 1) {
        $request->merge(['page_id' => $pages[0]['id']]);
        return $this->facebookSelectPage($request);
    }

    return view('Perfiles.PerfilSeleccionar', compact('pages'));
}

public function facebookSelectPage(Request $request)
{
    $request->validate([
        'page_id' => 'required|string',
    ]);

    $tmp   = session('perfil_tmp');
    $pages = session('fb_pages_tmp', []);

    if (!$tmp || empty($pages)) {
        return redirect()->route('perfiles.create')->with('error', 'Sesión expirada. Vuelve a conectar.');
    }

    $page = collect($pages)->firstWhere('id', $request->input('page_id'));
    if (!$page || empty($page['access_token'])) {
        return redirect()->route('perfiles.create')->with('error', 'Página inválida o sin token.');
    }

    $pageId    = $page['id'];
    $pageName  = $page['name'];
    $pageToken = $page['access_token'];
    $apiVersion = env('FACEBOOK_API_VERSION', 'v21.0');

    // 3) Intentar obtener IG business id (si existe)
    $igResp = Http::get("https://graph.facebook.com/{$apiVersion}/{$pageId}", [
        'access_token' => $pageToken,
        'fields'       => 'instagram_business_account',
    ])->json();

    $igBusinessId = $igResp['instagram_business_account']['id'] ?? null;

    // 4) Guardar en TU tabla (tu estructura)
    $perfil = PerfilModel::updateOrCreate(
        ['fb_page_id' => $pageId],
        [
            'nombre'                  => $tmp['nombre'],
            'descripcion'             => $tmp['descripcion'] ?? null,
            'fb_page_name'            => $pageName,
            'fb_page_token'           => $pageToken,
            'fb_system_user_token'    => null,
            'fb_page_token_expires_at'=> null,
            'status'                  => 1,
            'ig_business_id'          => $igBusinessId,
        ]
    );

    // limpiar sesión
    session()->forget(['perfil_tmp', 'fb_pages_tmp', 'fb_oauth_state']);

    return redirect()->route('perfiles.index')->with('success', 'Perfil conectado: '.$perfil->nombre);
}



public function fbTestRedirect()
{
    $clientId    = '1002802425345267';
    $redirectUri = url('/fb-callback-test');
    $apiVersion  = 'v21.0';

    $scopes = [
        'public_profile',
        'email',

        // PÁGINAS
        'pages_show_list',
        'pages_manage_posts',
        'pages_read_engagement',
    ];

    $query = http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'state'         => csrf_token(),
        'scope'         => implode(',', $scopes),
        'response_type' => 'code',
    ]);

    return redirect("https://www.facebook.com/{$apiVersion}/dialog/oauth?{$query}");
}

public function fbTestCallback(Request $request)
{
    if (!$request->has('code')) {
        dd('No llegó el code', $request->all());
    }

    $code         = $request->code;
    $clientId     = '1002802425345267';
    $clientSecret = env('FACEBOOK_APP_SECRET');
    $redirectUri  = url('/fb-callback-test');
    $apiVersion   = 'v21.0';

    // 1️⃣ code → user access token
    $tokenResponse = Http::get("https://graph.facebook.com/{$apiVersion}/oauth/access_token", [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'code'          => $code,
    ]);

    $tokenData = $tokenResponse->json();

    if (!isset($tokenData['access_token'])) {
        dd('Error obteniendo token', $tokenData);
    }

    $accessToken = $tokenData['access_token'];
    
    $perms = Http::get("https://graph.facebook.com/v21.0/me/permissions", [
    'access_token' => $accessToken,
    ])->json();

    dd($perms);

    // 2️⃣ perfil del usuario
    $me = Http::get("https://graph.facebook.com/{$apiVersion}/me", [
        'access_token' => $accessToken,
        'fields'       => 'id,name,email',
    ])->json();

    // 3️⃣ páginas + page access token (ESTE token es el que publica)
    $accounts = Http::get("https://graph.facebook.com/{$apiVersion}/me/accounts", [
        'access_token' => $accessToken,
        'fields'       => 'id,name,access_token',
        'limit'        => 100,
    ])->json();
    $page = $accounts['data'][0] ?? null;

    if (!$page || empty($page['access_token'])) {
        dd('No hay página o token de página', $accounts);
    }

    $pageId    = $page['id'];
    $pageToken = $page['access_token'];

    $postResp = Http::post("https://graph.facebook.com/v21.0/{$pageId}/feed", [
        'message'      => 'Post de prueba desde Laravel 🚀',
        'access_token' => $pageToken,
    ]);

    dd([
        'page' => $page,
        'post_response' => $postResp->json(),
    ]);
}

}
