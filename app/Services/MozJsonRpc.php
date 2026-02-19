<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MozJsonRpc
{
    private string $baseUrl = 'https://api.moz.com/jsonrpc';

    public function call(string $method, array $params): array
    {
        $token = config('services.moz.token');
        if (!$token) {
            throw new \RuntimeException('MOZ_TOKEN no configurado en .env');
        }

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => (string) Str::uuid(),
            'method'  => $method,
            'params'  => $params,
        ];

        $resp = Http::timeout(60)->withHeaders([
            'x-moz-token' => $token,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl, $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("Moz JSON-RPC {$resp->status()}: " . $resp->body());
        }

        $json = $resp->json();
        if (isset($json['error'])) {
            $msg = $json['error']['message'] ?? 'Error desconocido';
            throw new \RuntimeException("Moz JSON-RPC error: {$msg}");
        }

        return $json;
    }

    // ✅ Opción A: métricas completas (volume + difficulty + organic_ctr + priority)
    public function keywordMetrics(string $keyword, string $locale='es-ES', string $device='desktop', string $engine='google'): array
    {
        $res = $this->call('data.keyword.metrics.fetch', [
            'data' => [
                'serp_query' => [
                    'keyword' => $keyword,
                    'locale'  => $locale,
                    'device'  => $device,
                    'engine'  => $engine,
                ],
            ],
        ]);

        return $res['result'] ?? [];
    }



    public function siteRankingKeywordList(
    string $query,
    string $scope = 'domain',
    string $locale = 'es-ES',
    int $n = 0,
    int $limit = 50,
    string $sort = 'rank'
): array {
    return $this->call('data.site.ranking-keyword.list', [
        'data' => [
            'target_query' => [
                'query' => $query,
                'scope' => $scope,
                'locale' => $locale,
            ],
            'options' => [
                'sort' => $sort,
            ],
            'page' => [
                'n' => $n,
                'limit' => $limit,
            ],
        ],
    ]);
}
}
