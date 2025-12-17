<?php

namespace App\Http\Controllers;

use App\Models\PerfilModel;
use Illuminate\Http\Request;

use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
class FacebookController extends Controller
{
    private function maskTok(?string $t): string {
    if (!$t) return '[null]';
    $len = strlen($t);
    return substr($t,0,6).'…'.substr($t,-6).' (len:'.$len.')';
}

 private function api(string $path): string
{
    return "https://graph.facebook.com/v20.0/{$path}";
}
    private function sanitizeFilename(string $originalName, string $defaultExt = 'jpg'): string
{
    $name = pathinfo($originalName, PATHINFO_FILENAME) ?: 'image';
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: $defaultExt);

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
    $safe  = Str::slug($ascii); // “diseño ux.png” -> “diseno-ux.png”

    return $safe.'-'.Str::random(6).'.'.$ext;
}

	private function publicUrl(string $relativePath): string
{
    return \Storage::disk('public')->url($relativePath);
}
 

    /* ----- FORMULARIOS ----- */

    public function create()
    {
        return view('facebook.crear');
    }

    public function publishForm(PerfilModel $perfil)
    {
        return view('facebook.publicar', compact('perfil'));
    }

    public function scheduleForm(PerfilModel $perfil)
    {
        return view('facebook.programar', compact('perfil'));
    }

    /* ----- ACCIONES ----- */

    public function store(Request $r)
{
    $r->validate([
        'nombre' => 'required|string|max:255',
        'fb_page_id' => 'required|string',
        'fb_page_name' => 'nullable|string',
        'fb_system_user_token' => 'required|string', // obligatorio
        'fb_page_token' => 'nullable|string', // opcional
    ]);

    $perfil = PerfilModel::create([
        'nombre' => $r->nombre,
        'fb_page_id' => $r->fb_page_id,
        'fb_page_name' => $r->fb_page_name,
        'fb_system_user_token' => $r->fb_system_user_token, // global
        'fb_page_token' => $r->fb_page_token, // puede quedar vacío
    ]);

    // Redirigir a publicar
    return redirect()->route('facebook.publish.form', $perfil->id)
        ->with('success', 'Perfil creado correctamente.');
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

    // Normaliza entrada de archivos
    $filesInput = $r->file('images');
    $files = [];
    if ($filesInput instanceof \Illuminate\Http\UploadedFile) {
        $files = [$filesInput];
    } elseif (is_array($filesInput)) {
        $files = $filesInput;
    }
    $urlInputs = array_values(array_filter((array) $r->input('image_urls', [])));
    \Illuminate\Support\Facades\Log::info('[PUB] files='.count($files).' urlInputs='.count($urlInputs));

    // 1) Resolver Page Access Token (para Facebook)
    $pageToken = null;
    if (!empty($perfil->fb_system_user_token)) {
        $tokRes = \Illuminate\Support\Facades\Http::get($this->api("{$perfil->fb_page_id}"), [
            'fields'       => 'access_token,name,id',
            'access_token' => $perfil->fb_system_user_token,
        ]);
        if ($tokRes->ok() && ($tok = $tokRes->json('access_token'))) {
            $pageToken = $tok;
            try { $perfil->update(['fb_page_token' => $pageToken]); }
            catch (\Throwable $e) { \Illuminate\Support\Facades\Log::warning('[FB] Persistir page token: '.$e->getMessage()); }
        } else {
            \Illuminate\Support\Facades\Log::warning('[FB] No pude obtener Page Token: '.$tokRes->body());
            return back()->with('error',
                'No pude obtener el Page Access Token. Verifica asignación a la Página y permisos pages_manage_posts/pages_read_engagement. '.
                'Resp: '.$tokRes->body()
            );
        }
    } else {
        if (!empty($perfil->fb_page_token)) {
            $pageToken = $perfil->fb_page_token;
        } else {
            return back()->with('error', 'No hay token de Página ni token de System User configurado.');
        }
    }

    // 1.b) Token para IG -> SOLO System User (sin fallback al page token)
    $igAccessToken = trim((string)$perfil->fb_system_user_token);
    if ($igAccessToken === '') {
        \Illuminate\Support\Facades\Log::error('[IG] Falta fb_system_user_token para IG');
        // No abortamos toda la publicación: FB puede seguir
    } else {
        \Illuminate\Support\Facades\Log::info('[IG] token usado (hash)='.substr(hash('sha256', $igAccessToken), 0, 12));
    }

    // 2) Preparar imágenes normalizadas (Intervention Image v3)
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

    $url = asset('ig_out/'.$name); // https://social.ideidev.com/ig_out/...

    // Sonda con UA de Facebook para evitar 9004
    $probe = \Illuminate\Support\Facades\Http::withHeaders([
        'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    ])->timeout(15)->get($url);

    if (!$probe->ok() || stripos((string)$probe->header('Content-Type',''), 'image/jpeg') !== 0) {
        throw new \RuntimeException('URL pública no accesible o sin image/jpeg ('.$probe->status().')');
    }

    return $url;
};

    // Archivos subidos → 1080x1080 JPG
    foreach ($files as $file) {
        try {
            $igUrls[] = $saveProcessed($file->getRealPath());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[IG] Procesar archivo falló: '.$e->getMessage());
            return back()->with('error', 'No se pudo procesar la imagen subida para IG: '.$e->getMessage());
        }
    }

    // URLs → descargar binario y normalizar
    foreach ($urlInputs as $u) {
        try {
            $resp = \Illuminate\Support\Facades\Http::timeout(20)->get($u);
            if (!$resp->ok()) throw new \RuntimeException('HTTP '.$resp->status());
            $igUrls[] = $saveProcessed($resp->body());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[IG] No pude procesar URL ($u): ".$e->getMessage());
            return back()->with('error', "No se pudo preparar la URL de imagen para IG: $u");
        }
    }

    \Illuminate\Support\Facades\Log::info('[IG] igUrls='.json_encode($igUrls));
    $totalImgs = count($igUrls);

    // 3) FACEBOOK
    $fbMsg = null;
    try {
        if ($totalImgs === 0) {
            if ($message === '') {
                return back()->with('error', 'Debes enviar un mensaje o al menos una imagen.');
            }
            $fb = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), [
                'message'      => $message,
                'access_token' => $pageToken,
            ]);
            if (!$fb->ok()) {
                \Illuminate\Support\Facades\Log::error('[FB] text post error: '.$fb->body());
                return back()->with('error', 'Facebook: '.$fb->body());
            }
            $fbMsg = 'FB: publicación de texto creada.';
        } elseif ($totalImgs === 1) {
            // usa la URL procesada para FB
            $singleUrl = $igUrls[0] ?? null;
            if (!$singleUrl) return back()->with('error', 'No se pudo preparar la imagen para Facebook.');
            $fb = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/photos"), [
                'caption'      => $message,
                'url'          => $singleUrl,
                'published'    => true,
                'access_token' => $pageToken,
            ]);
            if (!$fb->ok()) {
                \Illuminate\Support\Facades\Log::error('[FB] single photo error: '.$fb->body());
                return back()->with('error', 'Facebook (1 imagen): '.$fb->body());
            }
            $fbMsg = 'FB: publicación con 1 imagen creada.';
        } else {
            // Carrusel FB: sube cada URL como unpublished, luego /feed con attached_media
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
            if (empty($mediaFbids)) {
                return back()->with('error', 'No se pudieron preparar imágenes para el carrusel de Facebook.');
            }
            $attached = [];
            foreach ($mediaFbids as $i => $fbid) {
                $attached["attached_media[{$i}]"] = json_encode(['media_fbid' => $fbid]);
            }
            $payload = array_merge($attached, [
                'message'      => $message,
                'access_token' => $pageToken,
            ]);
            $fb = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), $payload);
            if (!$fb->ok()) {
                \Illuminate\Support\Facades\Log::error('[FB] carousel error: '.$fb->body());
                return back()->with('error', 'Facebook (carrusel): '.$fb->body());
            }
            $fbMsg = 'FB: carrusel de imágenes creado.';
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('[FB] exception: '.$e->getMessage());
        return back()->with('error', 'Facebook error: '.$e->getMessage());
    }

    // 4) INSTAGRAM
    $igMsg = null;
    if (!empty($perfil->ig_business_id) && $igAccessToken !== '') {
        try {
            if ($totalImgs === 0) {
                $igMsg = 'IG: omitido (requiere imagen).';

            } elseif ($totalImgs === 1) {
                // === MODO POWERSHELL (POST con todo en la URL) + retry si 9007 ===
                $imageUrl = trim($igUrls[0]); // evita \n escondidos
                $igAccessToken = trim($igAccessToken);

                // 1) /media: POST sin body, params en querystring
                $createUrl = $this->api("{$perfil->ig_business_id}/media")
                    . '?image_url='    . urlencode($imageUrl)
                    . '&caption='      . urlencode($message)
                    . '&access_token=' . urlencode($igAccessToken);

                $media = \Illuminate\Support\Facades\Http::withOptions(['http_errors' => false])->post($createUrl);
                if (!$media->ok() || $media->json('error')) {
                    \Illuminate\Support\Facades\Log::error('[IG] media create error (url-mode): '.$media->body());
                    return back()->with('error', 'IG (crear media): '.$media->body());
                }
                $creationId = $media->json('id');
                if (!$creationId) {
                    return back()->with('error', 'IG: sin creation_id en /media.');
                }

                // 2) /media_publish con reintentos cortos si “Media ID is not available” (9007/2207027)
                $attempts = 5; $delay = 2; $pub = null;
                for ($i = 1; $i <= $attempts; $i++) {
                    $publishUrl = $this->api("{$perfil->ig_business_id}/media_publish")
                        . '?creation_id='  . urlencode($creationId)
                        . '&access_token=' . urlencode($igAccessToken);

                    $pub = \Illuminate\Support\Facades\Http::withOptions(['http_errors' => false])->post($publishUrl);

                    $err = $pub->json('error');
                    $code = $err['code'] ?? null;
                    $sub  = $err['error_subcode'] ?? null;

                    if ($pub->ok() && !$err) {
                        // Publicado ✅
                        $mediaId = $pub->json('id');
                        $perma = \Illuminate\Support\Facades\Http::get($this->api("{$mediaId}"), [
                            'fields'       => 'permalink,media_type,caption,timestamp',
                            'access_token' => $igAccessToken,
                        ]);
                        $permalink = $perma->json('permalink');
                        $igMsg = 'IG: publicación con 1 imagen creada.'
                               . ($permalink ? ' <a href="'.$permalink.'" target="_blank" rel="noopener">Ver en Instagram</a>' : '');
                        break;
                    }

                    // Si IG dice “no está listo” → espera y reintenta
                    if ($code == 9007 || $sub == 2207027) {
                        sleep($delay);
                        continue;
                    }

                    // Otro error → cortar
                    \Illuminate\Support\Facades\Log::error('[IG] media_publish error (url-mode): '.$pub->body());
                    return back()->with('error', 'IG (publicar): '.$pub->body());
                }

                if (!isset($igMsg)) {
                    // No lo logró tras reintentos
                    return back()->with('error', 'IG: el media no estuvo listo para publicar (intenta de nuevo).');
                }

            } elseif ($totalImgs > 1) {
                // Carrusel IG (todas ya normalizadas y validadas)
                $children = [];
                foreach ($igUrls as $u) {
                    $child = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->ig_business_id}/media"), [
                        'image_url'        => $u,
                        'is_carousel_item' => true,
                        'access_token'     => $igAccessToken,
                    ]);
                    if (!$child->ok()) {
                        \Illuminate\Support\Facades\Log::error('[IG] child error: '.$child->body());
                        return back()->with('error', 'IG (crear hijo carrusel): '.$child->body());
                    }
                    $children[] = $child->json('id');
                }

                $parent = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->ig_business_id}/media"), [
                    'caption'      => $message,
                    'children'     => implode(',', $children),
                    'media_type'   => 'CAROUSEL',
                    'access_token' => $igAccessToken,
                ]);
                if (!$parent->ok()) {
                    \Illuminate\Support\Facades\Log::error('[IG] parent error: '.$parent->body());
                    return back()->with('error', 'IG (crear padre carrusel): '.$parent->body());
                }

                $creationId = $parent->json('id');

                $finished = false;
                for ($i = 0; $i < 12; $i++) {
                    sleep(1);
                    $st = \Illuminate\Support\Facades\Http::get($this->api("{$creationId}"), [
                        'fields'       => 'status_code',
                        'access_token' => $igAccessToken,
                    ]);
                    if ($st->ok()) {
                        $code = $st->json('status_code');
                        if ($code === 'FINISHED') { $finished = true; break; }
                        if ($code === 'ERROR')    { break; }
                    }
                }
                if (!$finished) {
                    \Illuminate\Support\Facades\Log::error('[IG] parent status no FINISHED');
                    return back()->with('error', 'IG: el carrusel no llegó a FINISHED.');
                }

                $pub = \Illuminate\Support\Facades\Http::asForm()->post($this->api("{$perfil->ig_business_id}/media_publish"), [
                    'creation_id'  => $creationId,
                    'access_token' => $igAccessToken,
                ]);
                if (!$pub->ok()) {
                    \Illuminate\Support\Facades\Log::error('[IG] publish error: '.$pub->body());
                    return back()->with('error', 'IG (publicar carrusel): '.$pub->body());
                }

                $mediaId = $pub->json('id');
                $perma = \Illuminate\Support\Facades\Http::get($this->api("{$mediaId}"), [
                    'fields'       => 'permalink,media_type,caption,timestamp',
                    'access_token' => $igAccessToken,
                ]);
                if ($perma->ok() && ($permalink = $perma->json('permalink'))) {
                    $igMsg = 'IG: carrusel publicado. <a href="'.$permalink.'" target="_blank" rel="noopener">Ver en Instagram</a>';
                } else {
                    $igMsg = 'IG: carrusel publicado.';
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[IG] exception: '.$e->getMessage());
            $igMsg = 'IG: error (ver logs).';
        }
    }

    $finalMsg = trim(($fbMsg ?? '').' '.($igMsg ?? ''));
    return back()->with('success', $finalMsg === '' ? 'Publicación creada.' : $finalMsg);
}


    public function schedule(Request $r, PerfilModel $perfil)
    {
        $r->validate([
            'message' => 'required|string|max:2000',
            'scheduled_at' => 'required|date|after:now',
        ]);

        $ts = \Carbon\Carbon::parse($r->scheduled_at)->timestamp;
        $res = Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), [
            'message' => $r->message,
            'published' => false,
            'scheduled_publish_time' => $ts,
            'access_token' => $perfil->fb_page_token,
        ]);

        if (!$res->ok()) {
            return back()->with('error', 'Error: '.$res->body());
        }

        return back()->with('success', 'Publicación programada correctamente.');
    }

    public function probarToken(\App\Models\PerfilModel $perfil)
{
  // Usamos SIEMPRE el token global del System User
    $systemToken = $perfil->fb_system_user_token;

    if (!$systemToken) {
        return back()->with('error', 'No hay token del System User guardado.');
    }

    $response = Http::get('https://graph.facebook.com/v20.0/me/accounts', [
        'access_token' => $systemToken,
        'fields' => 'id,name,access_token,tasks',
    ])->json();

    // Si da error, mostralo tal cual
    if (isset($response['error'])) {
        return back()->with('error', 'Error en /me/accounts: ' . json_encode($response['error']));
    }

    $page = collect($response['data'] ?? [])->firstWhere('id', (string)$perfil->fb_page_id);

    if (!$page || empty($page['access_token'])) {
        return back()->with('error', 'No se encontró la PAGE_ID en la respuesta. Verifica que el System User tenga la página asignada en el Business Manager.');
    }

    // ✅ Guardamos el token de página real
    $perfil->update([
        'fb_page_token' => $page['access_token'],
    ]);

    return back()->with('success', '✅ Token de la página actualizado correctamente.');
}





 // --- FORMULARIO: muestra datos y permite pegar/actualizar el System User token ---
    public function syncTokenForm(PerfilModel $perfil)
    {
        return view('facebook.sync', compact('perfil'));
    }

    // --- ACCIÓN: valida System User token, obtiene Page token y lo guarda ---
    public function syncToken(Request $r, PerfilModel $perfil)
    {
    if (!$perfil) return back()->with('error','Perfil no encontrado.');

    $sys = $perfil->fb_system_user_token;   // <-- SIEMPRE este para /me/accounts
    if (!$sys) return back()->with('error','Falta fb_system_user_token en el perfil.');

    Log::info('[FB] sync: PAGE_ID='.$perfil->fb_page_id.' sys='.$this->maskTok($sys).' pageTok='.$this->maskTok($perfil->fb_page_token));

    // (Opcional) Verificar tipo de token con /debug_token si tienes APP_ID/SECRET
    $appId = env('FB_APP_ID');
    $appSecret = env('FB_APP_SECRET');
    if ($appId && $appSecret) {
        $appTok = $appId.'|'.$appSecret;
        $debugUrl = $this->api('debug_token').'?input_token='.urlencode($sys).'&access_token='.urlencode($appTok);
        $debug = Http::get($debugUrl)->json();
        if (isset($debug['error'])) {
            return back()->with('error','/debug_token error: '.json_encode($debug['error']));
        }
        $type = strtoupper((string) data_get($debug, 'data.type'));
        Log::info('[FB] /debug_token type='.$type);
        if ($type === 'PAGE') {
            return back()->with('error','fb_system_user_token es de TIPO PAGE. Debe ser el token del System User (regénéralo y guárdalo en fb_system_user_token).');
        }
    }

    // FORZAR el uso del System User token en la URL (sin arrays que puedan mezclar campos)
    $url = $this->api('me/accounts')
         .'?access_token='.urlencode($sys)
         .'&fields='.urlencode('id,name,access_token,tasks');

    $resp = Http::get($url)->json();

    if (isset($resp['error'])) {
        // Si esto vuelve con #100, SEGURO se envió un token de página.
        return back()->with('error','Error en /me/accounts con System User token: '.json_encode($resp['error']));
    }

    $pages = collect($resp['data'] ?? []);
    $page  = $pages->firstWhere('id', (string) $perfil->fb_page_id);

    if (!$page || empty($page['access_token'])) {
        $ids = $pages->pluck('id')->implode(',');
        return back()->with('error', "No se encontró la PAGE_ID {$perfil->fb_page_id} en /me/accounts. IDs visibles: [{$ids}]. Asegura que la Página esté asignada como activo al System User con permisos de publicación.");
    }

    // Guardar el TOKEN DE LA PÁGINA (para usar luego en /{PAGE_ID}/feed)
    $perfil->update(['fb_page_token' => $page['access_token']]);

    Log::info('[FB] Page token actualizado: '.$this->maskTok($perfil->fresh()->fb_page_token));
    return back()->with('success','✅ Token de la página sincronizado y guardado correctamente.');
}
    
public function vincularInstagram(\App\Models\PerfilModel $perfil)
{
    $res = Http::get($this->api("{$perfil->fb_page_id}"), [
        'fields' => 'instagram_business_account',
        'access_token' => $perfil->fb_page_token,
    ]);

    if (!$res->ok()) {
        return back()->with('error', 'Error al obtener cuenta de Instagram: '.$res->body());
    }

    $igId = data_get($res->json(), 'instagram_business_account.id');
    if (!$igId) {
        return back()->with('error', 'Esta página no tiene una cuenta de Instagram vinculada.');
    }

    $perfil->update(['ig_business_id' => $igId]);
    return back()->with('success', 'Cuenta de Instagram vinculada correctamente.');
}




public function listFacebookPosts(\App\Models\PerfilModel $perfil)
{
    // 1) Validaciones rápidas
    abort_if(!$perfil->fb_page_id, 400, 'Falta fb_page_id');

    // Para leer posts: usar Page Access Token; si no hay, intenta con el System User
    $pageToken = $perfil->fb_page_token ?: $perfil->fb_system_user_token;
    abort_if(!$pageToken, 400, 'Falta token de página o token del System User');

    // 2) Parámetros (paginación opcional con cursor ?after=XYZ)
    $after = request('after'); // cursor hacia "más recientes"
    $params = [
        'fields'       => 'id,message,created_time,permalink_url,full_picture,attachments{media_type,media,url,subattachments},status_type',
        'limit'        => 12,
        'access_token' => $pageToken,
    ];
    if (!empty($after)) {
        $params['after'] = $after;
    }

    // 3) Llamar a Graph API
    $resp = \Illuminate\Support\Facades\Http::get($this->api("{$perfil->fb_page_id}/posts"), $params);
    if (!$resp->ok()) {
        \Illuminate\Support\Facades\Log::error('[FB list] '.$resp->body());
        return back()->with('error', 'Facebook list: '.$resp->body());
    }

    $data = $resp->json();

    // 4) Normalizar resultados para la vista
    $items = $data['data'] ?? [];
    $next  = $data['paging']['cursors']['after']  ?? null;
    $prev  = $data['paging']['cursors']['before'] ?? null;

    // 5) Render (estilo como tu edit(): compact con variables claras)
    return view('social.fb_posts', compact('perfil', 'items', 'next', 'prev'));
}
public function listInstagramMedia(\App\Models\PerfilModel $perfil)
{
    abort_if(!$perfil->ig_business_id, 400, 'Falta ig_business_id (vincula IG primero)');
    $igToken = $perfil->fb_system_user_token;
    abort_if(!$igToken, 400, 'Falta fb_system_user_token para IG');

    $after = request('after'); // ?after=XYZ

    // Campos útiles para mostrar
    $params = [
        'fields'       => 'id,media_type,media_url,thumbnail_url,permalink,caption,timestamp,children{media_type,media_url,permalink}',
        'limit'        => 12,
        'access_token' => $igToken,
    ];
    if ($after) $params['after'] = $after;

    $resp = Http::get($this->api("{$perfil->ig_business_id}/media"), $params);
    if (!$resp->ok()) {
        Log::error('[IG list] '.$resp->body());
        return back()->with('error', 'Instagram list: '.$resp->body());
    }
    $data = $resp->json();

    return view('social.ig_media', [
        'perfil' => $perfil,
        'items'  => $data['data'] ?? [],
        'next'   => data_get($data, 'paging.cursors.after'),
        'prev'   => data_get($data, 'paging.cursors.before'),
    ]);
}
}

