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
                <div class="col-xxl-6 col-xl-8 col-lg-10">
                    <div class="card border">
                        <div class="card-body">

                            <form method="POST" action="{{ route('dominios.update', $dominio->id_dominio) }}">
                                @csrf
                                @method('put')

                                <div class="mb-20">
                                    <label for="nombre" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Nombre del Dominio<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="text" class="form-control radius-8" id="nombre" name="nombre"
                                           value=" {{ old('name', $dominio->nombre ?? '') }}"
                                           placeholder="Ej: IdeiWeb.com" readonly>
                                </div>

                                <div class="mb-20">
                                    <label for="url" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Url
                                    </label>
                                    <textarea class="form-control radius-8" id="url" name="url" readonly
                                              rows="2" placeholder="https://ideiweb.com/">{{ old('url', $dominio->url ?? '') }}</textarea>
                                </div>

                                <div class="mb-20">
                                    <label for="usuario" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Usuario<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="text" class="form-control radius-8" id="usuario" name="usuario"
                                           value=" {{ old('usuario', $dominio->usuario ?? '') }}">
                                </div>

                                <div class="mb-20">
                                    <label for="password" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Contraseña<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="password" class="form-control radius-8" id="password" name="password"
                                           placeholder="Ingrese su contraseña ">
                                </div>

                                {{-- =========================
                                   PLANTILLAS (solo imagen + botón ver)
                                   ========================= --}}
                                @php
                                    $wpBase = 'https://testingseo.entornodedesarrollo.es';
                                    $secret = env('TSEO_TPL_SECRET');

                                    // Si luego quieres guardar selección, descomenta el hidden
                                    // $selectedTplId = old('wp_template_id', $dominio->wp_template_id ?? '');
                                @endphp

                                <div class="mb-20">
                                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Plantillas (testingseo)
                                    </label>

                                    {{-- Opcional si quieres guardar el ID seleccionado (requiere campo en DB)
                                    <input type="hidden" id="wp_template_id" name="wp_template_id" value="{{ $selectedTplId }}">
                                    --}}

                                    @if(empty($plantillas))
                                        <div class="alert alert-warning mb-0">
                                            No se pudieron cargar las plantillas desde WordPress.
                                        </div>
                                    @else
                                        <div class="row g-3">
                                            @foreach($plantillas as $tpl)
                                                @php
                                                    $id = $tpl['id'] ?? null;
                                                    $title = $tpl['title'] ?? 'Sin título';

                                                    // Preview real (solo cuando den click en VER)
                                                    $ts = time();
                                                    $sig = hash_hmac('sha256', $ts.'.preview.'.$id, $secret);
                                                    $previewUrl = $wpBase.'/?tseo_preview=1&id='.$id.'&ts='.$ts.'&sig='.$sig;
                                                @endphp

                                                <div class="col-md-6">
                                                    <div class="tpl-card border radius-12 overflow-hidden bg-white">
                                                        {{-- Miniatura genérica (sin iframe) --}}
                                                        <div class="tpl-thumb d-flex align-items-center justify-content-center"
                                                             style="height:180px; background:#f6f7f9;">
                                                            <div class="text-center px-3">
                                                                <div class="fw-semibold text-dark">{{ $title }}</div>
                                                                <small class="text-muted">ID: {{ $id }}</small>
                                                            </div>
                                                        </div>

                                                        <div class="p-12 d-flex align-items-center justify-content-between gap-2">
                                                            <small class="text-muted">
                                                                Vista previa (imagen)
                                                            </small>

                                                            <div class="d-flex gap-2">
                                                                <a href="{{ $previewUrl }}"
                                                                   target="_blank"
                                                                   rel="noopener noreferrer"
                                                                   class="btn btn-sm btn-primary">
                                                                    Ver
                                                                </a>

                                                                {{-- Opcional: seleccionar para guardar ID
                                                                <button type="button" class="btn btn-sm btn-outline-primary tpl-select-btn" data-id="{{ $id }}">
                                                                    Seleccionar
                                                                </button>
                                                                --}}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>

                                        <small class="text-muted d-block mt-2">
                                            Pulsa <strong>Ver</strong> para abrir la plantilla completa (solo vista) en una nueva pestaña.
                                        </small>
                                    @endif
                                </div>

                                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                                    <button type="button"
                                            onclick="window.location.href='{{ route('inicio') }}'"
                                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                                        Cancelar
                                    </button>

                                    <button type="submit"
                                            class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
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

@section('scripts')
<script type="text/javascript" src="{{ asset('assets\js\Articulos.js') }}"></script>
<script src="{{ asset('assets/js/lib/file-upload.js') }}"></script>

<script>
  document.getElementById('imagen')?.addEventListener('change', function (e) {
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const url = URL.createObjectURL(file);
    const imgTag = document.getElementById('avatar-img');
    const link = document.querySelector('.popup-img');

    if (imgTag) {
      imgTag.src = url;
    }
    if (link) {
      link.href = url;
    }

    const img = new Image();
    img.onload = () => URL.revokeObjectURL(url);
    img.src = url;
  });
</script>

<script src="{{ asset('assets/js/lib/magnifc-popup.min.js') }}"></script>

<script>
    $('.popup-img').magnificPopup({
        type: 'image',
        gallery: { enabled: true }
    });
</script>

{{-- Opcional: si activas "Seleccionar" arriba, descomenta esto también
<script>
  document.querySelectorAll('.tpl-select-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id || '';
      const input = document.getElementById('wp_template_id');
      if (input) input.value = id;
      alert('Plantilla seleccionada: ' + id);
    });
  });
</script>
--}}
@endsection
