@extends('layouts.master')

@section('titulo', 'Publicaciones de Instagram')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Publicaciones de Instagram</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('perfiles.index') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">{{ $perfil->fb_page_name ?? 'Instagram' }}</li>
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
                    @foreach($items as $m)
                        @php
                            $isVideo = ($m['media_type'] ?? '') === 'VIDEO';
                            $thumb = $isVideo ? ($m['thumbnail_url'] ?? null) : ($m['media_url'] ?? null);
                        @endphp
                        <div class="col-xxl-3 col-xl-4 col-lg-4 col-md-6 mb-20">
                            <div class="card border h-100 radius-12">
                                @if($thumb)
                                    <img src="{{ $thumb }}" class="card-img-top radius-12" alt="media">
                                @endif
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-8">
                                        <span class="badge bg-secondary-600">{{ $m['media_type'] ?? 'MEDIA' }}</span>
                                        <small class="text-primary-light">
                                            {{ \Carbon\Carbon::parse($m['timestamp'])->format('d/m/Y H:i') }}
                                        </small>
                                    </div>
                                    @if(!empty($m['caption']))
                                        <p class="mb-0" style="white-space: pre-line;">
                                            {{ \Illuminate\Support\Str::limit($m['caption'], 160) }}
                                        </p>
                                    @endif
                                </div>
                                <div class="card-body pt-0">
                                    @if(!empty($m['permalink']))
                                        <a href="{{ $m['permalink'] }}" target="_blank" rel="noopener"
                                           class="btn btn-sm btn-outline-primary radius-8">
                                            Ver en Instagram
                                        </a>
                                    @endif
                                </div>

                                {{-- Carrusel: hijos --}}
                                {{-- @if(!empty($m['children']['data']))
                                    <div class="card-body pt-0">
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach($m['children']['data'] as $child)
                                                @php
                                                    $ct = ($child['media_type'] ?? '') === 'VIDEO';
                                                    $cthumb = $ct ? ($child['thumbnail_url'] ?? null) : ($child['media_url'] ?? null);
                                                @endphp
                                                @if($cthumb)
                                                    <img src="{{ $cthumb }}" class="img-thumbnail radius-8" style="max-width: 120px;">
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
                       href="{{ route('perfilesinsta', [$perfil->id, 'before' => $prev]) }}">
                        &laquo; Anteriores
                    </a>
                @endif
                @if(!empty($next))
                    <a class="btn btn-primary text-md px-24 py-8 radius-8"
                       href="{{ route('perfilesinsta', [$perfil->id, 'after' => $next]) }}">
                        Más recientes &raquo;
                    </a>
                @endif
            </div>

        </div>
    </div>
</div>
@endsection