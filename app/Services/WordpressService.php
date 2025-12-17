<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use App\Models\DominiosModel;

class WordpressService
{
    private function base(DominiosModel $dominio): string
    {
        return rtrim($dominio->url, '/').'/wp-json/wp/v2';
    }

    private function password(DominiosModel $dominio): string
    {
        return Crypt::decryptString($dominio->password);
    }

    public function posts(DominiosModel $dominio, array $statuses = ['publish'], int $perPage = 20, int $page = 1): array
    {
        return Http::timeout(20)
            ->withBasicAuth($dominio->usuario, $this->password($dominio))
            ->get($this->base($dominio).'/posts', [
                'status[]' => $statuses,
                'per_page' => $perPage,  // max 100
                'page' => $page,
                '_embed' => 1,
            ])
            ->throw()
            ->json();
    }

    public function pages(DominiosModel $dominio, array $statuses = ['publish'], int $perPage = 20, int $page = 1): array
    {
        return Http::timeout(20)
            ->withBasicAuth($dominio->usuario, $this->password($dominio))
            ->get($this->base($dominio).'/pages', [
              'status[]' => $statuses,
                'per_page' => $perPage,
                'page' => $page,
                '_embed' => 1,
            ])
            ->throw()
            ->json();
    }
    public function me(DominiosModel $dominio): array
{
    return Http::timeout(20)
        ->withBasicAuth($dominio->usuario, $this->password($dominio))
        ->get($this->base($dominio).'/users/me')
        ->throw()
        ->json();
}


public function countByStatus(\App\Models\DominiosModel $dominio, string $type = 'posts', array $statuses = ['publish','draft','future','pending','private']): array
{
    $counts = [];

    foreach ($statuses as $st) {
        $response = \Illuminate\Support\Facades\Http::timeout(20)
            ->withBasicAuth($dominio->usuario, $this->password($dominio))
            ->get($this->base($dominio)."/{$type}", [
                'status' => $st,     // aquí SOLO uno por request
                'per_page' => 1,
                'page' => 1,
            ]);

        // si WP rechaza algún estado por permisos, no rompas todo:
        if ($response->failed()) {
            $counts[$st] = null; // o 0 si prefieres
            continue;
        }

        $counts[$st] = (int) ($response->header('X-WP-Total') ?? 0);
    }

    return $counts;
}
}