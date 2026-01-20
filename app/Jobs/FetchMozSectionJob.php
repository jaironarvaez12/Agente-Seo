<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Models\SeoReportSection;
use App\Models\DominiosModel;
use App\Models\MozDailyMetric;
use App\Services\MozClient;
use App\Support\DomainNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class FetchMozSectionJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public int $reportId,
        public int $refDomainsMax = 100
    ) {}

    public function handle(MozClient $moz): void
    {
        $report = SeoReport::findOrFail($this->reportId);
        $dominio = DominiosModel::findOrFail($report->id_dominio);

        $host = DomainNormalizer::toHost($dominio->url);
        $root = DomainNormalizer::rootDomainSimple($host);
        $today = now()->toDateString();

        try {
            // 1) Snapshot url_metrics
            $urlMetrics = $moz->urlMetrics([$host]);
            $item = data_get($urlMetrics, 'results.0', []);

            $da = data_get($item, 'domain_authority');
            $pa = data_get($item, 'page_authority');
            $spam = data_get($item, 'spam_score');

            $backlinksTotal  = data_get($item, 'pages_to_root_domain');
            $refDomainsTotal = data_get($item, 'root_domains_to_root_domain');

            // 2) Guardar snapshot diario
            MozDailyMetric::updateOrCreate(
                ['id_dominio' => $dominio->id_dominio, 'date' => $today],
                [
                    'target' => $host,
                    'backlinks_total' => is_numeric($backlinksTotal) ? (int)$backlinksTotal : null,
                    'ref_domains_total' => is_numeric($refDomainsTotal) ? (int)$refDomainsTotal : null,
                    'domain_authority' => is_numeric($da) ? (int)$da : null,
                    'page_authority' => is_numeric($pa) ? (int)$pa : null,
                    'spam_score' => is_numeric($spam) ? (int)$spam : null,
                    'raw' => $urlMetrics,
                ]
            );

            // 3) Serie diaria desde BD
            $snapshots = MozDailyMetric::query()
                ->where('id_dominio', $dominio->id_dominio)
                ->where('date', '>=', now()->subDays(60)->toDateString())
                ->orderBy('date', 'asc')
                ->get();

            $daily = [];
            $prev = null;

            foreach ($snapshots as $s) {
                $row = [
                    'date' => $s->date->toDateString(),
                    'backlinks_total' => $s->backlinks_total,
                    'ref_domains_total' => $s->ref_domains_total,
                ];

                $row['backlinks_delta'] = ($prev && $prev->backlinks_total !== null && $s->backlinks_total !== null)
                    ? ($s->backlinks_total - $prev->backlinks_total)
                    : null;

                $row['ref_domains_delta'] = ($prev && $prev->ref_domains_total !== null && $s->ref_domains_total !== null)
                    ? ($s->ref_domains_total - $prev->ref_domains_total)
                    : null;

                $rd = (int)($row['ref_domains_delta'] ?? 0);
                $row['new_ref_domains'] = $rd > 0 ? $rd : 0;
                $row['lost_ref_domains'] = $rd < 0 ? abs($rd) : 0;

                $daily[] = $row;
                $prev = $s;
            }

            // 4) Ref domains smart: intenta linking_root_domains y si viene vacío, arma desde links
            $refPack = $this->getRefDomainsSmart($moz, $host, $root);

            // 5) Guardar sección MOZ
            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'moz'],
                [
                    'status' => 'ok',
                    'error_message' => null,
                    'payload' => [
                        'target' => $host,

                        'domain_authority' => $da,
                        'page_authority' => $pa,
                        'spam_score' => $spam,

                        'backlinks_total' => $backlinksTotal,
                        'ref_domains_total' => $refDomainsTotal,

                        'daily' => $daily,
                        'monthly' => [],

                        'ref_domains_list' => $refPack['list'],
                        'ref_domains_raw' => $refPack['raw'],
                        'ref_domains_source' => $refPack['source'],

                        'raw' => $urlMetrics,
                    ],
                ]
            );

        } catch (\Throwable $e) {
            Log::error('Moz Section Job Error', [
                'report_id' => $report->id,
                'domain_id' => $dominio->id_dominio,
                'target' => $host,
                'message' => $e->getMessage(),
            ]);

            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'moz'],
                [
                    'status' => 'error',
                    'error_message' => $e->getMessage(),
                    'payload' => null,
                ]
            );
        }
    }

    private function getRefDomainsSmart(MozClient $moz, string $host, string $root): array
    {
        $tries = [
            // linking_root_domains
            ['fn' => 'lrd', 'target' => $host,      'scope' => 'subdomain',   'filter' => 'external+nofollow'],
            ['fn' => 'lrd', 'target' => $host,      'scope' => 'subdomain',   'filter' => 'external'],
            ['fn' => 'lrd', 'target' => $root,      'scope' => 'root_domain', 'filter' => 'external+nofollow'],
            ['fn' => 'lrd', 'target' => $root,      'scope' => 'root_domain', 'filter' => 'external'],

            // links fallback (si arriba falla)
            ['fn' => 'links', 'target' => $host, 'scope' => 'subdomain',   'filter' => 'external+nofollow'],
            ['fn' => 'links', 'target' => $root, 'scope' => 'root_domain', 'filter' => 'external+nofollow'],
            ['fn' => 'links', 'target' => $host . '/', 'scope' => 'page',  'filter' => 'external+nofollow'],
        ];

        foreach ($tries as $t) {
            if ($t['fn'] === 'lrd') {
                $resp = $moz->linkingRootDomains(
                    target: $t['target'],
                    targetScope: $t['scope'],
                    limit: 50,
                    nextToken: null,
                    filter: $t['filter'],
                    sort: 'source_domain_authority'
                );

                $rows = data_get($resp, 'results', []);
                if (is_array($rows) && count($rows) > 0) {
                    $list = $this->normalizeRefDomainsFromLRD($rows);
                    if (count($list) > 0) {
                        return ['list' => array_slice($list, 0, $this->refDomainsMax), 'raw' => $resp, 'source' => 'linking_root_domains'];
                    }
                }
            } else {
                $resp = $moz->links(
                    target: $t['target'],
                    targetScope: $t['scope'],
                    limit: 50,
                    nextToken: null,
                    filter: $t['filter']
                );

                $rows = data_get($resp, 'results', []);
                if (is_array($rows) && count($rows) > 0) {
                    $list = $this->buildRefDomainsFromLinks($rows);
                    if (count($list) > 0) {
                        return ['list' => array_slice($list, 0, $this->refDomainsMax), 'raw' => $resp, 'source' => 'links_aggregated'];
                    }
                }
            }
        }

        return ['list' => [], 'raw' => null, 'source' => 'none'];
    }

    private function normalizeRefDomainsFromLRD(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $domain =
                $r['source_root_domain'] ??
                $r['root_domain'] ??
                $r['domain'] ??
                $r['linking_root_domain'] ??
                $r['source_domain'] ??
                null;

           $links =
            $r['source_link_count'] ??
            $r['link_count'] ??
            $r['links'] ??
            $r['pages'] ??
            1; // ✅ si no viene conteo, al menos 1

            $da =
                $r['source_domain_authority'] ??
                $r['domain_authority'] ??
                $r['da'] ??
                null;

            if (!$domain) continue;

            $out[] = [
                'root_domain' => $domain,
                'domain_authority' => $da,
                'spam_score' => $r['spam_score'] ?? $r['source_spam_score'] ?? null,
                'links' => is_numeric($links) ? (int)$links : $links,
                'raw' => $r,
            ];
        }

        usort($out, fn($a,$b) => (int)($b['links'] ?? 0) <=> (int)($a['links'] ?? 0));

        return $out;
    }

    private function buildRefDomainsFromLinks(array $linksRows): array
    {
        $map = [];

        foreach ($linksRows as $lnk) {
            $src =
                $lnk['source_root_domain'] ??
                $lnk['source_domain'] ??
                $lnk['source'] ??
                null;

            if (!$src) continue;

            if (!isset($map[$src])) {
                $map[$src] = [
                    'root_domain' => $src,
                    'domain_authority' => $lnk['source_domain_authority'] ?? null,
                    'spam_score' => $lnk['source_spam_score'] ?? null,
                    'links' => 0,
                    'raw' => [],
                ];
            }

            $map[$src]['links']++;

            // guarda 1 ejemplo por dominio para debug
            if (count($map[$src]['raw']) < 1) {
                $map[$src]['raw'][] = $lnk;
            }
        }

        $out = array_values($map);
        usort($out, fn($a,$b) => (int)$b['links'] <=> (int)$a['links']);

        return $out;
    }
}
