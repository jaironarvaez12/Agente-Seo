<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ServicioBacklinks
{
    public function procesarArticulo(array $payload): array
    {
        $base = rtrim(config('services.backlinks.base_url'), '/');
        $key  = (string) config('services.backlinks.auth_key');

        if ($key === '') {
            throw new \RuntimeException('BACKLINKS_AUTH_KEY no configurado');
        }

        $resp = Http::timeout(180)
            ->withHeaders([
                'X-Auth-Key' => $key,
                'Content-Type' => 'application/json',
            ])
            ->post($base . '/api/process-article.php', $payload);

        if (!in_array($resp->status(), [200, 207], true)) {
            $msg = $resp->json('message') ?? ('HTTP ' . $resp->status());
            throw new \RuntimeException('Backlinks API: ' . $msg);
        }

        $json = $resp->json();
        if (!is_array($json) || empty($json['success'])) {
            $msg = is_array($json) ? ($json['message'] ?? 'Respuesta inválida') : 'Respuesta inválida';
            throw new \RuntimeException('Backlinks API: ' . $msg);
        }

        return $json;
    }
}
