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
        public int $refDomainsMax = 10 // ✅ antes 50
    ) {}

    public function handle(MozClient $moz): void
    {
        $report = SeoReport::findOrFail($this->reportId);
        $dominio = DominiosModel::findOrFail($report->id_dominio);

        $host = DomainNormalizer::toHost($dominio->url);
        $root = DomainNormalizer::rootDomainSimple($host);
        $today = now()->toDateString();

        // ✅ hard cap de seguridad para no gastar cuota por accidente
        $limit = min((int)$this->refDomainsMax, 10);

        try {
            // 1) Snapshot url_metrics (1 llamada)
            $urlMetrics = $moz->urlMetrics([$host]);
            $item = data_get($urlMetrics, 'results.0', []);

            $da = data_get($item, 'domain_authority');
            $pa = data_get($item, 'page_authority');
            $spam = data_get($item, 'spam_score');

            $backlinksTotal  = data_get($item, 'pages_to_root_domain');
            $refDomainsTotal = data_get($item, 'root_domains_to_root_domain');

            // 2) Guardar snapshot diario (tu BD)
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

            // 3) Serie diaria desde BD (respeta el periodo del reporte)
            $start = $report->period_start
                ? \Carbon\Carbon::parse($report->period_start)->toDateString()
                : now()->subDays(60)->toDateString();

            $snapshots = MozDailyMetric::query()
                ->where('id_dominio', $dominio->id_dominio)
                ->where('date', '>=', $start)
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

            // 4) Ref domains (Top 10) — 1 llamada como máximo
            $refResp = $moz->linkingRootDomains(
                target: $root,
                targetScope: 'root_domain',
                limit: $limit, // ✅ ahora 10 fijo (o menos si pasas <10)
                nextToken: null,
                filter: 'external',
                sort: 'source_domain_authority'
            );

            $rows = data_get($refResp, 'results', []);
            $refList = is_array($rows) ? $this->normalizeRefDomainsFromLRD($rows) : [];

            // ✅ hard cap al guardar también
            $refList = array_slice($refList, 0, $limit);

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

                        'ref_domains_list' => $refList,
                        'ref_domains_raw' => $refResp,
                        'ref_domains_source' => 'linking_root_domains',

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
                1;

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
}
