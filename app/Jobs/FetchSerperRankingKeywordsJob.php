<?php

namespace App\Jobs;

use App\Models\SeoReportSection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FetchSerperRankingKeywordsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $reportId,
        public string $domain,            // ejemplo: aromasnadal.com (sin https)
        public array $keywords,
        public string $locale = 'es-ES',
        public int $pagesToCheck = 5,     // Top 50 con num=10 => 5 páginas
        public int $resultsPerPage = 10,
        public int $maxKeywords = 15,
    ) {}

    public function handle(): void
    {
        $apiKey = config('services.serper.key');
        $endpoint = config('services.serper.endpoint') ?: 'https://google.serper.dev/search';

        $domain = $this->normalizeDomain($this->domain);

        if (!$apiKey) {
            $this->writeSection('error', 'SERPER_API_KEY no configurada', null);
            return;
        }

        // ✅ Si quieres TOP 50 exacto, fuerza pagesToCheck de acuerdo a resultsPerPage
        // (sin pasarte a página 10 innecesariamente)
        $desiredTop = 50;
        $pagesToCheck = max(1, (int) ceil($desiredTop / max(1, (int) $this->resultsPerPage)));
        $pagesToCheck = min($pagesToCheck, (int) $this->pagesToCheck); // respeta si lo bajaste
        // Si quieres que SIEMPRE sea TOP 50, comenta la línea de arriba y deja:
        // $pagesToCheck = max(1, (int) ceil($desiredTop / max(1, (int) $this->resultsPerPage)));

        $keywords = array_slice($this->sanitizeKeywords($this->keywords), 0, max(1, (int) $this->maxKeywords));

        $params = $this->serperParamsFromLocale($this->locale);
        $gl = $params['gl'];
        $hl = $params['hl'];

        Log::info('SERPER: handle() INICIO', [
            'reportId' => $this->reportId,
            'domain' => $domain,
            'locale' => $this->locale,
            'gl' => $gl,
            'hl' => $hl,
            'pagesToCheck' => $pagesToCheck,
            'resultsPerPage' => $this->resultsPerPage,
            'keywords_count' => count($keywords),
        ]);

        $ranking = [];
        $checkedPagesMax = 0;
        $checkedResultsTotal = 0;
        $errors = [];

        foreach ($keywords as $kw) {
            $found = false;
            $pos = null;
            $urlFound = null;

            $checkedResultsForKw = 0;
            $checkedPagesForKw = 0;

            for ($page = 1; $page <= $pagesToCheck; $page++) {
                $checkedPagesForKw = $page;
                $checkedPagesMax = max($checkedPagesMax, $page);

                $body = [
                    'q'      => $kw,
                    'gl'     => $gl,
                    'hl'     => $hl,
                    'num'    => (int) $this->resultsPerPage,
                    'page'   => $page,
                    'type'   => 'search',
                    'engine' => 'google',
                ];

                // Log opcional por request (si no quieres tanto log, quítalo)
                Log::debug('SERPER: request', [
                    'reportId' => $this->reportId,
                    'kw' => $kw,
                    'page' => $page,
                    'num' => (int) $this->resultsPerPage,
                    'gl' => $gl,
                    'hl' => $hl,
                ]);

                $resp = Http::timeout(30)
                    ->withHeaders([
                        'X-API-KEY' => $apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($endpoint, $body);

                if (!$resp->successful()) {
                    $err = "Serper HTTP {$resp->status()}: {$resp->body()}";
                    $errors[] = ['keyword' => $kw, 'page' => $page, 'error' => $err];

                    Log::warning('SERPER: request failed', [
                        'reportId' => $this->reportId,
                        'kw' => $kw,
                        'page' => $page,
                        'status' => $resp->status(),
                        'body' => $resp->body(),
                    ]);

                    // rompe páginas para esta keyword, sigue con la siguiente keyword
                    break;
                }

                $data = $resp->json();
                $organic = data_get($data, 'organic', []);

                if (!is_array($organic) || empty($organic)) {
                    break;
                }

                foreach ($organic as $idx => $res) {
                    // ✅ cuenta primero para que el fallback de posición sea correcto
                    $checkedResultsTotal++;
                    $checkedResultsForKw++;

                    // ✅ Serper puede traer link o url
                    $link = (string) ($res['link'] ?? $res['url'] ?? '');
                    if ($link === '') continue;

                    $link = $this->extractRealUrl($link);
                    $resultHost = $this->hostFromUrl($link);
                    if (!$resultHost) continue;

                    if ($this->hostMatchesDomain($resultHost, $domain)) {
                        $found = true;

                        // ✅ posición global
                        // Serper suele traer 'position' (1..10) por página
                        $posInPage = (int) ($res['position'] ?? ($idx + 1));
                        if ($posInPage <= 0) $posInPage = $idx + 1;

                        $pos = (($page - 1) * (int)$this->resultsPerPage) + $posInPage;

                        $urlFound = $link;

                        Log::info('SERPER MATCH', [
                            'reportId' => $this->reportId,
                            'kw' => $kw,
                            'page' => $page,
                            'pos' => $pos,
                            'url' => $urlFound,
                            'resultHost' => $resultHost,
                            'domain' => $domain,
                            'keys' => is_array($res) ? array_keys($res) : [],
                        ]);

                        break;
                    }
                }

                if ($found) break;
            }

            $ranking[] = [
                'keyword'        => $kw,
                'found'          => $found,
                'rank_position'  => $pos,
                'ranking_page'   => $urlFound,  // ✅ AQUÍ QUEDA LA URL
                'checkedPages'   => $checkedPagesForKw,
                'checkedResults' => $checkedResultsForKw,
            ];
        }

        $payload = [
            'domain'              => $domain,
            'locale'              => $this->locale,
            'gl'                  => $gl,
            'hl'                  => $hl,
            'pagesToCheck'        => $pagesToCheck,
            'resultsPerPage'      => (int) $this->resultsPerPage,
            'checkedPagesMax'     => $checkedPagesMax,
            'checkedResultsTotal' => $checkedResultsTotal,
            'ranking'             => $ranking,
            'errors'              => $errors,
        ];

        Log::info('SERPER: FIN OK', [
            'reportId' => $this->reportId,
            'checkedResultsTotal' => $checkedResultsTotal,
            'foundAny' => collect($ranking)->contains(fn($r) => !empty($r['found'])),
        ]);

        $this->writeSection('ok', null, $payload);
    }

    private function writeSection(string $status, ?string $error, $payload): void
    {
        SeoReportSection::updateOrCreate(
            ['seo_report_id' => $this->reportId, 'section' => 'serper_ranking_keywords'],
            ['status' => $status, 'error_message' => $error, 'payload' => $payload]
        );
    }

    private function sanitizeKeywords(array $kws): array
    {
        return collect($kws)
            ->map(fn($x) => trim((string)$x))
            ->filter(fn($x) => $x !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeDomain(string $d): string
    {
        $d = trim(strtolower($d));
        $d = preg_replace('/^https?:\/\//', '', $d);
        $d = preg_replace('/\/.*$/', '', $d);
        $d = preg_replace('/^www\./', '', $d);
        return $d;
    }

    private function hostFromUrl(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);
        if (!$h) return null;
        $h = strtolower($h);
        return preg_replace('/^www\./', '', $h);
    }

    private function serperParamsFromLocale(string $locale): array
    {
        // match() requiere PHP 8. Si estás en PHP 7, cambia esto por if/elseif.
        return match ($locale) {
            'es-ES' => ['gl' => 'es', 'hl' => 'es'],
            'es-MX' => ['gl' => 'mx', 'hl' => 'es'],
            'es-AR' => ['gl' => 'ar', 'hl' => 'es'],
            'en-US' => ['gl' => 'us', 'hl' => 'en'],
            'en-GB' => ['gl' => 'uk', 'hl' => 'en'],
            'en-CA' => ['gl' => 'ca', 'hl' => 'en'],
            'en-AU' => ['gl' => 'au', 'hl' => 'en'],
            default => ['gl' => 'es', 'hl' => 'es'],
        };
    }

    private function extractRealUrl(string $url): string
    {
        $url = trim($url);

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        // caso: https://www.google.com/url?q=https://destino.com/...
        if (Str::contains($host, 'google.') && $path === '/url') {
            parse_str($parts['query'] ?? '', $qs);
            if (!empty($qs['q'])) return (string) $qs['q'];
            if (!empty($qs['url'])) return (string) $qs['url'];
        }

        return $url;
    }

    private function hostMatchesDomain(string $resultHost, string $domain): bool
    {
        $resultHost = strtolower(preg_replace('/^www\./', '', $resultHost));
        $domain = strtolower(preg_replace('/^www\./', '', $domain));

        if ($resultHost === $domain) return true;

        // ✅ PHP 7 compatible
        return Str::endsWith($resultHost, '.' . $domain);
    }
}
