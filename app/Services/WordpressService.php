<?php

namespace App\Services;

use App\Models\DominiosModel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class WordpressService
{
    private const TIMEOUT = 60;
    private const RETRIES = 2;
    private const RETRY_SLEEP_MS = 500;

    private const MAX_PER_PAGE = 100;

    private const FALLBACK_MAX_PAGES   = 10;
    private const FALLBACK_MAX_SECONDS = 6;

    private const LIST_FIELDS = 'id,link,slug,status,date,modified,title';

    private function root(DominiosModel $dominio): string
    {
        $url = trim((string)$dominio->url);
        if ($url === '') throw new \InvalidArgumentException('El dominio no tiene URL configurada.');
        return rtrim($url, '/');
    }

    private function password(DominiosModel $dominio): string
    {
        return Crypt::decryptString($dominio->password);
    }

    private function clientBase(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)
            ->retry(self::RETRIES, self::RETRY_SLEEP_MS)
            ->withOptions(['http_errors' => false]) // no exceptions por 4xx/5xx
            ->withHeaders([
                'Accept' => 'application/json, text/plain, */*',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            ]);
    }

    private function clientNoAuth(): PendingRequest
    {
        return $this->clientBase();
    }

    private function clientAuth(DominiosModel $dominio): PendingRequest
    {
        return $this->clientBase()->withBasicAuth($dominio->usuario, $this->password($dominio));
    }

    private function clampPerPage(int $perPage): int
    {
        if ($perPage < 1) return 1;
        if ($perPage > self::MAX_PER_PAGE) return self::MAX_PER_PAGE;
        return $perPage;
    }

    private function parseJson(Response $resp): ?array
    {
        $raw = (string)$resp->body();

        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

        $raw = trim($raw);
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isWpError(array $json): bool
    {
        return isset($json['code'], $json['message'], $json['data']) && is_array($json['data']);
    }

    private function isForbiddenContext(array $json): bool
    {
        return ($json['code'] ?? '') === 'rest_forbidden_context';
    }

    private function isInvalidStatusParam(array $json): bool
    {
        if (($json['code'] ?? '') !== 'rest_invalid_param') return false;
        $params = $json['data']['params'] ?? [];
        return is_array($params) && array_key_exists('status', $params);
    }

    /**
     * ✅ GET blindado:
     * - intenta sin auth
     * - si 401/403 => intenta con auth
     * - si pedimos status/context=edit y viene 400 => intenta con auth
     * - si por cualquier razón lanza RequestException => devolvemos $e->response
     */
    private function getUrl(DominiosModel $dominio, string $url, array $query): Response
    {
        try {
            $resp = $this->clientNoAuth()->get($url, $query);
        } catch (RequestException $e) {
            $resp = $e->response;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Error en request sin auth a {$url}: " . $e->getMessage());
        }

        $needsAuth = array_key_exists('status', $query) || (($query['context'] ?? null) === 'edit');

        if (
            in_array($resp->status(), [401, 403], true) ||
            ($needsAuth && $resp->status() === 400)
        ) {
            try {
                $resp = $this->clientAuth($dominio)->get($url, $query);
            } catch (RequestException $e) {
                $resp = $e->response;
            } catch (\Throwable $e) {
                throw new \RuntimeException("Error en request con auth a {$url}: " . $e->getMessage());
            }
        }

        return $resp;
    }

    /**
     * ✅ requestJson:
     * Devuelve:
     * - lista normal (posts/pages)
     * - o JSON de error WP (code/message/data) con _http_status y _url
     */
    private function requestJson(DominiosModel $dominio, string $path, array $query = []): array
    {
        $root = $this->root($dominio);
        $path = ltrim($path, '/');

        // A) wp-json
        $urlA  = "{$root}/wp-json/wp/v2/{$path}";
        $respA = $this->getUrl($dominio, $urlA, $query);
        $jsonA = $this->parseJson($respA);

        if ($jsonA !== null) {
            if ($respA->status() >= 400 && $this->isWpError($jsonA)) {
                $jsonA['_http_status'] = $respA->status();
                $jsonA['_url'] = $urlA;
            }
            return $jsonA;
        }

        // B) rest_route
        $urlB   = "{$root}/";
        $queryB = array_merge(['rest_route' => "/wp/v2/{$path}"], $query);

        $respB = $this->getUrl($dominio, $urlB, $queryB);
        $jsonB = $this->parseJson($respB);

        if ($jsonB !== null) {
            if ($respB->status() >= 400 && $this->isWpError($jsonB)) {
                $jsonB['_http_status'] = $respB->status();
                $jsonB['_url'] = $urlB;
            }
            return $jsonB;
        }

        // si no llega JSON, error real
        $ct = (string)$respA->header('Content-Type');
        $sn = substr((string)$respA->body(), 0, 250);

        throw new \RuntimeException("No llegó JSON desde WordPress. {$urlA} HTTP {$respA->status()} CT={$ct} Snippet={$sn}");
    }

    /**
     * Fallback sin status param:
     * - intenta context=edit para poder ver drafts (si WP lo permite)
     * - si forbidden_context => view
     * - filtra local por status
     */
    private function fastFallbackByStatus(
        DominiosModel $dominio,
        string $type,
        string $wantedStatus,
        int $perPage,
        int $page
    ): array {
        $start = microtime(true);
        $out = [];
        $seen = [];

        $from = max(1, $page);
        $to   = $from + self::FALLBACK_MAX_PAGES - 1;

        for ($p = $from; $p <= $to; $p++) {
            if ((microtime(true) - $start) > self::FALLBACK_MAX_SECONDS) break;

            $query = [
                'per_page' => $this->clampPerPage($perPage),
                'page'     => $p,
                'context'  => 'edit',
                'orderby'  => 'modified',
                'order'    => 'desc',
                '_fields'  => self::LIST_FIELDS,
            ];

            $items = $this->requestJson($dominio, $type, $query);

            if ($this->isWpError($items) && $this->isForbiddenContext($items)) {
                $query['context'] = 'view';
                $items = $this->requestJson($dominio, $type, $query);
            }

            if ($this->isWpError($items)) break;
            if (empty($items) || !is_array($items)) break;

            foreach ($items as $it) {
                if (!is_array($it)) continue;
                if (($it['status'] ?? '') !== $wantedStatus) continue;

                $id = $it['id'] ?? null;
                if ($id === null || isset($seen[$id])) continue;

                $seen[$id] = true;
                $out[] = $it;
            }

            if (count($items) < $perPage) break;
        }

        return $out;
    }

    private function listSingleStatus(
        DominiosModel $dominio,
        string $type,
        string $status,
        int $perPage,
        int $page
    ): array {
        $context = ($status === 'publish') ? 'view' : 'edit';

        $query = [
            'per_page' => $this->clampPerPage($perPage),
            'page'     => max(1, $page),
            'context'  => $context,
            'orderby'  => 'modified',
            'order'    => 'desc',
            '_fields'  => self::LIST_FIELDS,
        ];

        // ✅ status SOLO si NO es publish
        if ($status !== 'publish') {
            $query['status'] = $status;
        }

        $items = $this->requestJson($dominio, $type, $query);

        // forbidden_context => degradar
        if ($this->isWpError($items) && $this->isForbiddenContext($items)) {
            $query['context'] = 'view';
            $items = $this->requestJson($dominio, $type, $query);
        }

        // invalid status param => fallback sin status y filtrado local
        if ($this->isWpError($items) && $this->isInvalidStatusParam($items)) {
            return $this->fastFallbackByStatus($dominio, $type, $status, $perPage, $page);
        }

        // otros errores => vacío
        if ($this->isWpError($items)) {
            return [];
        }

        return $items;
    }

    private function listMultiStatus(
        DominiosModel $dominio,
        string $type,
        array $statuses,
        int $perPage,
        int $page
    ): array {
        $statuses = array_values(array_unique(array_filter(array_map('strval', $statuses), fn($s) => $s !== '')));
        $all = [];
        $seen = [];

        foreach ($statuses as $st) {
            $items = $this->listSingleStatus($dominio, $type, $st, $perPage, $page);

            foreach ($items as $it) {
                if (!is_array($it)) continue;
                $id = $it['id'] ?? null;
                if ($id === null || isset($seen[$id])) continue;
                $seen[$id] = true;
                $all[] = $it;
            }
        }

        usort($all, fn($a, $b) => strcmp($b['modified'] ?? '', $a['modified'] ?? ''));
        return array_slice($all, 0, $perPage);
    }

    public function posts(DominiosModel $dominio, array $statuses = ['publish'], int $perPage = 20, int $page = 1): array
    {
        set_time_limit(0);

        $statuses = array_values(array_filter(array_map('strval', $statuses), fn($s) => $s !== ''));
        if (count($statuses) > 1) {
            return $this->listMultiStatus($dominio, 'posts', $statuses, $perPage, $page);
        }

        $status = $statuses[0] ?? 'publish';
        return $this->listSingleStatus($dominio, 'posts', $status, $perPage, $page);
    }

    public function pages(DominiosModel $dominio, array $statuses = ['publish'], int $perPage = 20, int $page = 1): array
    {
        set_time_limit(0);

        $statuses = array_values(array_filter(array_map('strval', $statuses), fn($s) => $s !== ''));
        if (count($statuses) > 1) {
            return $this->listMultiStatus($dominio, 'pages', $statuses, $perPage, $page);
        }

        $status = $statuses[0] ?? 'publish';
        return $this->listSingleStatus($dominio, 'pages', $status, $perPage, $page);
    }

    public function me(DominiosModel $dominio): array
    {
        $items = $this->requestJson($dominio, 'users/me', ['context' => 'edit']);

        if ($this->isWpError($items) && $this->isForbiddenContext($items)) {
            $items = $this->requestJson($dominio, 'users/me', ['context' => 'view']);
        }

        return $items;
    }

    public function countByStatus(
        DominiosModel $dominio,
        string $type = 'posts',
        array $statuses = ['publish','draft','future','pending','private']
    ): array {
        set_time_limit(0);

        $type = trim($type) ?: 'posts';
        $statuses = array_values(array_unique(array_filter(array_map('strval', $statuses), fn($s) => $s !== '')));

        $counts = [];
        $root = $this->root($dominio);

        foreach ($statuses as $st) {
            $context = ($st === 'publish') ? 'view' : 'edit';

            $params = [
                'per_page' => 1,
                'page'     => 1,
                'context'  => $context,
                '_fields'  => 'id',
            ];

            // ✅ status SOLO si NO es publish
            if ($st !== 'publish') {
                $params['status'] = $st;
            }

            $resp = $this->getUrl($dominio, "{$root}/wp-json/wp/v2/{$type}", $params);
            $json = $this->parseJson($resp) ?? [];

            // Si WP bloquea edit context
            if ($resp->status() >= 400 && $this->isWpError($json) && $this->isForbiddenContext($json) && $context === 'edit') {
                $params['context'] = 'view';
                $resp = $this->getUrl($dominio, "{$root}/wp-json/wp/v2/{$type}", $params);
                $json = $this->parseJson($resp) ?? [];
            }

            // Si status es inválido, ese WP no permite contarlo por REST
            if ($resp->status() === 400 && $this->isWpError($json) && $this->isInvalidStatusParam($json)) {
                $counts[$st] = null;
                continue;
            }

            if ($resp->ok()) {
                $counts[$st] = (int)($resp->header('X-WP-Total') ?? 0);
            } else {
                $counts[$st] = null;
            }
        }

        return $counts;
    }


private function sendUrl(DominiosModel $dominio, string $method, string $url, array $payload = [], array $query = []): Response
{
    try {
        $req = $this->clientAuth($dominio); // escritura SIEMPRE con auth
        $resp = $req->send($method, $url, [
            'query' => $query,
            'json'  => $payload,
        ]);
        return $resp;
    } catch (RequestException $e) {
        return $e->response;
    } catch (\Throwable $e) {
        throw new \RuntimeException("Error en request {$method} a {$url}: " . $e->getMessage());
    }
}

private function requestJsonWrite(DominiosModel $dominio, string $path, string $method, array $payload = [], array $query = []): array
{
    $root = $this->root($dominio);
    $path = ltrim($path, '/');

    // A) wp-json
    $urlA  = "{$root}/wp-json/wp/v2/{$path}";
    $respA = $this->sendUrl($dominio, $method, $urlA, $payload, $query);
    $jsonA = $this->parseJson($respA);

    if ($jsonA !== null) {
        if ($respA->status() >= 400 && $this->isWpError($jsonA)) {
            $jsonA['_http_status'] = $respA->status();
            $jsonA['_url'] = $urlA;
        }
        return $jsonA;
    }

    // B) rest_route fallback
    $urlB = "{$root}/";
    $queryB = array_merge(['rest_route' => "/wp/v2/{$path}"], $query);

    $respB = $this->sendUrl($dominio, $method, $urlB, $payload, $queryB);
    $jsonB = $this->parseJson($respB);

    if ($jsonB !== null) {
        if ($respB->status() >= 400 && $this->isWpError($jsonB)) {
            $jsonB['_http_status'] = $respB->status();
            $jsonB['_url'] = $urlB;
        }
        return $jsonB;
    }

    $ct = (string)$respA->header('Content-Type');
    $sn = substr((string)$respA->body(), 0, 250);

    throw new \RuntimeException("No llegó JSON desde WordPress (write). {$urlA} HTTP {$respA->status()} CT={$ct} Snippet={$sn}");
}

public function upsert(DominiosModel $dominio, string $type, array $payload, ?int $wpId = null): array
{
    set_time_limit(0);

    $type = trim($type);
    if (!in_array($type, ['posts','pages'], true)) {
        throw new \InvalidArgumentException("Tipo inválido para upsert: {$type}");
    }

    $path = $wpId ? "{$type}/{$wpId}" : $type;
    $method = $wpId ? 'PUT' : 'POST';

    return $this->requestJsonWrite($dominio, $path, $method, $payload);
}
}
