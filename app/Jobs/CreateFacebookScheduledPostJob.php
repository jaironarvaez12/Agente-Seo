<?php

namespace App\Jobs;

use App\Models\ScheduledFacebookPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Laravel\Facades\Image;

class CreateFacebookScheduledPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $rowId) {}

    public function handle(): void
    {
        $row = ScheduledFacebookPost::find($this->rowId);
        if (!$row || $row->status !== 'queued') return;

        $perfil = $row->perfil;
        if (!$perfil || !$perfil->fb_page_id) {
            $row->update(['status' => 'error', 'last_error' => 'Perfil inválido o sin PAGE_ID']);
            return;
        }

        try {
            // 1) Page Access Token
            $pageToken = $this->resolvePageToken($perfil);
            if (!$pageToken) {
                throw new \RuntimeException('No hay Page Access Token disponible.');
            }

            // 2) Preparar imágenes en URLs públicas JPEG 1080x1080
            $processedUrls = [];
            foreach ((array)$row->image_sources as $src) {
                $processedUrls[] = $this->processToPublicJpeg1080($src);
            }

            // 3) Crear programación en Facebook
            if (count($processedUrls) === 0) {
                // Texto
                $resp = Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), [
                    'message'                => (string)($row->message ?? ''),
                    'published'              => false,
                    'scheduled_publish_time' => (int)$row->scheduled_epoch,
                    'access_token'           => $pageToken,
                ]);
                if (!$resp->ok()) throw new \RuntimeException($resp->body());
                $row->update([
                    'status'     => 'fb_scheduled',
                    'fb_post_id' => $resp->json('id'),
                ]);
            } else {
                // Fotos no publicadas + attached_media
                $mediaFbids = $this->uploadUnpublishedPhotos($perfil->fb_page_id, $pageToken, $processedUrls);

                $attached = [];
                foreach ($mediaFbids as $k => $fbid) {
                    $attached["attached_media[$k]"] = json_encode(['media_fbid' => $fbid]);
                }

                $payload = array_merge($attached, [
                    'message'                => (string)($row->message ?? ''),
                    'published'              => false,
                    'scheduled_publish_time' => (int)$row->scheduled_epoch,
                    'access_token'           => $pageToken,
                ]);

                $resp = Http::asForm()->post($this->api("{$perfil->fb_page_id}/feed"), $payload);
                if (!$resp->ok()) throw new \RuntimeException($resp->body());

                $row->update([
                    'status'     => 'fb_scheduled',
                    'fb_post_id' => $resp->json('id'),
                ]);
            }
        } catch (\Throwable $e) {
            $row?->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            report($e);
        }
    }

    protected function resolvePageToken($perfil): ?string
    {
        if (!empty($perfil->fb_system_user_token)) {
            $tokRes = Http::get($this->api("{$perfil->fb_page_id}"), [
                'fields'       => 'access_token',
                'access_token' => $perfil->fb_system_user_token,
            ]);
            if ($tokRes->ok() && ($tok = $tokRes->json('access_token'))) {
                try { $perfil->update(['fb_page_token' => $tok]); } catch (\Throwable $e) {}
                return $tok;
            }
        }
        return !empty($perfil->fb_page_token) ? $perfil->fb_page_token : null;
    }

    protected function processToPublicJpeg1080(array $source): string
    {
        // $source = ['type'=>'file|url','value'=>'...']
        $binary = null;

        if (($source['type'] ?? '') === 'file') {
            $path = $source['value'];
            $abs  = storage_path('app/public/'.$path);
            if (!is_file($abs)) throw new \RuntimeException("Archivo no encontrado: $path");
            $binary = file_get_contents($abs);
        } elseif (($source['type'] ?? '') === 'url') {
            $resp = Http::timeout(20)->get($source['value']);
            if (!$resp->ok()) throw new \RuntimeException("HTTP ".$resp->status()." al descargar ".$source['value']);
            $binary = $resp->body();
        } else {
            throw new \RuntimeException("Tipo de fuente inválido.");
        }

        $img = Image::read($binary);
        if (method_exists($img, 'orientate')) $img->orientate();
        $img->cover(1080,1080);
        $bytes = $img->toJpeg(85)->toString();

        $dir = public_path('ig_out');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $name = uniqid('fb_', true).'.jpg';
        file_put_contents($dir.'/'.$name, $bytes);
        $url = asset('ig_out/'.$name);

        // Sonda para que FB “vea” la imagen
        $probe = Http::withHeaders([
            'User-Agent' => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        ])->timeout(10)->head($url);
        if (!$probe->ok() || stripos((string)$probe->header('Content-Type',''), 'image/jpeg') !== 0) {
            throw new \RuntimeException('URL pública no accesible o sin image/jpeg ('.$probe->status().')');
        }
        return $url;
    }

    protected function uploadUnpublishedPhotos(string $pageId, string $pageToken, array $urls): array
    {
        $pool = Http::asForm()->pool(function ($pool) use ($urls, $pageId, $pageToken) {
            return array_map(function ($u) use ($pool, $pageId, $pageToken) {
                return $pool->post($this->api("$pageId/photos"), [
                    'url'          => $u,
                    'published'    => false,
                    'access_token' => $pageToken,
                ]);
            }, $urls);
        });

        $fbids = [];
        foreach ($pool as $res) {
            if (!$res->ok()) throw new \RuntimeException($res->body());
            $fbids[] = $res->json('id');
        }
        return $fbids;
    }

    protected function api(string $path): string
    {
        $base = rtrim(config('services.facebook.graph_base', 'https://graph.facebook.com/v20.0/'), '/');
        return $base.'/'.ltrim($path, '/');
    }
}