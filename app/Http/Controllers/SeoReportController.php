<?php

namespace App\Http\Controllers;

use App\Models\DominiosModel;
use App\Models\Dominios_ContenidoModel;
use App\Models\SeoReport;
use App\Jobs\FetchMozSectionJob;
use App\Jobs\RunTechAuditSectionJob;
use App\Jobs\FetchPageSpeedSectionJob;
use App\Jobs\FinalizeSeoReportJob;
use App\Jobs\FetchMozKeywordMetricsJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\ChartImageService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use App\Services\LicenseService;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Http\Request; 
// fakes
use App\Jobs\FakeFetchMozSectionJob;
use App\Jobs\FakeFetchMozKeywordMetricsJob;
use App\Jobs\FakeFetchPageSpeedSectionJob;
class SeoReportController extends Controller
{

    private function hostFromUrl(string $url): string
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        // fallback
        return $host ?: rtrim(preg_replace('#^https?://#i', '', $url), '/');
    }
    public function generar(int $id, Request $request, LicenseService $licenses)
{
    try {
        $user = auth()->user();
        if (!$user) return back()->withError('Debes iniciar sesión.');

        // ✅ ADMIN por rol (Spatie). Ajusta el nombre exacto del rol:
        $esAdmin = $user->hasRole('administrador'); // 'Administrador', 'superadmin', etc.

        // Solo se usan si NO es admin
        $titular = null;
        $licensePlain = null;
        $emailLicencia = null;

        if (!$esAdmin) {
            $titular = $user->titularLicencia();
            if (!$titular) return back()->withError('No se encontró el titular de la licencia.');

            $licensePlain = $titular->getLicenseKeyPlain();
            if (!$licensePlain) return back()->withError('El titular no tiene licencia registrada.');

            $emailLicencia = $titular->license_email ?? $titular->email;
        }

        [$ok, $msg, $redirectUrl] = DB::transaction(function () use (
            $id, $request, $licenses, $licensePlain, $user, $titular, $emailLicencia, $esAdmin
        ) {

            // ============================
            // 0) Cargar dominio
            // ============================
            $dominio = DominiosModel::where('id_dominio', (int)$id)
                ->lockForUpdate()
                ->first();

            if (!$dominio) {
                return [false, 'Dominio no encontrado.', null];
            }

            // ============================
            // 1) Permiso dominio (solo NO admin)
            // ============================
            if (!$esAdmin) {
                $dominiosIdsDelUser = DB::table('dominios_usuarios')
                    ->where('id_usuario', (int) $user->id)
                    ->pluck('id_dominio')
                    ->map(fn($v) => (int)$v)
                    ->all();

                if (!in_array((int)$id, $dominiosIdsDelUser, true)) {
                    return [false, 'No tienes permiso para generar reportes en este dominio.', null];
                }
            }

            // ✅ statuses que consumen cupo
            $statusesQueConsumenCupo = ['encolado', 'generando', 'en_proceso', 'ok', 'generado'];

            // ============================
            // 2) Licencia / límites (solo NO admin)
            // ============================
            if ($esAdmin) {
                $plan = 'admin';
                $desde = now()->subYears(50);
                $hasta = null;

                // Para mensaje final (no aplica realmente)
                $maxReportsPerDomain = PHP_INT_MAX;
                $maxActiveDomains = PHP_INT_MAX;

                // Para mensaje final
                $ocupadosDominio = 0;
                $ocupadosGlobal  = 0;
                $maxGlobal = PHP_INT_MAX;
            } else {
                $host = $this->hostFromUrl($dominio->url);

                $planResp = $licenses->getPlanLimitsAuto($licensePlain, $host, $emailLicencia);
                $plan   = (string) ($planResp['plan'] ?? 'free');
                $limits = $licenses->normalizeLimits($plan, (array) ($planResp['limits'] ?? []));

                $maxReportsPerDomain = (int) ($limits['max_report'] ?? ($limits['max_reports'] ?? 0));
                if ($maxReportsPerDomain <= 0) {
                    return [false, "Tu plan ($plan) no permite generar reportes SEO o el dominio no está activado.", null];
                }

                $maxActiveDomains = (int) ($limits['max_activations'] ?? 0);
                if ($maxActiveDomains <= 0) {
                    return [false, "Tu plan ($plan) no permite activar dominios (max_activations inválido).", null];
                }

                [$desde, $hasta, $w] = $licenses->licenseUsageRange($planResp);

                if (!$w['is_active']) {
                    $endTxt = $w['end'] ? $w['end']->setTimezone(config('app.timezone'))->format('d/m/Y h:i A') : 'N/D';
                    return [false, "Licencia inactiva o vencida. Expira: {$endTxt}. No puedes generar reportes.", null];
                }

                // =========================================================
                // 3) CUPO POR DOMINIO
                // =========================================================
                $ocupadosDominio = (int) SeoReport::where('id_dominio', (int)$dominio->id_dominio)
                    ->where('created_at', '>=', $desde)
                    ->when($hasta, fn($q) => $q->where('created_at', '<', $hasta))
                    ->whereIn('status', $statusesQueConsumenCupo)
                    ->count();

                if ($ocupadosDominio >= $maxReportsPerDomain) {
                    $tz = config('app.timezone');
                    $dTxt = $desde->copy()->setTimezone($tz)->format('d/m/Y h:i A');
                    $hTxt = $hasta ? $hasta->copy()->setTimezone($tz)->format('d/m/Y h:i A') : 'N/D';

                    return [false,
                        "Límite por dominio alcanzado: {$ocupadosDominio}/{$maxReportsPerDomain}. Ventana: {$dTxt} → {$hTxt} (plan {$plan}).",
                        null
                    ];
                }

                // =========================================================
                // 4) CUPO GLOBAL
                // =========================================================
                $maxGlobal = $maxActiveDomains * $maxReportsPerDomain;

                $dominiosIdsDelTitular = DB::table('dominios_usuarios')
                    ->where('id_usuario', (int) $titular->id)
                    ->pluck('id_dominio')
                    ->map(fn($v) => (int)$v)
                    ->all();

                $ocupadosGlobal = (int) SeoReport::whereIn('id_dominio', $dominiosIdsDelTitular)
                    ->where('created_at', '>=', $desde)
                    ->when($hasta, fn($q) => $q->where('created_at', '<', $hasta))
                    ->whereIn('status', $statusesQueConsumenCupo)
                    ->count();

                if ($ocupadosGlobal >= $maxGlobal) {
                    $tz = config('app.timezone');
                    $dTxt = $desde->copy()->setTimezone($tz)->format('d/m/Y h:i A');
                    $hTxt = $hasta ? $hasta->copy()->setTimezone($tz)->format('d/m/Y h:i A') : 'N/D';

                    return [false,
                        "Límite GLOBAL alcanzado: {$ocupadosGlobal}/{$maxGlobal} (= {$maxActiveDomains} dominios x {$maxReportsPerDomain} reportes). Ventana: {$dTxt} → {$hTxt} (plan {$plan}).",
                        null
                    ];
                }
            }

            // =========================================================
            // 5) Rango del reporte (preset)
            // =========================================================
            $preset = $request->input('preset', 'last_30');

            [$start, $end] = match ($preset) {
                'prev_month' => [
                    now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                    now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                ],
                'last_3m' => [now()->subMonths(3)->toDateString(), now()->toDateString()],
                'last_6m' => [now()->subMonths(6)->toDateString(), now()->toDateString()],
                'custom' => [
                    $request->input('period_start')
                        ? Carbon::parse($request->input('period_start'))->toDateString()
                        : now()->subDays(30)->toDateString(),
                    $request->input('period_end')
                        ? Carbon::parse($request->input('period_end'))->toDateString()
                        : now()->toDateString(),
                ],
                default => [now()->subDays(30)->toDateString(), now()->toDateString()],
            };

            if (Carbon::parse($end)->lt(Carbon::parse($start))) {
                return [false, 'El rango de fechas es inválido (fin < inicio).', null];
            }

            // =========================================================
            // 6) Crear reporte + jobs
            // =========================================================
            $report = SeoReport::create([
                'id_dominio'   => $dominio->id_dominio,
                'period_start' => $start,
                'period_end'   => $end,
                'status'       => 'generando',
            ]);

            Bus::chain([
                (new FetchMozSectionJob($report->id))->onQueue('reports'),
                (new RunTechAuditSectionJob($report->id, 200))->onQueue('reports'),
                (new FinalizeSeoReportJob($report->id))->onQueue('reports'),
            ])->catch(function (\Throwable $e) use ($report) {
                FinalizeSeoReportJob::dispatch($report->id)->onQueue('reports');
            })->dispatch();

            FetchPageSpeedSectionJob::dispatch($report->id)
                ->onQueue('pagespeed')
                ->delay(now()->addSeconds(20));

            $keywords = $this->keywordsFromDomain($dominio->id_dominio);

            FetchMozKeywordMetricsJob::dispatch($report->id, $keywords, 'desktop', 'google')
                ->onQueue('reports')
                ->delay(now()->addSeconds(5));

            // =========================================================
            // 7) Mensaje
            // =========================================================
            if ($esAdmin) {
                $msg = "Reporte SEO en generación. Periodo: {$start} a {$end}. (admin: sin límites de licencia)";
            } else {
                $tz = config('app.timezone');
                $dTxt = $desde->copy()->setTimezone($tz)->format('d/m/Y h:i A');
                $hTxt = $hasta ? $hasta->copy()->setTimezone($tz)->format('d/m/Y h:i A') : 'N/D';

                $msg = "Reporte SEO en generación. Periodo: {$start} a {$end}. "
                    . "Dominio: " . ($ocupadosDominio + 1) . "/{$maxReportsPerDomain}. "
                    . "Global: " . ($ocupadosGlobal + 1) . "/{$maxGlobal} (= {$maxActiveDomains} dominios x {$maxReportsPerDomain}). "
                    . "Ventana: {$dTxt} → {$hTxt} (plan {$plan}).";
            }

            return [true, $msg, route('dominios.reporte_seo.ver', $dominio->id_dominio)];
        });

        if (!$ok) return back()->withError($msg);

        return redirect($redirectUrl)->with('success', $msg);

    } catch (\Throwable $e) {
        return back()->withError('Error al generar reporte: ' . $e->getMessage());
    }
}

    public function ver($id_dominio)
    {
        $dominio = DominiosModel::findOrFail($id_dominio);

        $report = SeoReport::where('id_dominio', $dominio->id_dominio)
            ->orderByDesc('id')
            ->first();

        $sections = $report ? $report->sections()->get()->keyBy('section') : collect();

        // (opcional) si quieres seguir mostrando generadores en otra pantalla
        $generadores = Dominios_ContenidoModel::where('id_dominio', $dominio->id_dominio)->get();

        return view('Dominios.moz', compact('dominio', 'report', 'sections', 'generadores'));
    }

    /**
     * ✅ AQUÍ ES DONDE LO CAMBIAS (se llama desde generar())
     * Lee dominios_contenido.palabras_claves y genera lista para Moz Keywords.
     */
    private function keywordsFromDomain(int $idDominio, int $limit = 15): array
{
    $generadores = Dominios_ContenidoModel::where('id_dominio', $idDominio)->get();

    $keywords = [];
    $seen = [];

    foreach ($generadores as $g) {
        $text = trim((string)($g->palabras_claves ?? ''));
        if ($text === '') continue;

        // separa por coma, punto y coma, saltos de línea y "|" (por si acaso)
        $parts = preg_split('/[,;\n\r\|]+/u', $text);

        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;

            // ✅ normaliza espacios (evita "seo   " vs "seo")
            $p = preg_replace('/\s+/u', ' ', $p);
            $p = trim($p);

            // ✅ opcional: evita keywords ultra cortas tipo "a", "de"
            // if (mb_strlen($p) < 3) continue;

            // ✅ dedupe fuerte: minúsculas + sin tildes
            $key = $this->normalizeKeywordKey($p);
            if (isset($seen[$key])) continue;

            $seen[$key] = true;
            $keywords[] = $p;
        }
    }

    // ✅ límite por cuota
    return array_slice($keywords, 0, $limit);
}

private function normalizeKeywordKey(string $s): string
{
    $s = mb_strtolower($s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}
public function pdf($id_dominio)
{
    $dominio = DominiosModel::findOrFail($id_dominio);

    $report = SeoReport::where('id_dominio', $dominio->id_dominio)
        ->orderByDesc('id')
        ->firstOrFail();

    $sections = $report->sections()->get()->keyBy('section');

    $moz  = $sections->get('moz');
    $tech = $sections->get('tech');
    $psi  = $sections->get('pagespeed');
    $kw   = $sections->get('moz_keywords');

    $mozData  = ($moz  && $moz->status  === 'ok') ? ($moz->payload  ?? []) : [];
    $techData = ($tech && $tech->status === 'ok') ? ($tech->payload ?? []) : [];
    $psiData  = ($psi  && $psi->status  === 'ok') ? ($psi->payload  ?? []) : [];
    $kwData   = ($kw   && $kw->status   === 'ok') ? ($kw->payload   ?? []) : [];

    // Moz - tablas
    $daily = collect(data_get($mozData, 'daily', []))->take(-60)->values()->all();

    $refList = collect(data_get($mozData,'ref_domains_list', []))
        ->filter(fn($x) => !empty($x['root_domain']))
        ->take(100)->values()->all();

    // Keywords - tabla
    $kwRows = collect(data_get($kwData,'rows', []))->take(200)->values()->all();

    // Tech - páginas con problemas (top 50)
    $techPages = data_get($techData, 'pages', []);
    $badPages = collect($techPages)->filter(fn($p) =>
        ($p['http_code'] ?? 200) >= 400
        || empty($p['title'])
        || empty($p['meta_description'])
        || empty($p['h1'])
        || !empty($p['noindex'])
    )->take(50)->values()->all();

    // ✅ GRÁFICAS
    $charts = [
        // Moz line (nuevo look)
        'backlinks' => null,
        'refdomains' => null,

        // Moz bars
        'backlinks_delta' => null,
        'refdomains_delta' => null,

        // Pie
        'ref_new_vs_lost' => null,   // PIE (siempre visible)
        'tech_issues' => null,       // PIE

        // PSI
        'pagespeed_scores' => null,  // bar
        'cwv_mobile' => null,        // bar
        'cwv_desktop' => null,       // bar
    ];

    /** @var \App\Services\ChartImageService $chartService */
    $chartService = app(\App\Services\ChartImageService::class);

    $chartDir = "reports/seo/{$dominio->id_dominio}/{$report->id}";
    \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory($chartDir);

    // -------------------------
    // MOZ: line (estilo captura) + deltas + PIE new/lost (siempre)
    // -------------------------
    if (count($daily) >= 2) {
        // labels (si quieres estilo "ENE 2026", usa este bloque)
        $labels = collect($daily)->pluck('date')->map(function ($d) {
            try {
                $dt = \Carbon\Carbon::parse($d);
                return mb_strtoupper($dt->locale('es')->translatedFormat('M Y')); // "ENE 2026"
            } catch (\Throwable $e) {
                return (string)$d;
            }
        })->all();

        // line totals
        $backlinksSeries  = collect($daily)->pluck('backlinks_total')->map(fn($v) => (int)($v ?? 0))->all();
        $refDomainsSeries = collect($daily)->pluck('ref_domains_total')->map(fn($v) => (int)($v ?? 0))->all();

        // ✅ NUEVO LOOK (line + area gradient)
        $backlinksAbs = $chartService->makeLineAreaPng(
            "{$chartDir}/backlinks.png",
            $labels,
            $backlinksSeries,
            'BACKLINKS (TENDENCIA)',
            'Backlinks'
        );

        $refdomainsAbs = $chartService->makeLineAreaPng(
            "{$chartDir}/refdomains.png",
            $labels,
            $refDomainsSeries,
            'DOMINIOS DE REFERENCIA (TENDENCIA)',
            'Referring Domains'
        );

        $charts['backlinks'] = $this->imgToDataUri($backlinksAbs);
        $charts['refdomains'] = $this->imgToDataUri($refdomainsAbs);

        // bars: deltas
        $backlinksDelta  = collect($daily)->pluck('backlinks_delta')->map(fn($v) => is_null($v) ? 0 : (int)$v)->all();
        $refDomainsDelta = collect($daily)->pluck('ref_domains_delta')->map(fn($v) => is_null($v) ? 0 : (int)$v)->all();

        $bdAbs = $chartService->makeBarSimplePng("{$chartDir}/backlinks_delta.png", $labels, $backlinksDelta, 'Backlinks (Δ por día)');
        $rdAbs = $chartService->makeBarSimplePng("{$chartDir}/refdomains_delta.png", $labels, $refDomainsDelta, 'Ref Domains (Δ por día)');

        $charts['backlinks_delta'] = $this->imgToDataUri($bdAbs);
        $charts['refdomains_delta'] = $this->imgToDataUri($rdAbs);

        // PIE: nuevos vs perdidos (sumado del periodo)
        $newSeries  = collect($daily)->pluck('new_ref_domains')->map(fn($v) => (int)($v ?? 0))->all();
        $lostSeries = collect($daily)->pluck('lost_ref_domains')->map(fn($v) => (int)($v ?? 0))->all();

        $totalNew  = array_sum($newSeries);
        $totalLost = array_sum($lostSeries);

        // ✅ Si 0/0, generamos pie “Sin cambios” (porque [0,0] se renderiza vacío)
        if (($totalNew + $totalLost) === 0) {
            $nlAbs = $chartService->makePiePng(
                "{$chartDir}/ref_new_lost_pie.png",
                ['Sin cambios'],
                [1],
                'Ref Domains: Nuevos vs Perdidos (Periodo)'
            );
            $charts['ref_new_vs_lost'] = $this->imgToDataUri($nlAbs);
        } else {
            $nlAbs = $chartService->makePiePng(
                "{$chartDir}/ref_new_lost_pie.png",
                ['Nuevos', 'Perdidos'],
                [(int)$totalNew, (int)$totalLost],
                'Ref Domains: Nuevos vs Perdidos (Periodo)'
            );
            $charts['ref_new_vs_lost'] = $this->imgToDataUri($nlAbs);
        }
    }

    // -------------------------
    // TECH: PIE issues summary
    // -------------------------
    if (!empty($techData) && ($tech?->status === 'ok')) {
        $sum = data_get($techData, 'summary', []);

        $tLabels = ['4xx/5xx', 'Title falt.', 'Meta desc falt.', 'H1 falt.', 'Noindex'];
        $tSeries = [
            (int) data_get($sum, 'errors_4xx_5xx', 0),
            (int) data_get($sum, 'missing_title', 0),
            (int) data_get($sum, 'missing_meta_description', 0),
            (int) data_get($sum, 'missing_h1', 0),
            (int) data_get($sum, 'noindex_pages', 0),
        ];

        if (array_sum($tSeries) > 0) {
            $tiAbs = $chartService->makePiePng(
                "{$chartDir}/tech_issues_pie.png",
                $tLabels,
                $tSeries,
                'Auditoría técnica: Distribución de issues'
            );
            $charts['tech_issues'] = $this->imgToDataUri($tiAbs);
        }
    }

    // -------------------------
    // PAGESPEED: score bar + CWV bars
    // -------------------------
    $mobileScore  = data_get($psiData, 'mobile.score');
    $desktopScore = data_get($psiData, 'desktop.score');

    if ($mobileScore !== null || $desktopScore !== null) {
        $psiAbs = $chartService->makeBarPng(
            "{$chartDir}/pagespeed_scores.png",
            ['Mobile', 'Desktop'],
            [(int)($mobileScore ?? 0), (int)($desktopScore ?? 0)],
            'PageSpeed Scores'
        );
        $charts['pagespeed_scores'] = $this->imgToDataUri($psiAbs);
    }

    $parseNum = function($v) {
        if ($v === null) return null;
        if (is_numeric($v)) return (float)$v;
        $v = trim((string)$v);
        $v = str_replace(',', '.', $v);
        $v = preg_replace('/[^0-9\.]/', '', $v);
        return $v === '' ? null : (float)$v;
    };

    $mobile  = data_get($psiData, 'mobile', []);
    $desktop = data_get($psiData, 'desktop', []);

    if (!empty($mobile)) {
        $mSeries = [
            (float)($parseNum(data_get($mobile,'lcp')) ?? 0),
            (float)($parseNum(data_get($mobile,'cls')) ?? 0),
            (float)($parseNum(data_get($mobile,'inp')) ?? 0),
        ];
        $mAbs = $chartService->makeBarSimplePng("{$chartDir}/cwv_mobile.png", ['LCP','CLS','INP'], $mSeries, 'Core Web Vitals (Mobile)');
        $charts['cwv_mobile'] = $this->imgToDataUri($mAbs);
    }

    if (!empty($desktop)) {
        $dSeries = [
            (float)($parseNum(data_get($desktop,'lcp')) ?? 0),
            (float)($parseNum(data_get($desktop,'cls')) ?? 0),
            (float)($parseNum(data_get($desktop,'inp')) ?? 0),
        ];
        $dAbs = $chartService->makeBarSimplePng("{$chartDir}/cwv_desktop.png", ['LCP','CLS','INP'], $dSeries, 'Core Web Vitals (Desktop)');
        $charts['cwv_desktop'] = $this->imgToDataUri($dAbs);
    }

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('Dominios.reporte_pdf', compact(
        'dominio','report','sections',
        'moz','tech','psi','kw',
        'mozData','techData','psiData','kwData',
        'daily','refList','kwRows','badPages',
        'charts'
    ))->setPaper('a4', 'portrait');

    $filename = 'reporte-seo-' . $dominio->id_dominio . '-r' . $report->id . '.pdf';

    return $pdf->download($filename);
}
private function fileUri(string $absPath): string
{
    // Windows/XAMPP: C:\path\file.png => file:///C:/path/file.png
    $p = str_replace('\\', '/', $absPath);

    // si ya viene con /C:/ o C:/ lo normalizamos
    if (preg_match('/^[A-Za-z]:\//', $p)) {
        return 'file:///' . $p;
    }

    // linux/mac
    return 'file://' . $p;
}
private function imgToDataUri(string $absPath): string
{
    $bin = @file_get_contents($absPath);
    if ($bin === false) return '';
    return 'data:image/png;base64,' . base64_encode($bin);
}


    public function ReportesDominio($id_dominio,$id_reporte)
    {
        $dominio = DominiosModel::findOrFail($id_dominio);

        $report = SeoReport::where('id', $id_reporte)
            ->orderByDesc('id')
            ->first();

        $sections = $report ? $report->sections()->get()->keyBy('section') : collect();

        // (opcional) si quieres seguir mostrando generadores en otra pantalla
        $generadores = Dominios_ContenidoModel::where('id_dominio', $dominio->id_dominio)->get();

        return view('Dominios.moz', compact('dominio', 'report', 'sections', 'generadores'));
    }




    public function Reportepdf($id_dominio,$id_reporte)
    {
    
        $dominio = DominiosModel::find($id_dominio);

        $report = SeoReport::where('id', $id_reporte)
            ->orderByDesc('id')
            ->firstOrFail();

        $sections = $report->sections()->get()->keyBy('section');

        $moz  = $sections->get('moz');
        $tech = $sections->get('tech');
        $psi  = $sections->get('pagespeed');
        $kw   = $sections->get('moz_keywords');

        $mozData  = ($moz  && $moz->status  === 'ok') ? ($moz->payload  ?? []) : [];
        $techData = ($tech && $tech->status === 'ok') ? ($tech->payload ?? []) : [];
        $psiData  = ($psi  && $psi->status  === 'ok') ? ($psi->payload  ?? []) : [];
        $kwData   = ($kw   && $kw->status   === 'ok') ? ($kw->payload   ?? []) : [];

        // Moz - tablas
        $daily = collect(data_get($mozData, 'daily', []))->take(-60)->values()->all();

        $refList = collect(data_get($mozData,'ref_domains_list', []))
            ->filter(fn($x) => !empty($x['root_domain']))
            ->take(100)->values()->all();

        // Keywords - tabla
        $kwRows = collect(data_get($kwData,'rows', []))->take(200)->values()->all();

        // Tech - páginas con problemas (top 50)
        $techPages = data_get($techData, 'pages', []);
        $badPages = collect($techPages)->filter(fn($p) =>
            ($p['http_code'] ?? 200) >= 400
            || empty($p['title'])
            || empty($p['meta_description'])
            || empty($p['h1'])
            || !empty($p['noindex'])
        )->take(50)->values()->all();

        // ✅ GRÁFICAS
        $charts = [
            // Moz line (nuevo look)
            'backlinks' => null,
            'refdomains' => null,

            // Moz bars
            'backlinks_delta' => null,
            'refdomains_delta' => null,

            // Pie
            'ref_new_vs_lost' => null,   // PIE (siempre visible)
            'tech_issues' => null,       // PIE

            // PSI
            'pagespeed_scores' => null,  // bar
            'cwv_mobile' => null,        // bar
            'cwv_desktop' => null,       // bar
        ];

        /** @var \App\Services\ChartImageService $chartService */
        $chartService = app(\App\Services\ChartImageService::class);

        $chartDir = "reports/seo/{$dominio->id_dominio}/{$report->id}";
        \Illuminate\Support\Facades\Storage::disk('public')->makeDirectory($chartDir);

        // -------------------------
        // MOZ: line (estilo captura) + deltas + PIE new/lost (siempre)
        // -------------------------
        if (count($daily) >= 2) {
            // labels (si quieres estilo "ENE 2026", usa este bloque)
            $labels = collect($daily)->pluck('date')->map(function ($d) {
                try {
                    $dt = \Carbon\Carbon::parse($d);
                    return mb_strtoupper($dt->locale('es')->translatedFormat('M Y')); // "ENE 2026"
                } catch (\Throwable $e) {
                    return (string)$d;
                }
            })->all();

            // line totals
            $backlinksSeries  = collect($daily)->pluck('backlinks_total')->map(fn($v) => (int)($v ?? 0))->all();
            $refDomainsSeries = collect($daily)->pluck('ref_domains_total')->map(fn($v) => (int)($v ?? 0))->all();

            // ✅ NUEVO LOOK (line + area gradient)
            $backlinksAbs = $chartService->makeLineAreaPng(
                "{$chartDir}/backlinks.png",
                $labels,
                $backlinksSeries,
                'BACKLINKS (TENDENCIA)',
                'Backlinks'
            );

            $refdomainsAbs = $chartService->makeLineAreaPng(
                "{$chartDir}/refdomains.png",
                $labels,
                $refDomainsSeries,
                'DOMINIOS DE REFERENCIA (TENDENCIA)',
                'Referring Domains'
            );

            $charts['backlinks'] = $this->imgToDataUri($backlinksAbs);
            $charts['refdomains'] = $this->imgToDataUri($refdomainsAbs);

            // bars: deltas
            $backlinksDelta  = collect($daily)->pluck('backlinks_delta')->map(fn($v) => is_null($v) ? 0 : (int)$v)->all();
            $refDomainsDelta = collect($daily)->pluck('ref_domains_delta')->map(fn($v) => is_null($v) ? 0 : (int)$v)->all();

            $bdAbs = $chartService->makeBarSimplePng("{$chartDir}/backlinks_delta.png", $labels, $backlinksDelta, 'Backlinks (Δ por día)');
            $rdAbs = $chartService->makeBarSimplePng("{$chartDir}/refdomains_delta.png", $labels, $refDomainsDelta, 'Ref Domains (Δ por día)');

            $charts['backlinks_delta'] = $this->imgToDataUri($bdAbs);
            $charts['refdomains_delta'] = $this->imgToDataUri($rdAbs);

            // PIE: nuevos vs perdidos (sumado del periodo)
            $newSeries  = collect($daily)->pluck('new_ref_domains')->map(fn($v) => (int)($v ?? 0))->all();
            $lostSeries = collect($daily)->pluck('lost_ref_domains')->map(fn($v) => (int)($v ?? 0))->all();

            $totalNew  = array_sum($newSeries);
            $totalLost = array_sum($lostSeries);

            // ✅ Si 0/0, generamos pie “Sin cambios” (porque [0,0] se renderiza vacío)
            if (($totalNew + $totalLost) === 0) {
                $nlAbs = $chartService->makePiePng(
                    "{$chartDir}/ref_new_lost_pie.png",
                    ['Sin cambios'],
                    [1],
                    'Ref Domains: Nuevos vs Perdidos (Periodo)'
                );
                $charts['ref_new_vs_lost'] = $this->imgToDataUri($nlAbs);
            } else {
                $nlAbs = $chartService->makePiePng(
                    "{$chartDir}/ref_new_lost_pie.png",
                    ['Nuevos', 'Perdidos'],
                    [(int)$totalNew, (int)$totalLost],
                    'Ref Domains: Nuevos vs Perdidos (Periodo)'
                );
                $charts['ref_new_vs_lost'] = $this->imgToDataUri($nlAbs);
            }
        }

        // -------------------------
        // TECH: PIE issues summary
        // -------------------------
        if (!empty($techData) && ($tech?->status === 'ok')) {
            $sum = data_get($techData, 'summary', []);

            $tLabels = ['4xx/5xx', 'Title falt.', 'Meta desc falt.', 'H1 falt.', 'Noindex'];
            $tSeries = [
                (int) data_get($sum, 'errors_4xx_5xx', 0),
                (int) data_get($sum, 'missing_title', 0),
                (int) data_get($sum, 'missing_meta_description', 0),
                (int) data_get($sum, 'missing_h1', 0),
                (int) data_get($sum, 'noindex_pages', 0),
            ];

            if (array_sum($tSeries) > 0) {
                $tiAbs = $chartService->makePiePng(
                    "{$chartDir}/tech_issues_pie.png",
                    $tLabels,
                    $tSeries,
                    'Auditoría técnica: Distribución de issues'
                );
                $charts['tech_issues'] = $this->imgToDataUri($tiAbs);
            }
        }

        // -------------------------
        // PAGESPEED: score bar + CWV bars
        // -------------------------
        $mobileScore  = data_get($psiData, 'mobile.score');
        $desktopScore = data_get($psiData, 'desktop.score');

        if ($mobileScore !== null || $desktopScore !== null) {
            $psiAbs = $chartService->makeBarPng(
                "{$chartDir}/pagespeed_scores.png",
                ['Mobile', 'Desktop'],
                [(int)($mobileScore ?? 0), (int)($desktopScore ?? 0)],
                'PageSpeed Scores'
            );
            $charts['pagespeed_scores'] = $this->imgToDataUri($psiAbs);
        }

        $parseNum = function($v) {
            if ($v === null) return null;
            if (is_numeric($v)) return (float)$v;
            $v = trim((string)$v);
            $v = str_replace(',', '.', $v);
            $v = preg_replace('/[^0-9\.]/', '', $v);
            return $v === '' ? null : (float)$v;
        };

        $mobile  = data_get($psiData, 'mobile', []);
        $desktop = data_get($psiData, 'desktop', []);

        if (!empty($mobile)) {
            $mSeries = [
                (float)($parseNum(data_get($mobile,'lcp')) ?? 0),
                (float)($parseNum(data_get($mobile,'cls')) ?? 0),
                (float)($parseNum(data_get($mobile,'inp')) ?? 0),
            ];
            $mAbs = $chartService->makeBarSimplePng("{$chartDir}/cwv_mobile.png", ['LCP','CLS','INP'], $mSeries, 'Core Web Vitals (Mobile)');
            $charts['cwv_mobile'] = $this->imgToDataUri($mAbs);
        }

        if (!empty($desktop)) {
            $dSeries = [
                (float)($parseNum(data_get($desktop,'lcp')) ?? 0),
                (float)($parseNum(data_get($desktop,'cls')) ?? 0),
                (float)($parseNum(data_get($desktop,'inp')) ?? 0),
            ];
            $dAbs = $chartService->makeBarSimplePng("{$chartDir}/cwv_desktop.png", ['LCP','CLS','INP'], $dSeries, 'Core Web Vitals (Desktop)');
            $charts['cwv_desktop'] = $this->imgToDataUri($dAbs);
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('Dominios.reporte_pdf', compact(
            'dominio','report','sections',
            'moz','tech','psi','kw',
            'mozData','techData','psiData','kwData',
            'daily','refList','kwRows','badPages',
            'charts'
        ))->setPaper('a4', 'portrait');

        $filename = 'ReporteSeo' . $dominio->nombre . '-' . $report->id . '.pdf';

       return $pdf->stream($filename); // 👈 lo muestra en pantalla
    }
}
