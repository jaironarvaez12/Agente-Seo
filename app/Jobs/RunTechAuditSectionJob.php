<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Models\SeoReportSection;
use App\Models\DominiosModel;
use App\Support\DomainNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;

class RunTechAuditSectionJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    // ✅ evita “pegues” eternos
    public int $timeout = 1800; // 30 min
    public int $tries = 2;
    public array $backoff = [60, 120];

    public function __construct(public int $reportId, public int $maxUrls = 200) {}

    public function handle(): void
    {
        $report  = SeoReport::findOrFail($this->reportId);
        $dominio = DominiosModel::findOrFail($report->id_dominio);

        $baseUrl    = DomainNormalizer::toBaseUrl($dominio->url);
        $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';

        try {
            // ✅ modo pruebas: no hace requests
            if (env('MOZ_MOCK', false)) {
                $pages = [
                    [
                        'url' => $baseUrl,
                        'http_code' => 200,
                        'title' => 'Mock Title',
                        'meta_description' => 'Mock meta description',
                        'h1' => 'Mock H1',
                        'canonical' => $baseUrl,
                        'robots' => 'index,follow',
                        'noindex' => false,
                        'error' => null,
                        'timed_out' => false,
                    ],
                ];

                $summary = $this->summarize($pages);

                SeoReportSection::updateOrCreate(
                    ['seo_report_id' => $report->id, 'section' => 'tech'],
                    ['status' => 'ok', 'payload' => [
                        'base_url' => $baseUrl,
                        'sitemap' => $sitemapUrl,
                        'audited' => count($pages),
                        'summary' => $summary,
                        'pages' => $pages,
                        'mock' => true,
                    ]]
                );

                return;
            }

            $urls = $this->getUrlsFromSitemap($sitemapUrl, maxDepth: 2, maxSitemaps: 40);
            if (empty($urls)) {
                $urls = [$baseUrl]; // fallback mínimo
            }

            $urls = array_slice(array_values(array_unique($urls)), 0, $this->maxUrls);

            $pages = [];
            foreach ($urls as $u) {
                $pages[] = $this->auditUrl($u);
            }

            $summary = $this->summarize($pages);

            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'tech'],
                ['status' => 'ok', 'payload' => [
                    'base_url' => $baseUrl,
                    'sitemap' => $sitemapUrl,
                    'audited' => count($pages),
                    'summary' => $summary,
                    'pages' => $pages,
                ]]
            );
        } catch (\Throwable $e) {
            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'tech'],
                ['status' => 'error', 'error_message' => $e->getMessage(), 'payload' => null]
            );

            // ✅ rethrow para que quede visible en el job failed/retry si aplica
            throw $e;
        }
    }

    /**
     * Descarga sitemap(s) con límites para evitar recursion infinita
     */
    private function getUrlsFromSitemap(string $sitemapUrl, int $maxDepth = 2, int $maxSitemaps = 40, int $depth = 0, array &$seen = [], int &$countSitemaps = 0): array
    {
        $sitemapUrl = trim($sitemapUrl);
        if ($sitemapUrl === '') return [];

        // límite global de sitemaps
        if ($countSitemaps >= $maxSitemaps) return [];

        // evita loops
        if (isset($seen[$sitemapUrl])) return [];
        $seen[$sitemapUrl] = true;

        // límite de profundidad
        if ($depth > $maxDepth) return [];

        $countSitemaps++;

        try {
            $resp = Http::withHeaders(['User-Agent' => 'LaravelSEOReportBot/1.0'])
                ->connectTimeout(10)
                ->timeout(25)
                ->retry(2, 500, function ($exception) {
                    $msg = $exception->getMessage();
                    return str_contains($msg, 'cURL error 28') || str_contains(strtolower($msg), 'timeout');
                })
                ->get($sitemapUrl);

            if (!$resp->successful()) return [];
        } catch (\Throwable $e) {
            // ✅ si sitemap falla, no revienta todo
            return [];
        }

        $xml = @simplexml_load_string($resp->body());
        if (!$xml) return [];

        $urls = [];

        // sitemap index?
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sm) {
                $loc = trim((string) $sm->loc);
                if ($loc) {
                    $urls = array_merge($urls, $this->getUrlsFromSitemap($loc, $maxDepth, $maxSitemaps, $depth + 1, $seen, $countSitemaps));
                }
            }
            return $urls;
        }

        // urlset
        if (isset($xml->url)) {
            foreach ($xml->url as $u) {
                $loc = trim((string) $u->loc);
                if ($loc) $urls[] = $loc;
            }
        }

        return $urls;
    }

    private function auditUrl(string $url): array
    {
        $url = trim($url);

        $status = 0;
        $html = '';
        $error = null;
        $timedOut = false;

        try {
            $resp = Http::withHeaders(['User-Agent' => 'LaravelSEOReportBot/1.0'])
                ->connectTimeout(10) // ✅ evita SSL connect timeout infinito
                ->timeout(25)        // ✅ total time
                ->retry(1, 400, function ($exception) use (&$timedOut) {
                    $msg = $exception->getMessage();
                    $isTimeout = str_contains($msg, 'cURL error 28') || str_contains(strtolower($msg), 'timeout');
                    if ($isTimeout) $timedOut = true;
                    return $isTimeout;
                })
                ->get($url);

            $status = (int) $resp->status();
            $html = $resp->successful() ? (string) $resp->body() : '';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $title = null;
        $metaDesc = null;
        $h1 = null;
        $canonical = null;
        $robots = null;

        if ($html !== '') {
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);

            $titleNodes = $dom->getElementsByTagName('title');
            $title = $titleNodes->length ? trim($titleNodes->item(0)->textContent) : null;

            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $name = strtolower((string) $meta->getAttribute('name'));
                $prop = strtolower((string) $meta->getAttribute('property'));
                $content = trim((string) $meta->getAttribute('content'));

                if ($name === 'description' && $content) $metaDesc = $content;
                if ($name === 'robots' && $content) $robots = $content;
                if ($prop === 'og:title' && !$title && $content) $title = $content;
            }

            $h1Nodes = $dom->getElementsByTagName('h1');
            $h1 = $h1Nodes->length ? trim($h1Nodes->item(0)->textContent) : null;

            foreach ($dom->getElementsByTagName('link') as $link) {
                if (strtolower((string)$link->getAttribute('rel')) === 'canonical') {
                    $canonical = trim((string)$link->getAttribute('href'));
                    break;
                }
            }
        }

        return [
            'url' => $url,
            'http_code' => $status,
            'title' => $title,
            'meta_description' => $metaDesc,
            'h1' => $h1,
            'canonical' => $canonical,
            'robots' => $robots,
            'noindex' => $robots ? (stripos($robots, 'noindex') !== false) : false,

            // ✅ extras para debug
            'error' => $error,
            'timed_out' => (bool) $timedOut,
        ];
    }

    private function summarize(array $pages): array
    {
        $codes4xx5xx = array_filter($pages, fn($p) => (int)($p['http_code'] ?? 0) >= 400);
        $missingTitle = array_filter($pages, fn($p) => empty($p['title']));
        $missingDesc  = array_filter($pages, fn($p) => empty($p['meta_description']));
        $missingH1    = array_filter($pages, fn($p) => empty($p['h1']));
        $noindex      = array_filter($pages, fn($p) => !empty($p['noindex']));
        $timeouts     = array_filter($pages, fn($p) => !empty($p['timed_out']) || str_contains((string)($p['error'] ?? ''), 'cURL error 28'));

        $titleMap = [];
        foreach ($pages as $p) {
            $t = $p['title'] ?? '';
            if ($t !== '') $titleMap[$t][] = $p['url'];
        }
        $dupTitles = array_filter($titleMap, fn($urls) => count($urls) > 1);

        return [
            'errors_4xx_5xx' => count($codes4xx5xx),
            'missing_title' => count($missingTitle),
            'missing_meta_description' => count($missingDesc),
            'missing_h1' => count($missingH1),
            'noindex_pages' => count($noindex),
            'duplicate_titles' => count($dupTitles),
            'timeouts' => count($timeouts),
            'top_duplicate_titles' => array_slice(
                array_map(fn($t, $u) => ['title' => $t, 'count' => count($u)], array_keys($dupTitles), $dupTitles),
                0,
                10
            ),
        ];
    }
}
