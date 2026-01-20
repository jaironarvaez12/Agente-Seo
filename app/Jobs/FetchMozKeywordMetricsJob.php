<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Models\SeoReportSection;
use App\Services\MozJsonRpc;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchMozKeywordMetricsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    public function __construct(
        public int $reportId,
        public array $keywords,
        public string $device = 'desktop',
        public string $engine = 'google'
    ) {}

    public function handle(MozJsonRpc $moz): void
    {
        $report = SeoReport::findOrFail($this->reportId);

        $keywords = $this->sanitizeKeywords($this->keywords);
        if (empty($keywords)) {
            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'moz_keywords'],
                [
                    'status' => 'ok',
                    'error_message' => null,
                    'payload' => [
                        'device' => $this->device,
                        'engine' => $this->engine,
                        'rows' => [],
                        'no_data_count' => 0,
                        'note' => 'No hay keywords configuradas en dominios_contenido',
                    ],
                ]
            );
            return;
        }

        // Limita por cuota (ajusta si quieres)
        $keywords = array_slice($keywords, 0, 25);

        $rows = [];
        $noDataCount = 0;
        $quotaStop = false;

        foreach ($keywords as $kw) {
            $localesToTry = ['es-ES', 'es-MX', 'en-US'];

            $metrics = null;
            $usedLocale = null;
            $tried = [];
            $lastErr = null;

            foreach ($localesToTry as $locale) {
                $tried[] = $locale;

                try {
                    $result = $moz->keywordMetrics($kw, $locale, $this->device, $this->engine);
                    $km = data_get($result, 'keyword_metrics', []);

                    // ✅ si la respuesta existe pero TODO es null -> no sirve, intenta otro locale
                    $allNull = true;
                    foreach (['volume','difficulty','organic_ctr','priority'] as $field) {
                        if (array_key_exists($field, $km) && $km[$field] !== null) {
                            $allNull = false;
                            break;
                        }
                    }

                    if (is_array($km) && !$allNull) {
                        $metrics = $km;
                        $usedLocale = $locale;
                        break;
                    }

                    // si llegó aquí es porque es todo null -> seguimos probando
                    $lastErr = 'Moz devolvió métricas null (sin datos) para este locale.';
                    continue;

                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    $lastErr = $msg;

                    // ✅ 404: no hay datos -> intenta siguiente locale
                    if (str_contains($msg, 'No keyword metrics found')) {
                        continue;
                    }

                    // ✅ sin cuota: detén todo (no gastes más)
                    if (str_contains($msg, 'insufficient quota') || str_contains($msg, 'insufficient-quota')) {
                        $quotaStop = true;
                        break;
                    }

                    // otros errores: log y rompe para esta keyword, sigue con la siguiente
                    Log::warning('Moz keyword metrics error', [
                        'report_id' => $this->reportId,
                        'keyword' => $kw,
                        'locale' => $locale,
                        'msg' => $msg,
                    ]);
                    break;
                }
            }

            // ✅ si no hay cuota, guardamos sección como OK pero con nota (o error si prefieres)
            if ($quotaStop) {
                SeoReportSection::updateOrCreate(
                    ['seo_report_id' => $report->id, 'section' => 'moz_keywords'],
                    [
                        'status' => 'error',
                        'error_message' => 'Moz Keywords: sin cuota disponible en este periodo (Moz Search API / JSON-RPC).',
                        'payload' => null,
                    ]
                );
                return;
            }

            if (!$metrics) {
                $noDataCount++;
                $rows[] = [
                    'keyword' => $kw,
                    'locale' => $usedLocale,
                    'volume' => null,
                    'difficulty' => null,
                    'organic_ctr' => null,
                    'priority' => null,
                    'note' => 'Sin datos en Moz para esta keyword',
                    'tried_locales' => $tried,
                    'last_error' => $lastErr,
                ];
                continue;
            }

            $rows[] = [
                'keyword' => $kw,
                'locale' => $usedLocale,
                'volume' => $metrics['volume'] ?? null,
                'difficulty' => $metrics['difficulty'] ?? null,
                'organic_ctr' => $metrics['organic_ctr'] ?? null,
                'priority' => $metrics['priority'] ?? null,
                'note' => null,
                'tried_locales' => $tried,
                'last_error' => null,
            ];
        }

        SeoReportSection::updateOrCreate(
            ['seo_report_id' => $report->id, 'section' => 'moz_keywords'],
            [
                'status' => 'ok',
                'error_message' => null,
                'payload' => [
                    'device' => $this->device,
                    'engine' => $this->engine,
                    'rows' => $rows,
                    'no_data_count' => $noDataCount,
                ],
            ]
        );
    }

    private function sanitizeKeywords(array $keywords): array
    {
        $out = [];
        foreach ($keywords as $k) {
            $k = trim((string) $k);
            if ($k === '') continue;
            $k = mb_substr($k, 0, 120);
            $out[] = $k;
        }
        return array_values(array_unique($out));
    }
}
