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

use Illuminate\Support\Facades\Storage;
use Throwable;

class SeoReportController extends Controller
{
    public function generar($id_dominio)
    {
        $dominio = DominiosModel::findOrFail($id_dominio);

        $report = SeoReport::create([
            'id_dominio'   => $dominio->id_dominio,
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end'   => now()->toDateString(),
            'status'       => 'generando',
        ]);

        // ✅ Cadena SOLO con secciones requeridas + finalize
        Bus::chain([
            (new FetchMozSectionJob($report->id))->onQueue('reports'),
            (new RunTechAuditSectionJob($report->id, 200))->onQueue('reports'),
            (new FinalizeSeoReportJob($report->id))->onQueue('reports'),
        ])
        ->catch(function (Throwable $e) use ($report) {
            // por si algo explota fuera, intenta finalizar
            FinalizeSeoReportJob::dispatch($report->id)->onQueue('reports');
        })
        ->dispatch();

        // ✅ PageSpeed fuera (NO bloquea), en otra cola
        FetchPageSpeedSectionJob::dispatch($report->id)
            ->onQueue('pagespeed')
            ->delay(now()->addSeconds(20));

        // ✅ Keywords Moz fuera (NO bloquea) — usa tus keywords desde dominios_contenido
        $keywords = $this->keywordsFromDomain($dominio->id_dominio);

        FetchMozKeywordMetricsJob::dispatch($report->id, $keywords, 'desktop', 'google')
            ->onQueue('reports')
            ->delay(now()->addSeconds(5));

        return redirect()
            ->route('dominios.reporte_seo.ver', $dominio->id_dominio)
            ->with('success', 'Reporte SEO en generación. Recarga en unos momentos.');
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
    private function keywordsFromDomain(int $idDominio): array
    {
        $generadores = Dominios_ContenidoModel::where('id_dominio', $idDominio)->get();

        $keywords = [];

        // 1) Extraer keywords
        foreach ($generadores as $g) {
            $text = (string) ($g->palabras_claves ?? '');
            if ($text === '') continue;

            // separa por coma, punto y coma, saltos de linea
            $parts = preg_split('/[,;\n\r]+/u', $text);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') $keywords[] = $p;
            }
        }

        // 2) Expandir variantes para mejorar chance de datos en Moz
        $expanded = [];
        foreach ($keywords as $k) {
            $kTrim = trim($k);
            if ($kTrim === '') continue;

            $expanded[] = $kTrim;

            $lower = mb_strtolower($kTrim);

            // 🔥 tus casos reales
            if ($lower === 'paginas web' || $lower === 'páginas web') {
                $expanded[] = 'páginas web';
                $expanded[] = 'paginas web';
                $expanded[] = 'diseño web';
                $expanded[] = 'desarrollo web';
                $expanded[] = 'diseño de páginas web';
                $expanded[] = 'creación de páginas web';
            }

            if ($lower === 'seo' || $lower === 'posicionamiento seo') {
                $expanded[] = 'seo';
                $expanded[] = 'posicionamiento seo';
                $expanded[] = 'agencia seo';
                $expanded[] = 'seo agency';
            }
        }

        // 3) Únicas + límite por cuota
        $expanded = array_values(array_unique($expanded));

        // ⚠️ limita para no gastar cuota. (sube/baja esto a gusto)
        return array_slice($expanded, 0, 15);
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
