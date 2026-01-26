<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Models\SeoReportSection;
use App\Services\MozJsonRpc;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchMozKeywordMetricsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    // ✅ fijo a España
    private string $fixedLocale = 'es-ES';

    public function __construct(
        public int $reportId,
        public array $keywords,
        public string $device = 'desktop',
        public string $engine = 'google',
        public int $maxKeywords = 15,      // ✅ baja esto si quieres ahorrar más (ej 10)
        public int $cacheDays = 30         // ✅ cache para no re-consultar lo mismo
    ) {}

    public function handle(MozJsonRpc $moz): void
    {
        if (env('MOZ_MOCK', false)) {
            $report = SeoReport::findOrFail($this->reportId);

            // opcional: guardar fake
            // $report->update([
            //   'moz_keywords_json' => json_encode(['mock' => true, 'count' => count($this->keywords)])
            // ]);

            return;
        }
        $report = SeoReport::findOrFail($this->reportId);

        // ✅ normaliza + quita duplicados (incluye tildes)
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
                        'note' => 'No hay keywords configuradas.',
                        'locale' => $this->fixedLocale,
                    ],
                ]
            );
            return;
        }

        // ✅ límite real (para cuota)
        $keywords = array_slice($keywords, 0, $this->maxKeywords);

        $rows = [];
        $noDataCount = 0;

        foreach ($keywords as $kw) {
            $cacheKey = $this->cacheKey($kw);

            // ✅ cache: evita gastar cuota si ya consultaste esta keyword recientemente
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $rows[] = $cached + [
                    'keyword' => $kw,
                    'locale' => $this->fixedLocale,
                    'cached' => true,
                ];
                continue;
            }

            try {
                $result = $moz->keywordMetrics($kw, $this->fixedLocale, $this->device, $this->engine);
                $km = data_get($result, 'keyword_metrics', []);

                // si viene vacío o todo null -> lo tratamos como "sin datos"
                $allNull = true;
                foreach (['volume','difficulty','organic_ctr','priority'] as $field) {
                    if (array_key_exists($field, $km) && $km[$field] !== null) {
                        $allNull = false;
                        break;
                    }
                }

                if (!is_array($km) || $allNull) {
                    $noDataCount++;
                    $row = [
                        'keyword' => $kw,
                        'locale' => $this->fixedLocale,
                        'volume' => null,
                        'difficulty' => null,
                        'organic_ctr' => null,
                        'priority' => null,
                        'note' => 'Sin datos en Moz (respuesta vacía)',
                        'cached' => false,
                    ];
                    $rows[] = $row;

                    Cache::put($cacheKey, $row, now()->addDays($this->cacheDays));
                    continue;
                }

                $row = [
                    'keyword' => $kw,
                    'locale' => $this->fixedLocale,
                    'volume' => $km['volume'] ?? null,
                    'difficulty' => $km['difficulty'] ?? null,
                    'organic_ctr' => $km['organic_ctr'] ?? null,
                    'priority' => $km['priority'] ?? null,
                    'note' => null,
                    'cached' => false,
                ];

                $rows[] = $row;
                Cache::put($cacheKey, $row, now()->addDays($this->cacheDays));

            } catch (Throwable $e) {
                $msg = $e->getMessage();

                // ✅ sin cuota: corta YA para no quemar más
                if (str_contains($msg, 'insufficient quota') || str_contains($msg, 'insufficient-quota')) {
                    SeoReportSection::updateOrCreate(
                        ['seo_report_id' => $report->id, 'section' => 'moz_keywords'],
                        [
                            'status' => 'error',
                            'error_message' => 'Moz Keywords: sin cuota disponible en este periodo.',
                            'payload' => null,
                        ]
                    );
                    return;
                }

                // ✅ 404 / no data: guarda fila sin datos (1 sola llamada, no reintenta otros locales)
                if (str_contains($msg, 'No keyword metrics found') || str_contains($msg, '404')) {
                    $noDataCount++;
                    $row = [
                        'keyword' => $kw,
                        'locale' => $this->fixedLocale,
                        'volume' => null,
                        'difficulty' => null,
                        'organic_ctr' => null,
                        'priority' => null,
                        'note' => 'Sin datos en Moz (404)',
                        'last_error' => $msg,
                        'cached' => false,
                    ];
                    $rows[] = $row;
                    Cache::put($cacheKey, $row, now()->addDays($this->cacheDays));
                    continue;
                }

                Log::warning('Moz keyword metrics error', [
                    'report_id' => $this->reportId,
                    'keyword' => $kw,
                    'locale' => $this->fixedLocale,
                    'msg' => $msg,
                ]);

                $noDataCount++;
                $row = [
                    'keyword' => $kw,
                    'locale' => $this->fixedLocale,
                    'volume' => null,
                    'difficulty' => null,
                    'organic_ctr' => null,
                    'priority' => null,
                    'note' => 'Error consultando Moz',
                    'last_error' => $msg,
                    'cached' => false,
                ];
                $rows[] = $row;
                Cache::put($cacheKey, $row, now()->addDays(3));
            }
        }

        SeoReportSection::updateOrCreate(
            ['seo_report_id' => $report->id, 'section' => 'moz_keywords'],
            [
                'status' => 'ok',
                'error_message' => null,
                'payload' => [
                    'device' => $this->device,
                    'engine' => $this->engine,
                    'locale' => $this->fixedLocale,
                    'rows' => $rows,
                    'no_data_count' => $noDataCount,
                    'max_keywords' => $this->maxKeywords,
                    'cache_days' => $this->cacheDays,
                ],
            ]
        );
    }

    private function cacheKey(string $kw): string
    {
        $norm = $this->normalizeKey($kw);
        return "moz_kw:{$this->device}:{$this->engine}:{$this->fixedLocale}:" . sha1($norm);
    }

    private function sanitizeKeywords(array $keywords): array
    {
        $out = [];
        $seen = [];

        foreach ($keywords as $k) {
            $k = trim((string) $k);
            if ($k === '') continue;

            // corta largo
            $k = mb_substr($k, 0, 120);

            // ✅ dedupe por clave normalizada (sin tildes, minúsculas, etc.)
            $key = $this->normalizeKey($k);
            if (isset($seen[$key])) continue;

            $seen[$key] = true;
            $out[] = $k;
        }

        return array_values($out);
    }

    private function normalizeKey(string $s): string
    {
        $s = mb_strtolower($s);
        // quita tildes
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        // colapsa espacios
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }
}
