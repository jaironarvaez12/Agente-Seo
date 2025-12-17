@extends('layouts.master')

@section('titulo', 'Publicaciones de Facebook')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

@php
    // Helper para obtener una miniatura "principal" similar a IG
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
@endphp

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Publicaciones de Facebook</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
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

            @if(empty($items) || count($items) === 0)
                <div class="alert alert-warning mb-0">
                    No hay publicaciones para mostrar.
                </div>
            @else
                <div class="row">
                    @foreach($items as $it)
                        @php
                            $thumb = $getThumb($it);
                            $fecha = \Carbon\Carbon::parse($it['created_time'])->format('d/m/Y g:i:s A');
                            $isCarousel = !empty(data_get($it, 'attachments.data.0.subattachments.data'));
                            $statusType = strtoupper($it['status_type'] ?? 'POST');
                        @endphp

                        <div class="col-xxl-3 col-xl-4 col-lg-4 col-md-6 mb-20">
                            <div class="card border h-100 radius-12">

                                @if($thumb)
                                    <img src="{{ $thumb }}" class="card-img-top radius-12" alt="post">
                                @endif

                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-8">
                                        <span class="badge bg-secondary-600">
                                            {{ $isCarousel ? 'CARRUSEL' : $statusType }}
                                        </span>
                                        <small class="text-primary-light">{{ $fecha }}</small>
                                    </div>

                                    @if(!empty($it['message']))
                                        <p class="mb-0" style="white-space: pre-line;">
                                            {{ \Illuminate\Support\Str::limit($it['message'], 160) }}
                                        </p>
                                    @endif
                                </div>

                                <div class="card-body pt-0">
                                    @if(!empty($it['permalink_url']))
                                        <a href="{{ $it['permalink_url'] }}" target="_blank" rel="noopener"
                                           class="btn btn-sm btn-outline-primary radius-8">
                                            Ver en Facebook
                                        </a>
                                    @endif
                                </div>

                                {{-- Si es carrusel, muestra miniaturas de hijos/adjuntos --}}
                                {{-- @if($isCarousel)
                                    <div class="card-body pt-0">
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach(data_get($it, 'attachments.data', []) as $att)
                                                @if(!empty($att['subattachments']['data']))
                                                    @foreach($att['subattachments']['data'] as $sub)
                                                        @if(!empty($sub['media']['image']['src']))
                                                            <img src="{{ $sub['media']['image']['src'] }}"
                                                                 class="img-thumbnail radius-8" style="max-width: 120px;">
                                                        @endif
                                                    @endforeach
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif --}}

                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Paginación (cursors) --}}
            <div class="d-flex align-items-center justify-content-center gap-3">
                @if(!empty($prev))
                    <a class="border bg-hover-warning-100 text-warning-600 text-md px-24 py-8 radius-8"
                       href="{{ route('facebook.posts', [$perfil->id, 'before' => $prev]) }}">
                        &laquo; Mas Recientes
                    </a>
                @endif
                @if(!empty($next))
                    <a class="btn btn-primary text-md px-24 py-8 radius-8"
                       href="{{ route('facebook.posts', [$perfil->id, 'after' => $next]) }}">
                        Anteriores &raquo;
                    </a>
                @endif
            </div>

        </div>
    </div>
</div>
@endsection