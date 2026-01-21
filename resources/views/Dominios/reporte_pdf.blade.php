<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Reporte SEO</title>

  <style>
     :root{
    --brand: {{ $dominio->color ?? '#487FFF' }};
  }
    /* ====== DomPDF page box ====== */
    @page {
      margin: 35px 35px 70px 35px; /* bottom reservado para footer */
    }

    body {
      font-family: DejaVu Sans, sans-serif;
      font-size: 12px;
      color: var(--brand);
      margin: 0;
      padding: 0;
      /* colchón mínimo extra, sin exagerar */
      padding-bottom: 10px;
    }

    .muted { color:#666; }
    .title { font-size: 18px; font-weight: 700; margin-bottom: 6px; }
    .subtitle { margin-bottom: 14px; }

    .badge { display:inline-block; padding:3px 8px; border-radius: 10px; font-size: 11px; }
    .ok { background:#e7f7ee; color:#1f7a3b; }
    .err { background:#fde8e8; color:#9b1c1c; }
    .gen { background:#fff3cd; color:#6b4f00; }

    .card{
  border:1px solid #ddd;
  border-radius:8px;
  padding:10px 12px;
  margin-bottom: 12px;

  /* permitir que DomPDF parta cards grandes */
  page-break-inside: auto;
}

/* Solo las cards que tú quieras “no partir” */
.card.keep{
  page-break-inside: avoid;
}

    h2 { font-size: 14px; margin: 0 0 8px 0; }
    h3 { font-size: 12px; margin: 10px 0 6px; }

    table { width: 100%; border-collapse: collapse; }
    th, td { border:1px solid #ddd; padding: 6px; vertical-align: top; }
    th { background:#f5f5f5; text-align:left; }

    .center { text-align:center; }
    .small { font-size: 10px; }

    .page-break { page-break-after: always; }

    .kpi td { border:none; padding:4px 0; }

    /* ====== Imágenes / gráficas ====== */
    .imgbox { margin-top: 10px; }

    /* IMPORTANTE: NO usar avoid en IMG global (provoca huecos) */
    .chart{
      width: 100%;
      height: 210px;         /* un poco menor para reducir saltos */
      object-fit: contain;
      display: block;
    }

    /* si alguna gráfica es más “alta”, usa esta clase */
    .chart--big{
      height: 240px;
    }

    .logo {
      width: 150px;
      height: 150px;
      object-fit: contain;
    }

    /* ====== Footer fijo ====== */
    .footer{
      position: fixed;
      left: 35px;   /* igual que @page */
      right: 35px;  /* igual que @page */
      bottom: 20px; /* dentro del margen inferior reservado */
      height: 28px;

      font-size: 10px;
      color: var(--brand);
      border-top: 1px solid #ddd;
      padding-top: 6px;
      background: #fff; /* evita “texto por debajo” */
    }
  </style>
</head>

<body>

  <!-- ====== Header ====== -->
  <table style="width:100%; margin-bottom:12px;" cellpadding="0" cellspacing="0">
    <tr>
      <td style="width:60%; vertical-align:top;">
        <div class="title">Reporte SEO — {{ $dominio->nombre }}</div>
        <div class="subtitle muted">
          Dominio: <b>{{ $dominio->url }}</b><br>
          Periodo: <b>{{ date('d-m-Y', strtotime($report->period_start))}}</b> a <b>{{ date('d-m-Y', strtotime($report->period_end ))}}</b><br>
          Report ID: <b>#{{ $report->id }}</b> — {{ date('d-m-Y h:i A', strtotime($report->created_at)) }}
        </div>
      </td>

      <td style="width:40%; vertical-align:top; text-align:center;">
        <img class="logo" src="{{ $dominio->imagen }}" alt="Logo">
      </td>
    </tr>
  </table>

  {{-- Estado --}}
  {{-- <div class="card">
    <h2>Estado del reporte</h2>
    @php $st = $report->status; @endphp

    @if($st === 'ok')
      <span class="badge ok">OK</span>
    @elseif($st === 'error')
      <span class="badge err">Error</span>
    @else
      <span class="badge gen">Generando</span>
    @endif

    @if($st === 'error' && $report->error_message)
      <div style="margin-top:8px;" class="small">
        <b style="color:#9b1c1c;">Detalle:</b> {{ $report->error_message }}
      </div>
    @endif
  </div> --}}

  {{-- MOZ LINKS --}}
  <div class="card">
    <h2>Backlinks & Autoridad (Moz)</h2>

    @if(!$moz)
      <div class="muted">Sin sección Moz.</div>
    @elseif($moz->status !== 'ok')
      <div class="small" style="color:#9b1c1c;"><b>Error Moz:</b> {{ $moz->error_message }}</div>
    @else
      <table class="kpi">
        <tr>
          <td><b>DA:</b> {{ data_get($mozData,'domain_authority','-') }}</td>
          <td><b>PA:</b> {{ data_get($mozData,'page_authority','-') }}</td>
          <td><b>Backlinks:</b> {{ data_get($mozData,'backlinks_total','-') }}</td>
          <td><b>Ref Domains:</b> {{ data_get($mozData,'ref_domains_total','-') }}</td>
        </tr>
        <tr>
          <td colspan="4">
            <b>Target:</b> {{ data_get($mozData,'target','-') }}
            — <b>Spam:</b>
            @php $ss = data_get($mozData,'spam_score'); @endphp
            {{ ($ss === null || $ss < 0) ? 'N/A' : $ss }}
          </td>
        </tr>
      </table>

      {{-- Gráficas --}}
      @if(!empty($charts['backlinks']))
        <div class="imgbox">
          <img class="chart" src="{{ $charts['backlinks'] }}" alt="Backlinks">
        </div>
      @endif

      @if(!empty($charts['refdomains']))
        <div class="imgbox">
          <img class="chart" src="{{ $charts['refdomains'] }}" alt="Referring domains">
        </div>
      @endif

      @if(!empty($charts['backlinks_delta']))
        <div class="imgbox">
          <img class="chart" src="{{ $charts['backlinks_delta'] }}" alt="Backlinks delta">
        </div>
      @endif

      @if(!empty($charts['refdomains_delta']))
        <div class="imgbox">
          <img class="chart" src="{{ $charts['refdomains_delta'] }}" alt="Ref domains delta">
        </div>
      @endif

      @if(!empty($charts['ref_new_vs_lost']))
        <div class="imgbox">
          <img class="chart" src="{{ $charts['ref_new_vs_lost'] }}" alt="New vs lost">
        </div>
      @endif

      <h3>Historial diario (últimos 60)</h3>
      @if(count($daily))
        <table>
          <thead>
            <tr>
              <th>Fecha</th>
              <th class="center">Backlinks</th>
              <th class="center">Δ</th>
              <th class="center">Ref Domains</th>
              <th class="center">Δ</th>
              <th class="center">Nuevos</th>
              <th class="center">Perdidos</th>
            </tr>
          </thead>
          <tbody>
            @foreach($daily as $r)
              <tr>
                <td>{{ $r['date'] ?? '-' }}</td>
                <td class="center">{{ $r['backlinks_total'] ?? '-' }}</td>
                <td class="center">{{ $r['backlinks_delta'] ?? '-' }}</td>
                <td class="center">{{ $r['ref_domains_total'] ?? '-' }}</td>
                <td class="center">{{ $r['ref_domains_delta'] ?? '-' }}</td>
                <td class="center">{{ $r['new_ref_domains'] ?? 0 }}</td>
                <td class="center">{{ $r['lost_ref_domains'] ?? 0 }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @else
        <div class="muted small">Sin historial diario (se llena con snapshots día a día).</div>
      @endif

      <h3>Dominios de referencia (Top 100)</h3>
      @if(count($refList))
        <table>
          <thead>
            <tr>
              <th>Dominio</th>
              <th class="center">DA</th>
              <th class="center">Spam</th>
              <th class="center">Links</th>
            </tr>
          </thead>
          <tbody>
            @foreach($refList as $r)
              @php $ss2 = $r['spam_score'] ?? null; @endphp
              <tr>
                <td class="small">{{ $r['root_domain'] }}</td>
                <td class="center">{{ $r['domain_authority'] ?? '-' }}</td>
                <td class="center">{{ ($ss2 === null || $ss2 < 0) ? 'N/A' : $ss2 }}</td>
                <td class="center">{{ $r['links'] ?? '-' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @else
        <div class="muted small">Moz no devolvió lista de dominios de referencia.</div>
      @endif
    @endif
  </div>
 <div class="page-break"></div>
  {{-- MOZ KEYWORDS --}}
  <div class="card">
    <h2>Palabras clave objetivo (Moz)</h2>

    @if(!$kw)
      <div class="muted">Sin sección moz_keywords.</div>
    @elseif($kw->status !== 'ok')
      <div class="small" style="color:#9b1c1c;"><b>Error Moz Keywords:</b> {{ $kw->error_message }}</div>
    @else
      <div class="muted small">
        Device: {{ data_get($kwData,'device','-') }} — Engine: {{ data_get($kwData,'engine','-') }}
        — Sin datos: {{ data_get($kwData,'no_data_count', 0) }}
      </div>

      @if(count($kwRows))
        <table>
          <thead>
            <tr>
              <th>Keyword</th>
              <th class="center">Locale</th>
              <th class="center">Volumen</th>
              <th class="center">Dificultad</th>
              <th class="center">CTR</th>
              <th class="center">Priority</th>
              <th>Nota</th>
            </tr>
          </thead>
          <tbody>
            @foreach($kwRows as $r)
              <tr>
                <td>{{ $r['keyword'] ?? '-' }}</td>
                <td class="center">{{ $r['locale'] ?? '-' }}</td>
                <td class="center">{{ $r['volume'] ?? 'N/A' }}</td>
                <td class="center">{{ $r['difficulty'] ?? 'N/A' }}</td>
                <td class="center">{{ $r['organic_ctr'] ?? 'N/A' }}</td>
                <td class="center">{{ $r['priority'] ?? 'N/A' }}</td>
                <td class="small">{{ $r['note'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @else
        <div class="muted small">Sin resultados de métricas para keywords.</div>
      @endif
    @endif
  </div>

  <div class="page-break"></div>

  {{-- TECH AUDIT --}}
  <div class="card">
    <h2>Auditoría técnica (Crawler)</h2>

    @if(!empty($charts['tech_issues']))
      <div class="imgbox">
        <img class="chart chart--big" src="{{ $charts['tech_issues'] }}" alt="Tech issues">
      </div>
    @endif

    @if(!$tech)
      <div class="muted">Sin sección tech.</div>
    @elseif($tech->status !== 'ok')
      <div class="small" style="color:#9b1c1c;"><b>Error Auditoría:</b> {{ $tech->error_message }}</div>
    @else
      @php $sum = data_get($techData,'summary',[]); @endphp
      <table class="kpi">
        <tr>
          <td><b>URLs auditadas:</b> {{ data_get($techData,'audited','-') }}</td>
          <td><b>4xx/5xx:</b> {{ data_get($sum,'errors_4xx_5xx','-') }}</td>
          <td><b>Title faltante:</b> {{ data_get($sum,'missing_title','-') }}</td>
          <td><b>Meta desc faltante:</b> {{ data_get($sum,'missing_meta_description','-') }}</td>
        </tr>
        <tr>
          <td><b>H1 faltante:</b> {{ data_get($sum,'missing_h1','-') }}</td>
          <td><b>Noindex:</b> {{ data_get($sum,'noindex_pages','-') }}</td>
          <td colspan="2">
            <b>Base URL:</b> {{ data_get($techData,'base_url','-') }}<br>
            <b>Sitemap:</b> {{ data_get($techData,'sitemap','-') }}
          </td>
        </tr>
      </table>

      <h3>Páginas con problemas (Top 50)</h3>
      <table>
        <thead>
          <tr>
            <th>URL</th>
            <th class="center">HTTP</th>
            <th>Title</th>
            <th>Meta Desc</th>
            <th>H1</th>
            <th class="center">Noindex</th>
          </tr>
        </thead>
        <tbody>
          @forelse($badPages as $p)
            <tr>
              <td class="small">{{ $p['url'] ?? '-' }}</td>
              <td class="center">{{ $p['http_code'] ?? '-' }}</td>
              <td class="small">{{ $p['title'] ?? 'Faltante' }}</td>
              <td class="small">{{ $p['meta_description'] ?? 'Faltante' }}</td>
              <td class="small">{{ $p['h1'] ?? 'Faltante' }}</td>
              <td class="center">{{ !empty($p['noindex']) ? 'Sí' : 'No' }}</td>
            </tr>
          @empty
            <tr><td colspan="6" class="center muted">Sin problemas detectados.</td></tr>
          @endforelse
        </tbody>
      </table>
    @endif
  </div>

  {{-- PAGESPEED --}}
  <div class="card">
    <h2>Velocidad & Core Web Vitals (PageSpeed Insights)</h2>

    @if(!$psi)
      <div class="muted">Sin sección pagespeed.</div>
    @elseif($psi->status !== 'ok')
      <div class="small" style="color:#9b1c1c;"><b>Error PageSpeed:</b> {{ $psi->error_message }}</div>
    @else
      @php
        $mobile = data_get($psiData,'mobile',[]);
        $desktop = data_get($psiData,'desktop',[]);
      @endphp

      <div class="muted small">URL evaluada: <b>{{ data_get($psiData,'url','-') }}</b></div>

      <table>
        <thead>
          <tr>
            <th>Dispositivo</th>
            <th class="center">Score</th>
            <th class="center">LCP</th>
            <th class="center">CLS</th>
            <th class="center">INP</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Mobile</td>
            <td class="center">{{ data_get($mobile,'score','-') }}</td>
            <td class="center">{{ data_get($mobile,'lcp','-') }}</td>
            <td class="center">{{ data_get($mobile,'cls','-') }}</td>
            <td class="center">{{ data_get($mobile,'inp','-') }}</td>
          </tr>
          <tr>
            <td>Desktop</td>
            <td class="center">{{ data_get($desktop,'score','-') }}</td>
            <td class="center">{{ data_get($desktop,'lcp','-') }}</td>
            <td class="center">{{ data_get($desktop,'cls','-') }}</td>
            <td class="center">{{ data_get($desktop,'inp','-') }}</td>
          </tr>
        </tbody>
      </table>

      @if(!empty($charts['pagespeed_scores']))
        <div class="imgbox"><img class="chart" src="{{ $charts['pagespeed_scores'] }}" alt="PageSpeed scores"></div>
      @endif
      @if(!empty($charts['cwv_mobile']))
        <div class="imgbox"><img class="chart" src="{{ $charts['cwv_mobile'] }}" alt="CWV mobile"></div>
      @endif
      @if(!empty($charts['cwv_desktop']))
        <div class="imgbox"><img class="chart" src="{{ $charts['cwv_desktop'] }}" alt="CWV desktop"></div>
      @endif
    @endif
  </div>

  <!-- ====== Footer ====== -->
<div class="footer">
  <table style="width:100%; border:none; border-collapse:collapse;" cellpadding="0" cellspacing="0">
    <tr style="border:none;">
      <td style="text-align:center; border:none;">
        <b> {{ $dominio->nombre }} </b>
        <br>
         {{ $dominio->direccion }} 
      </td>
    </tr>
  </table>
</div>

</body>
</html>
