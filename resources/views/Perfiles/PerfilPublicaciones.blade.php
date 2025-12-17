@extends('layouts.master')

@section('titulo', 'Publicaciones de Facebook')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

@php
    // Miniatura principal
    $getThumb = function(array $post) {
        if (!empty($post['full_picture'])) return $post['full_picture'];
        $atts = data_get($post, 'attachments.data', []);
        foreach ($atts as $att) {
            if ($src = data_get($att, 'media.image.src')) return $src;
            $subs = data_get($att, 'subattachments.data', []);
            foreach ($subs as $sub) {
                if ($src2 = data_get($sub, 'media.image.src')) return $src2;
            }
        }
        return null;
    };
    $isScheduledView = !empty($showScheduled);
@endphp

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">
            Publicaciones de Facebook — {{ $perfil->fb_page_name ?? 'Página' }}
        </h6>

        <ul class="d-flex align-items-center gap-2 mb-0">
            <li class="fw-medium">
                <a href="{{ route('perfiles.index') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">{{ $perfil->fb_page_name ?? 'Página' }}</li>
        </ul>
    </div>

    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">

            {{-- Toggle Publicadas / Programadas (no publicadas) --}}
            <div class="d-flex align-items-center gap-2 mb-16">
                <a href="{{ route('perfilesface', [$perfil->id]) }}"
                   class="btn btn-sm {{ !$isScheduledView ? 'btn-primary' : 'btn-outline-primary' }} radius-8">
                    Publicadas
                </a>
                <a href="{{ route('perfilesface', [$perfil->id, 'scheduled' => 1]) }}"
                   class="btn btn-sm {{ $isScheduledView ? 'btn-primary' : 'btn-outline-primary' }} radius-8">
                    Programadas (pendientes)
                </a>
            </div>

            @if(empty($items) || count($items) === 0)
                <div class="alert alert-warning mb-0">
                    @if($isScheduledView)
                        No hay publicaciones <strong>programadas</strong> pendientes.
                    @else
                        No hay publicaciones <strong>publicadas</strong>.
                    @endif
                </div>
            @else
                <div class="row">
                    @foreach($items as $it)
                        @php
                            $thumb = $getThumb($it);

                            // Fecha: si es programada, usar scheduled_publish_time; si no, created_time
                            $whenIso = $isScheduledView
                                ? ($it['scheduled_publish_time'] ?? null)
                                : ($it['created_time'] ?? null);

                            $fecha = $whenIso ? \Carbon\Carbon::parse($whenIso)->format('d/m/Y H:i') : '—';

                            $isCarousel = !empty(data_get($it, 'attachments.data.0.subattachments.data'));

                            // Badge
                            if ($isScheduledView) {
                                $badgeText  = $isCarousel ? 'PROGRAMADA · CARRUSEL' : 'PROGRAMADA';
                                $badgeClass = 'bg-warning-600';
                            } else {
                                $statusType = strtoupper($it['status_type'] ?? 'POST');
                                $badgeText  = $isCarousel ? 'CARRUSEL' : $statusType;
                                $badgeClass = 'bg-secondary-600';
                            }

                            $domId = 'sched-'.preg_replace('/[^a-zA-Z0-9_-]/','', $it['id'] ?? uniqid());
                        @endphp

                        <div class="col-xxl-3 col-xl-4 col-lg-4 col-md-6 mb-20">
                            <div class="card border h-100 radius-12">

                                @if($thumb)
                                    <img src="{{ $thumb }}" class="card-img-top radius-12" alt="post">
                                @endif

                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-8">
                                        <span class="badge {{ $badgeClass }}">
                                            {{ $badgeText }}
                                        </span>
                                        <small class="text-primary-light">{{ $fecha }}</small>
                                    </div>

                                    @if(!empty($it['message']))
                                        <p class="mb-8" style="white-space: pre-line;">
                                            {{ \Illuminate\Support\Str::limit($it['message'], 160) }}
                                        </p>
                                    @endif

                                    @if($isScheduledView && $whenIso)
                                        <div class="d-flex align-items-center justify-content-between">
                                            <small class="text-muted">Sale en:</small>
                                            <small id="{{ $domId }}" class="fw-semibold">—</small>
                                        </div>
                                        <script>
                                            (function(){
                                              const el = document.getElementById(@json($domId));
                                              const target = new Date(@json($whenIso));
                                              function tick(){
                                                const now = new Date();
                                                let diff = Math.floor((target - now) / 1000);
                                                if (isNaN(diff)) return;
                                                if (diff <= 0) { el.textContent = 'inminente'; return; }
                                                const d = Math.floor(diff/86400); diff%=86400;
                                                const h = Math.floor(diff/3600); diff%=3600;
                                                const m = Math.floor(diff/60);  const s = diff%60;
                                                el.textContent =
                                                  (d? d+'d ':'') + String(h).padStart(2,'0') + ':' +
                                                  String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
                                              }
                                              tick(); setInterval(tick, 1000);
                                            })();
                                        </script>
                                    @endif
                                </div>

                                <div class="card-body pt-0">
                                    @if(!$isScheduledView && !empty($it['permalink_url']))
                                        <a href="{{ $it['permalink_url'] }}" target="_blank" rel="noopener"
                                           class="btn btn-sm btn-outline-primary radius-8">
                                            Ver en Facebook
                                        </a>
                                    @elseif($isScheduledView)
                                        <span class="text-xs text-primary-light">Aún sin enlace público</span>
                                    @endif
                                </div>

                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

           {{-- Paginación (cursors) --}}
<div class="d-flex align-items-center justify-content-center gap-3">
    @if(!empty($prev))
        <a class="border bg-hover-warning-100 text-warning-600 text-md px-24 py-8 radius-8"
           href="{{ route('perfilesface', array_merge([$perfil->id], ['before' => $prev], $isScheduledView ? ['scheduled'=>1] : [])) }}">
            &laquo; Más recientes
        </a>
    @endif
    @if(!empty($next))
        <a class="btn btn-primary text-md px-24 py-8 radius-8"
           href="{{ route('perfilesface', array_merge([$perfil->id], ['after' => $next], $isScheduledView ? ['scheduled'=>1] : [])) }}">
            Anteriores &raquo;
        </a>
    @endif
</div>


        </div>
    </div>
</div>
@endsection