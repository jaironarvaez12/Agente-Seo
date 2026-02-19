@extends('layouts.master')

@section('titulo', 'Reporte SEO')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')

@php
  // =========================
  // Secciones
  // =========================
  $moz  = $sections->get('moz');
  $tech = $sections->get('tech');
  $psi  = $sections->get('pagespeed');
  $kw   = $sections->get('moz_keywords');
  $rk   = $sections->get('moz_ranking_keywords');
  $sr   = $sections->get('serper_ranking_keywords'); // ✅ Serper

  // =========================
  // Payloads (SIEMPRE arrays)
  // =========================
  $mozData  = ($moz  && $moz->status === 'ok') ? ((array)($moz->payload ?? []))  : [];
  $techData = ($tech && $tech->status === 'ok') ? ((array)($tech->payload ?? [])) : [];
  $psiData  = ($psi  && $psi->status === 'ok') ? ((array)($psi->payload ?? []))  : [];
  $kwData   = ($kw   && $kw->status === 'ok') ? ((array)($kw->payload ?? []))   : [];
  $rkData   = ($rk   && $rk->status === 'ok') ? ((array)($rk->payload ?? []))   : [];
  $srData   = ($sr   && $sr->status === 'ok') ? ((array)($sr->payload ?? []))   : [];

  // =========================
  // Estado reporte (blindado)
  // =========================
  $reportStatus = isset($report) && $report ? ($report->status ?? null) : null;

  // =========================
  // MOZ: series / refs (blindado)
  // =========================
  $daily   = collect(data_get($mozData, 'daily', []))->take(-60);
  $monthly = collect(data_get($mozData, 'monthly', []))->take(-24);

  $refList = collect(data_get($mozData, 'ref_domains_list', []))
      ->filter(fn($x) => !empty($x['root_domain']));
  $refTopN = $refList->count(); // ✅ para el título

  // =========================
  // TECH: summary/pages/badPages (ANTES te faltaba esto)
  // =========================
  $techSummary = (array) data_get($techData, 'summary', []);
  $techPages   = (array) data_get($techData, 'pages', []);

  $badPages = collect($techPages)->filter(fn($p) =>
      (int)($p['http_code'] ?? 200) >= 400
      || empty($p['title'] ?? null)
      || empty($p['meta_description'] ?? null)
      || empty($p['h1'] ?? null)
      || !empty($p['noindex'] ?? null)
  )->take(50)->values();

  // =========================
  // PSI: mobile/desktop (ANTES te faltaba esto)
  // =========================
  $mobile  = (array) data_get($psiData, 'mobile', []);
  $desktop = (array) data_get($psiData, 'desktop', []);

  // =========================
  // KW rows
  // =========================
  $kwRows = collect(data_get($kwData, 'rows', []))->take(200);

  // =========================
  // Ranking (Moz) + Serper fallback UI
  // =========================
  $rkTop        = collect(data_get($rkData, 'ranking', []));
  $rkModo       = (string) data_get($rkData, 'modo', 'estimado');
  $rkLocaleUsado = (string) data_get($rkData, 'locale', 'en-US');

  $srTop = collect(data_get($srData, 'ranking', []));
  $srHas = ($sr && $sr->status === 'ok' && $srTop->count() > 0);

  // UI decide qué mostrar
  $modoUI   = $rkModo;
  $dataUI   = $rkData;
  $topUI    = $rkTop;
  $localeUI = (string) data_get($rkData, 'locale', $rkLocaleUsado);

  // ✅ Si Moz viene estimado pero Serper existe OK -> mostramos Serper en UI
  if ($rkModo === 'estimado' && $srHas) {
      $modoUI   = 'serper';
      $dataUI   = $srData;
      $topUI    = $srTop;
      $localeUI = (string) data_get($srData, 'locale', $rkLocaleUsado);
  }

  // =========================
  // Locale selector (UI)
  // =========================
  $uiLocale = request('locale')
      ?? data_get($kwData, 'locale', null)
      ?? $rkLocaleUsado
      ?? 'es-ES';

  $locales = [
    'es-ES' => 'Español (España) - es-ES',
    'es-MX' => 'Español (México) - es-MX',
    'es-AR' => 'Español (Argentina) - es-AR',
    'en-US' => 'English (US) - en-US',
    'en-GB' => 'English (UK) - en-GB',
    'en-CA' => 'English (CA) - en-CA',
    'en-AU' => 'English (AU) - en-AU',
  ];
@endphp


<div class="dashboard-main-body">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <div>
      <h6 class="fw-semibold mb-0">Reporte SEO  — {{ $dominio->nombre }}</h6>
      <div class="text-secondary-light text-sm">
        Dominio: {{ $dominio->url }}
        @if($report)
          — Periodo: {{ $report->period_start }} a {{ $report->period_end }}
        @endif
      </div>
    </div>

    <div class="d-flex align-items-center gap-2">
      <form action="{{ route('dominios.reporte_seo.generar', $dominio->id_dominio) }}"
            method="POST"
            class="d-flex gap-2 align-items-center"
            style="display:inline-flex;">
        @csrf

        <select id="presetRange" name="preset" class="form-select form-select-sm" style="width: 190px;">
          <option value="last_30">Últimos 30 días</option>
          <option value="prev_month">Mes anterior</option>
          <option value="last_3m">Hace 3 meses</option>
          <option value="last_6m">Hace 6 meses</option>
          <option value="custom">Personalizado</option>
        </select>

        {{-- Locale que se enviará al backend (tú decides en controller cómo usarlo) --}}
        <select name="locale" class="form-select form-select-sm" style="width: 240px;">
          @foreach($locales as $code => $label)
            <option value="{{ $code }}" @selected($uiLocale === $code)>{{ $label }}</option>
          @endforeach
        </select>

        <input id="periodStart" type="date" name="period_start" class="form-control form-control-sm" style="width: 160px;">
        <input id="periodEnd" type="date" name="period_end" class="form-control form-control-sm" style="width: 160px;">

        <button class="btn btn-danger btn-sm">Actualizar reporte</button>
      </form>

      <a href="{{ route('dominios.show', $dominio->id_dominio) }}" class="btn btn-secondary btn-sm">Volver</a>
      <a href="{{ route('dominiosreportes', $dominio->id_dominio) }}" class="btn btn-secondary btn-sm">Ver Reportes Anteriores</a>
      <a href="{{ route('dominios.reporte_seo.pdf', $dominio->id_dominio) }}" class="btn btn-primary btn-sm">Descargar PDF</a>
    </div>
  </div>

  @if(!$report)
    <div class="alert alert-warning">
      No hay reportes aún. Da click en <b>Actualizar reporte</b> para generarlo.
    </div>
  @else
    <div class="card radius-12 mb-16">
      <div class="card-body p-16 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
          <div class="text-secondary-light text-sm">Estado del reporte</div>
          <div class="fw-semibold">
            @if($reportStatus === 'ok')
              <span class="badge bg-success">OK</span>
            @elseif($reportStatus === 'error')
              <span class="badge bg-danger">Error</span>
            @else
              <span class="badge bg-warning text-dark">Generando</span>
            @endif
            <span class="ms-2 text-secondary-light text-sm">ID: #{{ $report->id }} — {{ $report->created_at }}</span>
          </div>

          {{-- Locale seleccionado (UI) + locale real usado por ranking --}}
          <div class="text-secondary-light text-sm mt-1">
            Locale seleccionado: <b>{{ $uiLocale }}</b>
            @if($rk && $rk->status === 'ok')
              — Locale usado en Ranking: <b>{{ $rkLocaleUsado }}</b>
            @endif
          </div>
        </div>

        @if($reportStatus !== 'ok')
          <div class="text-secondary-light text-sm">
            Si está en “Generando”, recarga en unos segundos (necesitas queue worker corriendo).
            @if($reportStatus === 'error' && $report->error_message)
              <div class="text-danger mt-1">Detalle: {{ $report->error_message }}</div>
            @endif
          </div>
        @endif
      </div>
    </div>

    {{-- =========================
        MOZ LINKS
      ========================= --}}
    <div class="card radius-12 mb-16">
      <div class="card-body p-24">
        <div class="d-flex justify-content-between align-items-center mb-16">
          <h6 class="mb-0">Backlinks & Autoridad (Moz)</h6>
          @if($moz)
            @if($moz->status === 'ok') <span class="badge bg-success">OK</span>
            @else <span class="badge bg-danger">Error</span>
            @endif
          @endif
        </div>

        @if(!$moz)
          <div class="alert alert-warning mb-0">Aún no hay datos de Moz.</div>
        @elseif($moz->status !== 'ok')
          <div class="alert alert-danger mb-0">Error Moz: {{ $moz->error_message }}</div>
        @else
          <div class="row g-16 mb-16">
            <div class="col-12 col-md-3">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="text-secondary-light text-sm">Domain Authority (DA)</div>
                <div class="fw-bold" style="font-size:34px;">{{ data_get($mozData,'domain_authority','-') }}</div>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="text-secondary-light text-sm">Page Authority (PA)</div>
                <div class="fw-bold" style="font-size:34px;">{{ data_get($mozData,'page_authority','-') }}</div>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="text-secondary-light text-sm">Backlinks (Total)</div>
                <div class="fw-bold" style="font-size:34px;">{{ data_get($mozData,'backlinks_total','-') }}</div>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="text-secondary-light text-sm">Referring Domains (Total)</div>
                <div class="fw-bold" style="font-size:34px;">{{ data_get($mozData,'ref_domains_total','-') }}</div>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table bordered-table sm-table mb-0">
              <tbody>
                <tr>
                  <th style="width:40%;">Target consultado</th>
                  <td>{{ data_get($mozData,'target','-') }}</td>
                </tr>
                <tr>
                  <th>Spam Score</th>
                  @php $ss = data_get($mozData,'spam_score'); @endphp
                  <td>{{ ($ss === null || $ss < 0) ? 'N/A' : $ss }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          {{-- ✅ GRÁFICAS MOZ (Chart.js) --}}
          @php $dailyArr = $daily->values()->all(); @endphp
          @if(count($dailyArr) >= 2)
            <div class="row g-16 mt-16">
              <div class="col-12 col-lg-6">
                <div class="bg-base radius-12 p-16 h-100">
                  <div class="fw-semibold mb-2">Backlinks (Tendencia)</div>
                  <div style="height:260px;">
                    <canvas id="chartBacklinks"></canvas>
                  </div>
                </div>
              </div>

              <div class="col-12 col-lg-6">
                <div class="bg-base radius-12 p-16 h-100">
                  <div class="fw-semibold mb-2">Referring Domains (Tendencia)</div>
                  <div style="height:260px;">
                    <canvas id="chartRefDomains"></canvas>
                  </div>
                </div>
              </div>

              <div class="col-12 col-lg-6">
                <div class="bg-base radius-12 p-16 h-100">
                  <div class="fw-semibold mb-2">Backlinks (Δ por día)</div>
                  <div style="height:260px;">
                    <canvas id="chartBacklinksDelta"></canvas>
                  </div>
                </div>
              </div>

              <div class="col-12 col-lg-6">
                <div class="bg-base radius-12 p-16 h-100">
                  <div class="fw-semibold mb-2">Ref Domains: Nuevos vs Perdidos (Periodo)</div>
                  <div style="height:260px;">
                    <canvas id="chartNewLostPie"></canvas>
                  </div>
                </div>
              </div>
            </div>
          @else
            <div class="alert alert-info mt-16 mb-0">
              No hay suficientes puntos en el historial diario para graficar (mínimo 2).
            </div>
          @endif

          <h6 class="mt-16 mb-12">Backlinks & Referring Domains por día</h6>
          @if($daily->count())
            <div class="table-responsive">
              <table class="table bordered-table sm-table mb-0">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th class="text-center">Backlinks (total)</th>
                    <th class="text-center">Backlinks (Δ)</th>
                    <th class="text-center">Ref Domains (total)</th>
                    <th class="text-center">Ref Domains (Δ)</th>
                    <th class="text-center">Nuevos</th>
                    <th class="text-center">Perdidos</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($daily as $r)
                    @php
                      $bd = is_null($r['backlinks_delta'] ?? null) ? null : (int)$r['backlinks_delta'];
                      $rd = is_null($r['ref_domains_delta'] ?? null) ? null : (int)$r['ref_domains_delta'];
                      $new = (int)($r['new_ref_domains'] ?? 0);
                      $lost = (int)($r['lost_ref_domains'] ?? 0);
                    @endphp
                    <tr>
                      <td>{{ $r['date'] ?? '-' }}</td>
                      <td class="text-center">{{ $r['backlinks_total'] ?? '-' }}</td>
                      <td class="text-center">
                        @if($bd === null) - @else <span class="{{ $bd < 0 ? 'text-danger' : 'text-success' }}">{{ $bd }}</span> @endif
                      </td>
                      <td class="text-center">{{ $r['ref_domains_total'] ?? '-' }}</td>
                      <td class="text-center">
                        @if($rd === null) - @else <span class="{{ $rd < 0 ? 'text-danger' : 'text-success' }}">{{ $rd }}</span> @endif
                      </td>
                      <td class="text-center">{{ $new }}</td>
                      <td class="text-center">{{ $lost }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @else
            <div class="alert alert-info mb-0">
              Aún no hay historial diario en BD. Se llena con snapshots día a día.
            </div>
          @endif

          <h6 class="mt-16 mb-12">Dominios de referencia (Top {{ $refTopN ?: 10 }})</h6>
          @if($refList->count())
            <div class="table-responsive">
              <table class="table bordered-table sm-table mb-0">
                <thead>
                  <tr>
                    <th>Dominio</th>
                    <th class="text-center">DA</th>
                    <th class="text-center">Spam</th>
                    <th class="text-center">Links</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($refList as $r)
                    @php $ss2 = $r['spam_score'] ?? null; @endphp
                    <tr>
                      <td style="max-width: 360px; word-break: break-all;">{{ $r['root_domain'] }}</td>
                      <td class="text-center">{{ $r['domain_authority'] ?? '-' }}</td>
                      <td class="text-center">{{ ($ss2 === null || $ss2 < 0) ? 'N/A' : $ss2 }}</td>
                      <td class="text-center">{{ $r['links'] ?? '-' }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @else
            <div class="alert alert-info mb-0">
              Moz no devolvió lista. Fuente: <b>{{ data_get($mozData,'ref_domains_source','none') }}</b>.
              Si está en <b>none</b>, Moz no tiene datos de listas para este target o tu plan no lo permite.
            </div>
          @endif
        @endif
      </div>
    </div>

    {{-- =========================
        MOZ KEYWORDS
      ========================= --}}
    <div class="card radius-12 mb-16">
      <div class="card-body p-24">
        <div class="d-flex justify-content-between align-items-center mb-16">
          <h6 class="mb-0">Palabras clave objetivo (Moz)</h6>
          @if($kw)
            @if($kw->status === 'ok') <span class="badge bg-success">OK</span>
            @else <span class="badge bg-danger">Error</span>
            @endif
          @endif
        </div>

        @if(!$kw)
          <div class="alert alert-warning mb-0">Aún no hay datos de Keywords (Moz).</div>
        @elseif($kw->status !== 'ok')
          <div class="alert alert-danger mb-0">Error Moz Keywords: {{ $kw->error_message }}</div>
        @else
          @if($kwRows->count())
            <div class="table-responsive">
              <table class="table bordered-table sm-table mb-0">
                <thead>
                  <tr>
                    <th>Keyword</th>
                    <th class="text-center">Volumen</th>
                    <th class="text-center">Dificultad</th>
                    <th class="text-center">CTR orgánico</th>
                    <th class="text-center">Priority</th>
                    <th>Keyword consultada</th>
                    <th class="text-center">Locale</th>
                    <th>Nota</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($kwRows as $r)
                    <tr>
                      <td style="max-width: 360px; word-break: break-word;">{{ $r['keyword'] ?? '-' }}</td>
                      <td class="text-center">{{ $r['volume'] ?? 'N/A' }}</td>
                      <td class="text-center">{{ $r['difficulty'] ?? 'N/A' }}</td>
                      <td class="text-center">{{ $r['organic_ctr'] ?? 'N/A' }}</td>
                      <td class="text-center">{{ $r['priority'] ?? 'N/A' }}</td>
                      <td style="max-width: 260px; word-break: break-word;">
                        {{ $r['keyword_consultada'] ?? '-' }}
                      </td>
                      <td class="text-center">{{ $r['locale'] ?? '-' }}</td>
                      <td>{{ $r['note'] ?? '' }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @else
            <div class="alert alert-info mb-0">Moz no devolvió métricas para estas keywords.</div>
          @endif
        @endif
      </div>
    </div>

    {{-- =========================
        RANKING KEYWORDS (MOZ)
      ========================= --}}
   <div class="card radius-12 mb-16">
    <div class="card-body p-24">
      <div class="d-flex justify-content-between align-items-start mb-16">
        <div>
          <h6 class="mb-0">Ranking de palabras clave (Top 50)</h6>

          <div class="text-secondary-light text-sm mt-1">
            @php
              $notaUI = data_get($dataUI, 'nota');
            @endphp

            Locale usado: <b>{{ $localeUI }}</b>
            — Modo:
            @if($modoUI === 'real')
              <b>Moz (real)</b>
            @elseif($modoUI === 'serper')
              <b>Serper (SERP real)</b>
            @else
              <b>Estimado</b>
            @endif

            @if($notaUI)
              <div class="text-secondary-light mt-1">{{ $notaUI }}</div>
            @endif

            @if($modoUI === 'serper' && $rkModo === 'estimado')
              <div class="text-secondary-light mt-1">
                (Mostrando Serper como fallback visual porque Moz vino en modo estimado.)
              </div>
            @endif
          </div>
        </div>

        {{-- Badge status: prioriza Moz si existe, si no Serper --}}
        @php
          $statusBadge = null;
          $statusText = null;

          if ($modoUI === 'serper' && $sr) {
            $statusBadge = $sr->status;
          } elseif ($rk) {
            $statusBadge = $rk->status;
          }
        @endphp

        @if($statusBadge)
          @if($statusBadge === 'ok')
            <span class="badge bg-success">OK</span>
          @else
            <span class="badge bg-danger">Error</span>
          @endif
        @endif
      </div>

      @php
        $top50 = collect($topUI)->take(50);
      @endphp

      @if(!$rk && !$sr)
        <div class="alert alert-warning mb-0">Aún no hay datos de ranking.</div>

      @elseif($modoUI === 'serper' && $sr && $sr->status !== 'ok')
        <div class="alert alert-danger mb-0">Error Serper: {{ $sr->error_message }}</div>

      @elseif($modoUI !== 'serper' && $rk && $rk->status !== 'ok')
        <div class="alert alert-danger mb-0">Error Ranking Keywords: {{ $rk->error_message }}</div>

      @else
        @if($top50->count())
          <div class="table-responsive">
            <table class="table bordered-table sm-table mb-0">
              <thead>
                <tr>
                  <th class="text-center">#</th>
                  <th>Keyword</th>
                  <th class="text-center">Posición</th>
                  <th class="text-center">Volumen</th>
                  <th class="text-center">Dificultad</th>
                  <th class="text-center">Score</th>
                  <th>URL que rankea</th>
                </tr>
              </thead>
              <tbody>
                @foreach($top50 as $i => $r)
                  <tr>
                    <td class="text-center">{{ $i + 1 }}</td>

                    <td style="max-width:260px; word-break: break-word;">
                      {{ $r['keyword'] ?? '-' }}
                    </td>

                    <td class="text-center">
                      @if($modoUI === 'real' || $modoUI === 'serper')
                        {{-- ✅ POSICIÓN REAL --}}
                        {{ $r['rank_position'] ?? '—' }}
                      @else
                        {{-- ✅ ESTIMADO --}}
                        <span class="badge bg-secondary">{{ $r['posicion_estimada'] ?? 'N/D' }}</span>
                        <div class="text-secondary-light text-xs">Estimado</div>
                      @endif
                    </td>

                    <td class="text-center">{{ $r['volume'] ?? 'N/A' }}</td>
                    <td class="text-center">{{ $r['difficulty'] ?? 'N/A' }}</td>
                    <td class="text-center">{{ $r['score'] ?? '-' }}</td>

                    <td style="max-width:340px; word-break: break-all;">
                      @php
                        $url = $r['ranking_page'] ?? null;
                        // en serper tu campo ranking_page es URL (linkFound)
                        // en moz puede venir URL también; si no, cae a N/D
                      @endphp

                      @if(!empty($url))
                        <a href="{{ $url }}" target="_blank">{{ $url }}</a>
                      @else
                        <span class="text-secondary-light">N/D</span>
                      @endif
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          <div class="mt-12 text-secondary-light text-sm">
            Mostrando Top 50.
            Total: <b>{{ count($topUI) }}</b>.
            @if($modoUI === 'estimado')
              <span class="ms-2"><b>Modo estimado</b>: Moz no devolvió posiciones reales (locale/no data/cuota).</span>
            @endif
          </div>
        @else
          <div class="alert alert-info mb-0">No hay datos para mostrar.</div>
        @endif
      @endif
    </div>
  </div>

    {{-- =========================
        AUDITORÍA
      ========================= --}}
    <div class="card radius-12 mb-16">
      <div class="card-body p-24">
        <div class="d-flex justify-content-between align-items-center mb-16">
          <h6 class="mb-0">Auditoría técnica (Crawler)</h6>
          @if($tech)
            @if($tech->status === 'ok') <span class="badge bg-success">OK</span>
            @else <span class="badge bg-danger">Error</span>
            @endif
          @endif
        </div>

        @if(!$tech)
          <div class="alert alert-warning mb-0">Aún no hay datos de auditoría técnica.</div>
        @elseif($tech->status !== 'ok')
          <div class="alert alert-danger mb-0">Error Auditoría: {{ $tech->error_message }}</div>
        @else
          <div class="row g-16 mb-16">
            <div class="col-12 col-md-2"><div class="bg-base radius-12 p-16 h-100"><div class="text-secondary-light text-sm">URLs</div><div class="fw-bold" style="font-size:28px;">{{ data_get($techData,'audited','-') }}</div></div></div>
            <div class="col-12 col-md-2"><div class="bg-base radius-12 p-16 h-100"><div class="text-secondary-light text-sm">4xx/5xx</div><div class="fw-bold" style="font-size:28px;">{{ data_get($techSummary,'errors_4xx_5xx','-') }}</div></div></div>
            <div class="col-12 col-md-2"><div class="bg-base radius-12 p-16 h-100"><div class="text-secondary-light text-sm">Title falt.</div><div class="fw-bold" style="font-size:28px;">{{ data_get($techSummary,'missing_title','-') }}</div></div></div>
            <div class="col-12 col-md-2"><div class="bg-base radius-12 p-16 h-100"><div class="text-secondary-light text-sm">Meta desc falt.</div><div class="fw-bold" style="font-size:28px;">{{ data_get($techSummary,'missing_meta_description','-') }}</div></div></div>
            <div class="col-12 col-md-2"><div class="bg-base radius-12 p-16 h-100"><div class="text-secondary-light text-sm">H1 falt.</div><div class="fw-bold" style="font-size:28px;">{{ data_get($techSummary,'missing_h1','-') }}</div></div></div>
            <div class="col-12 col-md-2"><div class="bg-base radius-12 p-16 h-100"><div class="text-secondary-light text-sm">Noindex</div><div class="fw-bold" style="font-size:28px;">{{ data_get($techSummary,'noindex_pages','-') }}</div></div></div>
          </div>

          <div class="mb-12 text-secondary-light text-sm">
            Base URL: <b>{{ data_get($techData,'base_url','-') }}</b><br>
            Sitemap: <b>{{ data_get($techData,'sitemap','-') }}</b>
          </div>

          <div class="row g-16 mt-16">
            <div class="col-12 col-lg-6">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="fw-semibold mb-2">Distribución de Issues (Auditoría)</div>
                <div style="height:260px;">
                  <canvas id="chartTechIssuesPie"></canvas>
                </div>
              </div>
            </div>
          </div>

          <h6 class="mb-12">Páginas con problemas (Top 50)</h6>
          <div class="table-responsive">
            <table class="table bordered-table sm-table mb-0">
              <thead>
                <tr>
                  <th>URL</th>
                  <th class="text-center">HTTP</th>
                  <th>Title</th>
                  <th>Meta Desc</th>
                  <th>H1</th>
                  <th class="text-center">Noindex</th>
                </tr>
              </thead>
              <tbody>
                @forelse($badPages as $p)
                  <tr>
                    <td style="max-width:340px; word-break: break-all;"><a href="{{ $p['url'] }}" target="_blank">{{ $p['url'] }}</a></td>
                    <td class="text-center">
                      @php $code = $p['http_code'] ?? 0; @endphp
                      <span class="badge {{ $code >= 400 ? 'bg-danger' : 'bg-success' }}">{{ $code }}</span>
                    </td>
                    <td style="max-width:240px;">{!! empty($p['title']) ? '<span class="text-danger">Faltante</span>' : e(\Illuminate\Support\Str::limit($p['title'], 60)) !!}</td>
                    <td style="max-width:240px;">{!! empty($p['meta_description']) ? '<span class="text-danger">Faltante</span>' : e(\Illuminate\Support\Str::limit($p['meta_description'], 60)) !!}</td>
                    <td style="max-width:200px;">{!! empty($p['h1']) ? '<span class="text-danger">Faltante</span>' : e(\Illuminate\Support\Str::limit($p['h1'], 50)) !!}</td>
                    <td class="text-center">
                      @if(!empty($p['noindex'])) <span class="badge bg-warning text-dark">Sí</span>
                      @else <span class="badge bg-secondary">No</span>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="6" class="text-center text-secondary-light">No se detectaron problemas en el filtro.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        @endif
      </div>
    </div>

    {{-- =========================
        PAGESPEED
      ========================= --}}
    <div class="card radius-12 mb-16">
      <div class="card-body p-24">
        <div class="d-flex justify-content-between align-items-center mb-16">
          <h6 class="mb-0">Velocidad & Core Web Vitals (PageSpeed Insights)</h6>
          @if($psi)
            @if($psi->status === 'ok') <span class="badge bg-success">OK</span>
            @else <span class="badge bg-danger">Error</span>
            @endif
          @endif
        </div>

        @if(!$psi)
          <div class="alert alert-warning mb-0">Aún no hay datos de PageSpeed.</div>
        @elseif($psi->status !== 'ok')
          <div class="alert alert-danger mb-0">Error PageSpeed: {{ $psi->error_message }}</div>
        @else
          <div class="mb-12 text-secondary-light text-sm">
            URL evaluada: <b>{{ data_get($psiData,'url','-') }}</b>
          </div>

          <div class="row g-16 mb-16">
            <div class="col-12 col-md-6">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div class="text-secondary-light text-sm">Mobile score</div>
                    <div class="fw-bold" style="font-size:34px;">{{ data_get($mobile,'score','-') }}</div>
                  </div>
                  <span class="badge bg-secondary">mobile</span>
                </div>
                <div class="mt-2 text-secondary-light text-sm">
                  LCP: <b>{{ data_get($mobile,'lcp','-') }}</b> • CLS: <b>{{ data_get($mobile,'cls','-') }}</b> • INP: <b>{{ data_get($mobile,'inp','-') }}</b>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-6">
              <div class="bg-base radius-12 p-16 h-100">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div class="text-secondary-light text-sm">Desktop score</div>
                    <div class="fw-bold" style="font-size:34px;">{{ data_get($desktop,'score','-') }}</div>
                  </div>
                  <span class="badge bg-secondary">desktop</span>
                </div>
                <div class="mt-2 text-secondary-light text-sm">
                  LCP: <b>{{ data_get($desktop,'lcp','-') }}</b> • CLS: <b>{{ data_get($desktop,'cls','-') }}</b> • INP: <b>{{ data_get($desktop,'inp','-') }}</b>
                </div>
              </div>
            </div>
          </div>
        @endif

        <div class="row g-16 mt-16">
          <div class="col-12 col-lg-6">
            <div class="bg-base radius-12 p-16 h-100">
              <div class="fw-semibold mb-2">Score PageSpeed (Mobile vs Desktop)</div>
              <div style="height:260px;">
                <canvas id="chartPsiScoreBar"></canvas>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="bg-base radius-12 p-16 h-100">
              <div class="fw-semibold mb-2">Core Web Vitals (Mobile)</div>
              <div style="height:260px;">
                <canvas id="chartCwvMobile"></canvas>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-6">
            <div class="bg-base radius-12 p-16 h-100">
              <div class="fw-semibold mb-2">Core Web Vitals (Desktop)</div>
              <div style="height:260px;">
                <canvas id="chartCwvDesktop"></canvas>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

  @endif
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(function () {
  const BLUE = '#2f80ed';
  const FILL = 'rgba(47,128,237,0.18)';

  Chart.defaults.font.family = 'system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif';
  Chart.defaults.color = '#6b7280';
  Chart.defaults.plugins.legend.labels.boxWidth = 18;

  function toInt(v) {
    const n = parseInt(v ?? 0, 10);
    return Number.isFinite(n) ? n : 0;
  }

  function parseMetricToNumber(val) {
    if (val === null || val === undefined) return null;
    const s = String(val).trim().toLowerCase();
    if (!s) return null;

    if (s.endsWith('ms')) return parseFloat(s.replace('ms','').trim());
    if (s.endsWith('s')) return parseFloat(s.replace('s','').trim()) * 1000;

    const n = parseFloat(s);
    return isNaN(n) ? null : n;
  }

  function lineAreaConfig(labels, label, data) {
    return {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label,
          data,
          fill: true,
          tension: 0.35,
          borderWidth: 2,
          borderColor: BLUE,
          backgroundColor: FILL,
          pointRadius: 4,
          pointHoverRadius: 5,
          pointBackgroundColor: '#ffffff',
          pointBorderColor: BLUE,
          pointBorderWidth: 2,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: true, position: 'top', align: 'end' },
          tooltip: { mode: 'index', intersect: false }
        },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
          y: { beginAtZero: false, grid: { color: 'rgba(0,0,0,0.08)' }, ticks: { precision: 0 } }
        }
      }
    };
  }

  function barConfig(labels, label, data, opts = {}) {
    return {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label,
          data,
          backgroundColor: FILL,
          borderColor: BLUE,
          borderWidth: 1,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true, position: 'top', align: 'end' } },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } },
          y: { beginAtZero: !!opts.beginAtZero, max: opts.max ?? undefined, grid: { color: 'rgba(0,0,0,0.08)' }, ticks: { precision: 0 } }
        }
      }
    };
  }

  // MOZ charts
  const daily = @json($daily->values()->all());
  if (Array.isArray(daily) && daily.length >= 2) {
    const labelsMoz = daily.map(r => r.date ?? '');
    const backlinksTotal = daily.map(r => toInt(r.backlinks_total));
    const refDomainsTotal = daily.map(r => toInt(r.ref_domains_total));
    const backlinksDelta = daily.map(r => toInt(r.backlinks_delta));

    const newTotal = daily.reduce((acc, r) => acc + toInt(r.new_ref_domains), 0);
    const lostTotal = daily.reduce((acc, r) => acc + toInt(r.lost_ref_domains), 0);

    const el1 = document.getElementById('chartBacklinks');
    if (el1) new Chart(el1, lineAreaConfig(labelsMoz, 'Backlinks', backlinksTotal));

    const el2 = document.getElementById('chartRefDomains');
    if (el2) new Chart(el2, lineAreaConfig(labelsMoz, 'Referring Domains', refDomainsTotal));

    const el3 = document.getElementById('chartBacklinksDelta');
    if (el3) new Chart(el3, barConfig(labelsMoz, 'Backlinks Δ', backlinksDelta));

    const pieEl = document.getElementById('chartNewLostPie');
    if (pieEl) {
      let pieLabels, pieData;
      if ((newTotal + lostTotal) === 0) {
        pieLabels = ['Sin cambios'];
        pieData = [1];
      } else {
        pieLabels = ['Nuevos', 'Perdidos'];
        pieData = [newTotal, lostTotal];
      }

      new Chart(pieEl, {
        type: 'pie',
        data: {
          labels: pieLabels,
          datasets: [{
            data: pieData,
            backgroundColor: [
              'rgba(47,128,237,0.65)',
              'rgba(47,128,237,0.25)',
            ],
            borderColor: '#ffffff',
            borderWidth: 2,
          }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
      });
    }
  }

  // TECH AUDIT PIE
  const techSummary = @json($techSummary ?? []);
  const techIssues = [
    { label: '4xx/5xx', val: toInt(techSummary.errors_4xx_5xx) },
    { label: 'Title faltante', val: toInt(techSummary.missing_title) },
    { label: 'Meta desc faltante', val: toInt(techSummary.missing_meta_description) },
    { label: 'H1 faltante', val: toInt(techSummary.missing_h1) },
    { label: 'Noindex', val: toInt(techSummary.noindex_pages) },
  ].filter(x => x.val > 0);

  const techPieEl = document.getElementById('chartTechIssuesPie');
  if (techPieEl) {
    let labels, data;
    if (!techIssues.length) {
      labels = ['Sin issues'];
      data = [1];
    } else {
      labels = techIssues.map(x => x.label);
      data = techIssues.map(x => x.val);
    }

    new Chart(techPieEl, {
      type: 'pie',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: [
            'rgba(47,128,237,0.70)',
            'rgba(47,128,237,0.55)',
            'rgba(47,128,237,0.40)',
            'rgba(47,128,237,0.30)',
            'rgba(47,128,237,0.20)',
          ],
          borderColor: '#ffffff',
          borderWidth: 2,
        }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
  }

  // PAGESPEED + CWV charts
  const psiData = @json($psiData ?? []);
  const mobileScore = psiData?.mobile?.score ?? null;
  const desktopScore = psiData?.desktop?.score ?? null;

  const psiScoreEl = document.getElementById('chartPsiScoreBar');
  if (psiScoreEl) {
    const labels = ['Mobile', 'Desktop'];
    const series = [
      mobileScore === null ? 0 : toInt(mobileScore),
      desktopScore === null ? 0 : toInt(desktopScore),
    ];
    new Chart(psiScoreEl, barConfig(labels, 'Score', series, { beginAtZero: true, max: 100 }));
  }

  const cwvMobileEl = document.getElementById('chartCwvMobile');
  if (cwvMobileEl) {
    const lcp = parseMetricToNumber(psiData?.mobile?.lcp);
    const inp = parseMetricToNumber(psiData?.mobile?.inp);
    const cls = parseMetricToNumber(psiData?.mobile?.cls);

    const labels = ['LCP (ms)', 'INP (ms)', 'CLS'];
    const series = [
      lcp === null ? 0 : Math.round(lcp),
      inp === null ? 0 : Math.round(inp),
      cls === null ? 0 : Number(cls),
    ];
    new Chart(cwvMobileEl, barConfig(labels, 'Mobile', series, { beginAtZero: true }));
  }

  const cwvDesktopEl = document.getElementById('chartCwvDesktop');
  if (cwvDesktopEl) {
    const lcp = parseMetricToNumber(psiData?.desktop?.lcp);
    const inp = parseMetricToNumber(psiData?.desktop?.inp);
    const cls = parseMetricToNumber(psiData?.desktop?.cls);

    const labels = ['LCP (ms)', 'INP (ms)', 'CLS'];
    const series = [
      lcp === null ? 0 : Math.round(lcp),
      inp === null ? 0 : Math.round(inp),
      cls === null ? 0 : Number(cls),
    ];
    new Chart(cwvDesktopEl, barConfig(labels, 'Desktop', series, { beginAtZero: true }));
  }
})();
</script>

<script>
(function () {
  const preset = document.getElementById('presetRange');
  const start = document.getElementById('periodStart');
  const end = document.getElementById('periodEnd');

  function sync() {
    const isCustom = preset.value === 'custom';
    start.style.display = isCustom ? '' : 'none';
    end.style.display = isCustom ? '' : 'none';

    if (!isCustom) {
      start.value = '';
      end.value = '';
    }
  }

  preset.addEventListener('change', sync);
  sync();
})();
</script>
@endsection
