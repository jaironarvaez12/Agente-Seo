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

    public function __construct(public int $reportId, public int $maxUrls = 200) {}

    public function handle(): void
    {
        $report = SeoReport::findOrFail($this->reportId);
        $dominio = DominiosModel::findOrFail($report->id_dominio);

        $baseUrl = DomainNormalizer::toBaseUrl($dominio->url);
        $sitemapUrl = rtrim($baseUrl, '/') . '/sitemap.xml';

        try {
            $urls = $this->getUrlsFromSitemap($sitemapUrl);
            if (empty($urls)) {
                $urls = [$baseUrl]; // fallback mínimo
            }

            $urls = array_slice(array_values(array_unique($urls)), 0, $this->maxUrls);

            $pages = [];
            foreach ($urls as $u) {
                $pages[] = $this->auditUrl($u);
            }

            // Resumen de issues
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
        }
    }

    private function getUrlsFromSitemap(string $sitemapUrl): array
    {
        $resp = Http::timeout(30)->get($sitemapUrl);
        if (!$resp->successful()) return [];

        $xml = @simplexml_load_string($resp->body());
        if (!$xml) return [];

        $urls = [];

        // sitemap index?
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sm) {
                $loc = (string) $sm->loc;
                if ($loc) {
                    $urls = array_merge($urls, $this->getUrlsFromSitemap($loc));
                }
            }
            return $urls;
        }

        // urlset
        if (isset($xml->url)) {
            foreach ($xml->url as $u) {
                $loc = (string) $u->loc;
                if ($loc) $urls[] = $loc;
            }
        }

        return $urls;
    }

    private function auditUrl(string $url): array
    {
        $resp = Http::timeout(30)
            ->withHeaders(['User-Agent' => 'LaravelSEOReportBot/1.0'])
            ->get($url);

        $status = $resp->status();
        $html = $resp->successful() ? $resp->body() : '';

        $title = null; $metaDesc = null; $h1 = null; $canonical = null; $robots = null;

        if ($html) {
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);

            $titleNodes = $dom->getElementsByTagName('title');
            $title = $titleNodes->length ? trim($titleNodes->item(0)->textContent) : null;

            // meta tags
            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $name = strtolower((string) $meta->getAttribute('name'));
                $prop = strtolower((string) $meta->getAttribute('property'));
                $content = trim((string) $meta->getAttribute('content'));

                if ($name === 'description' && $content) $metaDesc = $content;
                if ($name === 'robots' && $content) $robots = $content;
                if ($prop === 'og:title' && !$title && $content) $title = $content;
            }

            // h1
            $h1Nodes = $dom->getElementsByTagName('h1');
            $h1 = $h1Nodes->length ? trim($h1Nodes->item(0)->textContent) : null;

            // canonical
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
        ];
    }

    private function summarize(array $pages): array
    {
        $codes4xx5xx = array_filter($pages, fn($p) => $p['http_code'] >= 400);
        $missingTitle = array_filter($pages, fn($p) => empty($p['title']));
        $missingDesc  = array_filter($pages, fn($p) => empty($p['meta_description']));
        $missingH1    = array_filter($pages, fn($p) => empty($p['h1']));
        $noindex      = array_filter($pages, fn($p) => !empty($p['noindex']));

        // duplicados de title (si quieres)
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
            'top_duplicate_titles' => array_slice(array_map(fn($t,$u)=>['title'=>$t,'count'=>count($u)], array_keys($dupTitles), $dupTitles), 0, 10),
        ];
    }
}