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
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchPageSpeedSectionJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(public int $reportId) {}

    public function handle(): void
    {
        if (env('MOZ_MOCK', false)) {
            $report = SeoReport::findOrFail($this->reportId);

            // opcional: guardar fake
            // $report->update([
            //   'moz_keywords_json' => json_encode(['mock' => true, 'count' => count($this->keywords)])
            // ]);

            return;
        }
        try {
            $report = SeoReport::findOrFail($this->reportId);
            $dominio = DominiosModel::findOrFail($report->id_dominio);

            $url = DomainNormalizer::toBaseUrl($dominio->url);
            $key = (string) config('services.pagespeed.key', '');

            if (!$key) {
                $this->saveError('No hay API key de PageSpeed (services.pagespeed.key)');
                return;
            }

            $mobile = $this->runPsiSafe($url, 'mobile', $key);
            $desktop = $this->runPsiSafe($url, 'desktop', $key);

            if (!$mobile && !$desktop) {
                $this->saveError('Lighthouse/PSI falló en mobile y desktop (sin resultados).');
                return;
            }

            $payload = [
                'url' => $url,
                'mobile' => $mobile ? $this->extractPsi($mobile) : null,
                'desktop' => $desktop ? $this->extractPsi($desktop) : null,

                // ✅ RAW MINIMO (no guardes lighthouseResult completo)
                'debug' => [
                    'mobile' => $mobile ? [
                        'finalUrl' => data_get($mobile, 'lighthouseResult.finalUrl'),
                        'fetchTime' => data_get($mobile, 'lighthouseResult.fetchTime'),
                    ] : null,
                    'desktop' => $desktop ? [
                        'finalUrl' => data_get($desktop, 'lighthouseResult.finalUrl'),
                        'fetchTime' => data_get($desktop, 'lighthouseResult.fetchTime'),
                    ] : null,
                ],
            ];

            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'pagespeed'],
                ['status' => 'ok', 'error_message' => null, 'payload' => $payload]
            );

        } catch (Throwable $e) {
            Log::error('FetchPageSpeedSectionJob hard error', [
                'report_id' => $this->reportId,
                'message' => $e->getMessage(),
            ]);

            // ✅ NO fail: guarda error y termina normal
            $this->saveError($e->getMessage());
            return;
        }
    }

    private function runPsiSafe(string $url, string $strategy, string $key): ?array
    {
        try {
            $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

            $resp = Http::timeout(120)
                ->retry(1, 1000)
                ->get($endpoint, [
                    'url' => $url,
                    'strategy' => $strategy,
                    'key' => $key,
                ]);

            if (!$resp->successful()) {
                Log::warning("PSI {$strategy} failed", [
                    'status' => $resp->status(),
                    'body' => substr($resp->body(), 0, 1200), // ✅ no log gigantesco
                ]);
                return null;
            }

            return $resp->json();
        } catch (Throwable $e) {
            Log::warning("PSI {$strategy} exception", ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function saveError(string $msg): void
    {
        SeoReportSection::updateOrCreate(
            ['seo_report_id' => $this->reportId, 'section' => 'pagespeed'],
            ['status' => 'error', 'error_message' => $msg, 'payload' => null]
        );
    }

    private function extractPsi(array $data): array
    {
        $lhr = data_get($data, 'lighthouseResult', []);
        $score = data_get($lhr, 'categories.performance.score');
        $score = is_numeric($score) ? (int) round($score * 100) : null;

        return [
            'score' => $score,
            'lcp' => data_get($lhr, 'audits.largest-contentful-paint.displayValue'),
            'cls' => data_get($lhr, 'audits.cumulative-layout-shift.displayValue'),
            'inp' => data_get($lhr, 'audits.interaction-to-next-paint.displayValue')
                ?? data_get($lhr, 'audits.total-blocking-time.displayValue'),
        ];
    }
}
