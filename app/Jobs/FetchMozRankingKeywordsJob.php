<?php

namespace App\Jobs;

use App\Models\SeoReport;
use App\Models\SeoReportSection;
use App\Models\DominiosModel;
use App\Services\MozJsonRpc;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\FetchSerperRankingKeywordsJob;
use Throwable;

class FetchMozRankingKeywordsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;
    public int $timeout = 180;

    /**
     * OJO:
     * El endpoint de ranking keywords (siteRankingKeywordList) SOLO acepta:
     * en-US, en-GB, en-CA, en-AU
     */
    private string $fixedLocale = 'en-US';

    public function __construct(
        public int $reportId,
        public array $keywords = [],
        public string $locale = 'es-ES',
        public int $limitePorPagina = 50,
        public int $maxResultados = 50,
        public int $maxKwParaScore = 15,
        public int $cacheDays = 30
    ) {}

    public function handle(MozJsonRpc $moz): void
    {
                Log::info('MOZ JOB: handle() INICIO', [
            'reportId' => $this->reportId,
            'locale' => $this->locale,
        ]);
        if (env('MOZ_MOCK', false)) return;

        $report  = SeoReport::findOrFail($this->reportId);
        $dominio = DominiosModel::findOrFail($report->id_dominio);

        $host = $this->limpiarHost($dominio->url);

        // Locale pedido por el usuario (selector)
        $locale = $this->locale ?: 'es-ES';

        // ✅ keywords limpias (para fallback SIEMPRE)
        $kwList = $this->sanitizeKeywords($this->keywords ?? []);
        $kwList = array_slice($kwList, 0, max(1, (int)$this->maxKwParaScore));

        // ✅ ranking REAL solo funciona para estos locales
        $allowedRankingLocales = ['en-US','en-GB','en-CA','en-AU'];
        $canRankingReal = in_array($locale, $allowedRankingLocales, true);

        // =========================
        // 0) Si NO se puede ranking real en Moz -> intentar SERPER primero
        // =========================
        if (!$canRankingReal) {

            // 1) Intentar SERPER primero (SERP real)
            try {
                Log::info('MOZ JOB: intentando SERPER fallback', [
                'reportId' => $report->id,
                'host' => $host,
                'locale' => $this->locale,
                'kwCount' => count($kwList),
            ]);
                // OJO: usa llamada POSICIONAL (ver BUG #2)
                FetchSerperRankingKeywordsJob::dispatchSync(
                    $report->id,
                    $host,
                    $kwList,
                    $this->locale,   // <-- usa el locale real del usuario (es-ES)
                    10,
                    10,
                    $this->maxKwParaScore
                );

                $serperSection = SeoReportSection::where('seo_report_id', $report->id)
                    ->where('section', 'serper_ranking_keywords')
                    ->first();

                $serperRanking = collect(data_get($serperSection?->payload, 'ranking', []));
                $serperFoundAny = $serperRanking->contains(fn($x) => !empty($x['found']));

                if ($serperFoundAny) {
                    SeoReportSection::updateOrCreate(
                        ['seo_report_id' => $report->id, 'section' => 'moz_ranking_keywords'],
                        [
                            'status' => 'ok',
                            'error_message' => null,
                            'payload' => [
                                'target' => $host,
                                'scope' => 'domain',
                                'locale' => $this->locale,
                                'modo' => 'serper',
                                'nota' => 'Moz ranking real no soporta este locale. Ranking consultado en Google SERP usando Serper.',
                                'total_guardados' => $serperRanking->count(),
                                'ranking' => $serperRanking->values()->all(),
                                'rows' => [],
                            ],
                        ]
                    );
                    return;
                }
            } catch (\Throwable $e) {
                // si Serper falla, cae a estimado
            }

            // 2) Si Serper no encontró -> Estimado
            $rankingEstimado = $this->armarRankingEstimado($report->id, $kwList);

            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'moz_ranking_keywords'],
                [
                    'status' => 'ok',
                    'error_message' => null,
                    'payload' => [
                        'target' => $host,
                        'scope' => 'domain',
                        'locale' => $this->locale,
                        'modo' => 'estimado',
                        'nota' => 'Moz ranking real no soporta este locale y Serper no encontró el dominio en el top consultado. Ranking estimado.',
                        'total_guardados' => count($rankingEstimado),
                        'ranking' => $rankingEstimado,
                        'rows' => [],
                    ],
                ]
            );

            return;
        }

        $n = 0;
        $rows = [];

        try {
            // =========================
            // 1) INTENTO: ranking real (Moz)
            // =========================
            while (count($rows) < (int)$this->maxResultados) {
                $resp = $moz->siteRankingKeywordList(
                    query: $host,
                    scope: 'domain',
                    locale: $locale,
                    n: $n,
                    limit: (int)$this->limitePorPagina,
                    sort: 'rank'
                );

                $items = data_get($resp, 'ranking_keywords', []);
                if (!is_array($items) || empty($items)) break;

                foreach ($items as $it) {
                    $rows[] = [
                        'keyword'        => $it['keyword'] ?? null,
                        'ranking_page'   => $it['ranking_page'] ?? null,
                        'rank_position'  => $it['rank_position'] ?? null,
                        'difficulty'     => $it['difficulty'] ?? null,
                        'volume'         => $it['volume'] ?? null,
                        'raw'            => $it,
                    ];

                    if (count($rows) >= (int)$this->maxResultados) break;
                }

                if (count($items) < (int)$this->limitePorPagina) break;
                $n++;
            }

            // ✅ Si Moz devolvió ranking real -> úsalo
            if (!empty($rows)) {
                $ranking = $this->armarRankingSimple($rows);

                SeoReportSection::updateOrCreate(
                    ['seo_report_id' => $report->id, 'section' => 'moz_ranking_keywords'],
                    [
                        'status' => 'ok',
                        'error_message' => null,
                        'payload' => [
                            'target' => $host,
                            'scope' => 'domain',
                            'locale' => $locale,
                            'modo' => 'real',
                            'page_limit' => (int)$this->limitePorPagina,
                            'max_resultados' => (int)$this->maxResultados,
                            'total_guardados' => count($rows),
                            'ranking' => $ranking,
                            'rows' => $rows,
                        ],
                    ]
                );
                return;
            }

            // =========================
            // 2) SI NO HAY ROWS REALES -> intentar SERPER (SERP real)
            // =========================
            if ($this->trySerperFallback($report->id, $host, $kwList, $locale)) {
                return;
            }

            // =========================
            // 3) SERPER no encontró -> fallback estimado
            // =========================
            $rankingEstimado = $this->armarRankingEstimado($report->id, $kwList);

            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'moz_ranking_keywords'],
                [
                    'status' => 'ok',
                    'error_message' => null,
                    'payload' => [
                        'target' => $host,
                        'scope' => 'domain',
                        'locale' => $locale,
                        'modo' => 'estimado',
                        'nota' => 'Moz no devolvió posiciones reales (sin data) y Serper no encontró el dominio en el top consultado. Ranking estimado.',
                        'total_guardados' => count($rankingEstimado),
                        'ranking' => $rankingEstimado,
                        'rows' => [],
                    ],
                ]
            );

        } catch (Throwable $e) {
            $msg = $e->getMessage();

            // ✅ fallback si Moz falla por locale/404/cuota -> intentar SERPER antes del estimado
            if (
                str_contains($msg, 'Locale must be one of') ||
                str_contains($msg, 'Ranking keywords not found') ||
                str_contains($msg, 'insufficient quota') ||
                str_contains($msg, 'insufficient-quota')
            ) {
                if ($this->trySerperFallback($report->id, $host, $kwList, $locale)) {
                    return;
                }

                $rankingEstimado = $this->armarRankingEstimado($report->id, $kwList);

                SeoReportSection::updateOrCreate(
                    ['seo_report_id' => $report->id, 'section' => 'moz_ranking_keywords'],
                    [
                        'status' => 'ok',
                        'error_message' => null,
                        'payload' => [
                            'target' => $host,
                            'scope' => 'domain',
                            'locale' => $locale,
                            'modo' => 'estimado',
                            'nota' => 'Moz no devolvió posiciones reales (locale/404/cuota) y Serper no encontró el dominio. Mostrando ranking estimado.',
                            'total_guardados' => count($rankingEstimado),
                            'ranking' => $rankingEstimado,
                            'rows' => [],
                        ],
                    ]
                );
                return;
            }

            Log::error('Moz Ranking Keywords Job Error', [
                'report_id' => $report->id,
                'domain_id' => $dominio->id_dominio,
                'target' => $host,
                'locale' => $locale,
                'message' => $msg,
            ]);

            SeoReportSection::updateOrCreate(
                ['seo_report_id' => $report->id, 'section' => 'moz_ranking_keywords'],
                [
                    'status' => 'error',
                    'error_message' => $msg,
                    'payload' => null,
                ]
            );
        }
    }

    /**
     * ✅ Intenta Serper y si encuentra AL MENOS 1 keyword, guarda modo=serper en moz_ranking_keywords
     * Retorna true si se usó Serper como fallback.
     */
    private function trySerperFallback(int $reportId, string $host, array $kwList, string $locale): bool
    {
        try {
            Log::info('SERPER fallback attempt', [
                'report_id' => $reportId,
                'host' => $host,
                'locale' => $locale,
                'kw_count' => count($kwList),
            ]);

            // dispatchSync para decidir en caliente y guardar en BD
            \App\Jobs\FetchSerperRankingKeywordsJob::dispatchSync(
                reportId: $reportId,
                domain: $host,
                keywords: $kwList,
                locale: $locale,          // ✅ usar locale real (es-ES, etc)
                pagesToCheck: 5,
                resultsPerPage: 10,
                maxKeywords: $this->maxKwParaScore
            );

            $serperSection = SeoReportSection::where('seo_report_id', $reportId)
                ->where('section', 'serper_ranking_keywords')
                ->first();

            $serperRanking = collect(data_get($serperSection?->payload, 'ranking', []));
            $foundAny = $serperRanking->contains(fn($x) => !empty($x['found']));

            if ($foundAny) {
                SeoReportSection::updateOrCreate(
                    ['seo_report_id' => $reportId, 'section' => 'moz_ranking_keywords'],
                    [
                        'status' => 'ok',
                        'error_message' => null,
                        'payload' => [
                            'target' => $host,
                            'scope' => 'domain',
                            'locale' => $locale,
                            'modo' => 'serper',
                            'nota' => 'Moz no devolvió ranking real. Ranking consultado en Google SERP usando Serper.',
                            'total_guardados' => $serperRanking->count(),
                            'ranking' => $serperRanking->values()->all(),
                            'rows' => [],
                        ],
                    ]
                );
                return true;
            }

        } catch (\Throwable $e) {
            Log::warning('SERPER fallback failed', [
                'report_id' => $reportId,
                'host' => $host,
                'locale' => $locale,
                'message' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Ranking "real" (cuando Moz devuelve rank_position / volume / difficulty)
     */
    private function armarRankingSimple(array $rows): array
    {
        $ctr = [
            1 => 0.30, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05,
            6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018,
        ];

        $out = [];

        foreach ($rows as $r) {
            $pos = (int)($r['rank_position'] ?? 0);
            $vol = is_numeric($r['volume'] ?? null) ? (float)$r['volume'] : 0.0;
            $dif = is_numeric($r['difficulty'] ?? null) ? (float)$r['difficulty'] : null;

            $ctrPos = $ctr[$pos] ?? ($pos >= 11 && $pos <= 20 ? 0.01 : 0.005);
            $traficoEstimado = $vol * $ctrPos;
            $factorDif = ($dif === null) ? 1.0 : (1.0 - min(max($dif, 0.0), 100.0) / 100.0);
            $score = $traficoEstimado * $factorDif;

            $out[] = $r + [
                'ctr_estimado' => $ctrPos,
                'trafico_estimado' => (int) round($traficoEstimado),
                'score' => (float) round($score, 4),
            ];
        }

        usort($out, fn($a, $b) => ($b['score'] <=> $a['score']));
        return array_slice($out, 0, 100);
    }

    private function armarRankingEstimado(int $reportId, array $kwList): array
    {
        return $this->armarRankingEstimadoDesdeSecciones($reportId, $kwList);
    }

    private function armarRankingEstimadoDesdeSecciones(int $reportId, array $kwList): array
    {
        $secMoz = SeoReportSection::where('seo_report_id', $reportId)
            ->where('section', 'moz')
            ->first();

        $secKw  = SeoReportSection::where('seo_report_id', $reportId)
            ->where('section', 'moz_keywords')
            ->first();

        $mozData = ($secMoz && $secMoz->status === 'ok') ? ($secMoz->payload ?? []) : [];
        $kwData  = ($secKw && $secKw->status === 'ok') ? ($secKw->payload ?? []) : [];

        $da = $this->aNumero(data_get($mozData, 'domain_authority', null));

        $rows = data_get($kwData, 'rows', []);
        if (!is_array($rows)) $rows = [];

        $map = [];
        foreach ($rows as $r) {
            $k = trim((string)($r['keyword'] ?? ''));
            if ($k === '') continue;
            $map[$this->normalizeKey($k)] = $r;
        }

        $kws = $kwList;
        if (!is_array($kws) || empty($kws)) $kws = $this->keywords ?? [];
        $kws = $this->sanitizeKeywords($kws);
        $kws = array_slice($kws, 0, max(1, $this->maxKwParaScore));

        $out = [];

        foreach ($kws as $kw) {
            $key = $this->normalizeKey($kw);
            $r = $map[$key] ?? [];

            $vol = $this->aNumero($r['volume'] ?? null);
            $dif = $this->aNumero($r['difficulty'] ?? null);
            $pri = $this->aNumero($r['priority'] ?? null);

            $vol = $vol ?? $this->estimarVolumenBasico($kw);
            $dif = $dif ?? $this->estimarDificultadBasica($kw);

            $score = $this->calcularScoreEstimado($da, $dif, $vol, $pri);
            $posRango = $this->estimarRangoPosicion($da, $dif, $vol, $pri);

            $out[] = [
                'keyword' => $kw,
                'rank_position' => null,
                'posicion_estimada' => $posRango,
                'ranking_page' => null,
                'volume' => $r['volume'] ?? null,
                'difficulty' => $r['difficulty'] ?? null,
                'priority' => $r['priority'] ?? null,
                'score' => $score,
                'nota' => 'Estimado',
            ];
        }

        usort($out, fn($a,$b) => ($b['score'] <=> $a['score']));
        return array_slice($out, 0, 100);
    }

    private function aNumero($v): ?float
    {
        if ($v === null) return null;
        if (is_numeric($v)) return (float)$v;
        $s = trim((string)$v);
        if ($s === '' || !is_numeric($s)) return null;
        return (float)$s;
    }

    private function calcularScoreEstimado(?float $da, ?float $difficulty, float $volume, ?float $priority): float
    {
        if ($priority !== null) {
            $daFactor = ($da === null) ? 1.0 : (0.8 + min(max($da, 0.0), 100.0)/100.0*0.4);
            return (float) round($priority * $daFactor, 4);
        }

        $daV = $da ?? 30.0;
        $dif = $difficulty ?? 60.0;

        $fuerza = $daV - $dif;
        $fuerzaNorm = max(min(($fuerza + 50.0)/100.0, 1.0), 0.0);

        return (float) round($volume * (0.2 + 0.8*$fuerzaNorm), 4);
    }

    private function estimarRangoPosicion(?float $da, ?float $difficulty, float $volume, ?float $priority): string
    {
        $daV = $da ?? 30.0;
        $dif = $difficulty ?? 60.0;

        $fuerza = $daV - $dif;

        if ($volume >= 5000) $fuerza -= 10;
        elseif ($volume >= 1000) $fuerza -= 5;

        if ($priority !== null) {
            if ($priority >= 70) $fuerza += 10;
            elseif ($priority >= 50) $fuerza += 5;
        }

        if ($fuerza >= 25) return '1-10';
        if ($fuerza >= 10) return '11-30';
        if ($fuerza >= -5) return '31-50';
        return '50+';
    }

    private function sanitizeKeywords(array $keywords): array
    {
        $out = [];
        $seen = [];

        foreach ($keywords as $k) {
            $k = trim((string)$k);
            if ($k === '') continue;

            $k = mb_substr($k, 0, 120);

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
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private function limpiarHost(string $url): string
    {
        $url = trim($url);
        if (!Str::startsWith($url, ['http://','https://'])) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = preg_replace('/^www\./i', '', $host);

        return strtolower($host);
    }

    private function estimarVolumenBasico(string $kw): float
    {
        $words = preg_split('/\s+/', trim($kw)) ?: [];
        $n = count(array_filter($words));

        if ($n <= 1) return 500;
        if ($n == 2) return 250;
        if ($n == 3) return 140;
        if ($n == 4) return 90;
        return 60;
    }

    private function estimarDificultadBasica(string $kw): float
    {
        $words = preg_split('/\s+/', trim($kw)) ?: [];
        $n = count(array_filter($words));

        $s = mb_strtolower($kw);
        $isCommercial = str_contains($s, 'precio') || str_contains($s, 'barato') || str_contains($s, 'comprar');

        if ($n <= 1) return $isCommercial ? 75 : 70;
        if ($n == 2) return $isCommercial ? 65 : 58;
        if ($n == 3) return 52;
        if ($n == 4) return 45;
        return 40;
    }
}
