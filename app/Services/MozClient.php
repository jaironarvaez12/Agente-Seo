<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class MozClient
{
    private function baseUrl(): string
    {
        return rtrim(config('services.moz.base_url', 'https://lsapi.seomoz.com/v2'), '/');
    }

    private function token(): string
    {
        return (string) config('services.moz.token');
    }

    public function urlMetrics(array $targets, array $options = []): array
    {
        $payload = array_merge([
            'targets' => array_values($targets),
        ], $options);

        $resp = Http::timeout(60)
            ->withHeaders([
                'x-moz-token' => $this->token(),
                'Accept' => 'application/json',
            ])
            ->post($this->baseUrl() . '/url_metrics', $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("Moz url_metrics error {$resp->status()}: " . $resp->body());
        }

        return $resp->json();
    }

    /**
     * Linking Root Domains (referring domains)
     * Body usa target_scope (NO scope)
     */
    public function linkingRootDomains(
        string $target,
        string $targetScope = 'root_domain',
        int $limit = 50,
        ?string $nextToken = null,
        string $filter = 'external+nofollow',
        string $sort = 'source_domain_authority'
    ): array {
        $payload = [
            'target' => $target,
            'target_scope' => $targetScope,
            'filter' => $filter,
            'sort' => $sort,
            'limit' => $limit,
        ];

        if ($nextToken) {
            $payload['next_token'] = $nextToken;
        }

        $resp = Http::timeout(60)
            ->withHeaders([
                'x-moz-token' => $this->token(),
                'Accept' => 'application/json',
            ])
            ->post($this->baseUrl() . '/linking_root_domains', $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("Moz linking_root_domains error {$resp->status()}: " . $resp->body());
        }

        return $resp->json();
    }

    /**
     * Links (fallback para construir referring domains agrupados)
     */
    public function links(
        string $target,
        string $targetScope = 'page',
        int $limit = 50,
        ?string $nextToken = null,
        string $filter = 'external+nofollow'
    ): array {
        $payload = [
            'target' => $target,
            'target_scope' => $targetScope,
            'filter' => $filter,
            'limit' => $limit,
        ];

        if ($nextToken) {
            $payload['next_token'] = $nextToken;
        }

        $resp = Http::timeout(60)
            ->withHeaders([
                'x-moz-token' => $this->token(),
                'Accept' => 'application/json',
            ])
            ->post($this->baseUrl() . '/links', $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("Moz links error {$resp->status()}: " . $resp->body());
        }

        return $resp->json();
    }
}