@extends('layouts.master')

@section('titulo', 'Editar Dominios')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Editar Dominios</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{!! route('dominios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Dominios
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Editar Dominios</li>
        </ul>
    </div>

    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <div class="row justify-content-center">
                <div class="col-xxl-8 col-xl-10 col-lg-12">
                    <div class="card border">
                        <div class="card-body">

                            <form method="POST" action="{{ route('dominios.update', $dominio->id_dominio) }}">
                                @csrf
                                @method('put')

                                {{-- =====================
                                   DATOS DEL DOMINIO
                                   ===================== --}}
                                <div class="mb-20">
                                    <label class="form-label fw-semibold text-sm mb-8">Dominio</label>
                                    <input type="text" class="form-control radius-8"
                                           value="{{ $dominio->nombre }}" readonly>
                                </div>

                                {{-- =====================
                                   PLANTILLAS (IMAGEN)
                                   ===================== --}}
                                @php
                                    $wpBase = 'https://testingseo.entornodedesarrollo.es';
                                    $secret = env('TSEO_TPL_SECRET');
                                @endphp

                                <div class="mb-20">
                                    <label class="form-label fw-semibold text-sm mb-8">
                                        Plantillas disponibles
                                    </label>

                                    @if(empty($plantillas))
                                        <div class="alert alert-warning">
                                            No se pudieron cargar las plantillas desde WordPress.
                                        </div>
                                    @else
                                        <div class="row g-4">
                                            @foreach($plantillas as $tpl)
                                                @php
                                                    $id = $tpl['id'];
                                                    $title = $tpl['title'];

                                                    // URL PREVIEW (solo cuando den click)
                                                    $ts = time();
                                                    $sig = hash_hmac('sha256', $ts.'.preview.'.$id, $secret);
                                                    $previewUrl = $wpBase.'/?tseo_preview=1&id='.$id.'&ts='.$ts.'&sig='.$sig;
                                                @endphp

                                                <div class="col-md-4 col-lg-3">
                                                    <div class="card h-100 radius-12 overflow-hidden">
                                                        {{-- IMAGEN ESTÁTICA --}}
                                                        <div style="height:180px;background:#f3f4f6;
                                                            display:flex;align-items:center;justify-content:center;">
                                                            <span class="text-muted text-sm">
                                                                Vista previa
                                                            </span>
                                                        </div>

                                                        <div class="p-12">
                                                            <div class="fw-semibold text-sm mb-2">
                                                                {{ $title }}
                                                            </div>

                                                            <a href="{{ $previewUrl }}"
                                                               target="_blank"
                                                               rel="noopener noreferrer"
                                                               class="btn btn-sm btn-primary w-100">
                                                                Ver
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>

                                        <small class="text-muted d-block mt-3">
                                            Pulsa <strong>Ver</strong> para abrir la plantilla completa en una nueva pestaña.
                                        </small>
                                    @endif
                                </div>

                                {{-- =====================
                                   BOTONES
                                   ===================== --}}
                                <div class="d-flex justify-content-center gap-3 mt-4">
                                    <button type="button"
                                            onclick="window.location.href='{{ route('inicio') }}'"
                                            class="btn btn-outline-danger px-5">
                                        Cancelar
                                    </button>

                                    <button type="submit"
                                            class="btn btn-primary px-5">
                                        Guardar
                                    </button>
                                </div>

                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
@endsection
