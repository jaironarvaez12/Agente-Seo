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
                                           value="{{ old('name', $dominio->nombre ?? '') }}"
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
                                           value="{{ old('usuario', $dominio->usuario ?? '') }}">
                                </div>

                                <div class="mb-20">
                                    <label for="password" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Contraseña<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="password" class="form-control radius-8" id="password" name="password"
                                           placeholder="Ingrese su contraseña ">
                                </div>

                                {{-- ============================================
                                   PLANTILLAS (WP) -> Guarda elementor_template_path
                                   ============================================ --}}
                               @php
                                    $wpBase = 'https://testingseo.entornodedesarrollo.es';
                                    $secret = env('TSEO_TPL_SECRET');

                                    $wpIdToJsonPath = [
                                        179 => 'elementor/elementor-10.json',
                                        130 => 'elementor/elementor-idei.json',
                                    ];

                                    // ✅ NUEVO MAPA: ID WP -> imagen
                                    $wpIdToBg = [
                                     
                                        130 => asset('assets/images/PRUEBA.png'),
                                    ];

                                    // Imagen por defecto (si no hay en el mapa)
                                    $defaultBg = asset('assets/images/tpls/default.png');

                                    $selectedPath = old('elementor_template_path', $dominio->elementor_template_path ?? '');
                                @endphp

                                <div class="mb-20">
                                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Plantilla Elementor
                                    </label>

                                    {{-- Este es el que se guarda en DB --}}
                                    <input type="hidden" id="elementor_template_path" name="elementor_template_path" value="{{ $selectedPath }}">

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

                                                    // Preview real (solo al dar "Ver")
                                                    $ts = time();
                                                    $sig = hash_hmac('sha256', $ts.'.preview.'.$id, $secret);
                                                    $previewUrl = $wpBase.'/?tseo_preview=1&id='.$id.'&ts='.$ts.'&sig='.$sig;

                                                    // Path que se va a guardar en elementor_template_path
                                                    $jsonPath = $wpIdToJsonPath[$id] ?? '';
                                                    $isSelected = ($jsonPath !== '' && $selectedPath === $jsonPath);
                                                @endphp

                                                <div class="col-md-6">
                                                    <div class="tpl-card border radius-12 overflow-hidden bg-white {{ $isSelected ? 'tpl-selected' : '' }}">
                                                        {{-- Miniatura genérica (solo imagen visual) --}}
                                                       @php
                                                            $fixedBg = $wpIdToBg[$id] ?? null; // ✅ si no existe, queda gris
                                                        @endphp


                                                        <div class="d-flex align-items-center justify-content-center position-relative overflow-hidden"
                                                            style="
                                                                height:180px;
                                                                background:#f6f7f9;
                                                                @if(!empty($fixedBg))
                                                                    background-image:url('{{ $fixedBg }}');
                                                                    background-position:center;
                                                                    background-size:cover;
                                                                    background-repeat:no-repeat;
                                                                @endif
                                                            ">

                                                            {{-- Overlay para legibilidad (solo si hay imagen) --}}
                                                            @if(!empty($fixedBg))
                                                                <div class="position-absolute top-0 start-0 w-100 h-100"
                                                                    style="background:rgba(0,0,0,.35);"></div>
                                                            @endif

                                                            <div class="text-center px-3 position-relative" style="z-index:1;">
                                                                <div class="fw-semibold {{ !empty($fixedBg) ? 'text-white' : 'text-dark' }}">
                                                                    {{ $title }}
                                                                </div>

                                                                <small class="{{ !empty($fixedBg) ? 'text-white-50' : 'text-muted' }}">
                                                                    WP ID: {{ $id }}
                                                                </small>

                                                                @if($jsonPath)
                                                                    <div class="mt-1">
                                                                        <small class="{{ !empty($fixedBg) ? 'text-white-50' : 'text-muted' }}">
                                                                            Guardará: {{ $jsonPath }}
                                                                        </small>
                                                                    </div>
                                                                @else
                                                                    <div class="mt-1">
                                                                        <small class="{{ !empty($fixedBg) ? 'text-warning' : 'text-warning' }}">
                                                                            Sin mapeo a JSON
                                                                        </small>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </div>

                                                        <div class="p-12 d-flex align-items-center justify-content-between gap-2">
                                                            <a href="{{ $previewUrl }}"
                                                               target="_blank"
                                                               rel="noopener noreferrer"
                                                               class="btn btn-sm btn-primary">
                                                                Ver
                                                            </a>

                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-primary tpl-select-btn"
                                                                    data-path="{{ $jsonPath }}"
                                                                    {{ $jsonPath === '' ? 'disabled' : '' }}>
                                                                Seleccionar
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>

                                        <small class="text-muted d-block mt-2">
                                            - <strong>Ver</strong> abre la plantilla completa (solo vista) en WordPress.<br>
                                            - <strong>Seleccionar</strong> guardará el valor en <code>elementor_template_path</code> (cuando exista mapeo).
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

<style>
  .tpl-selected { border:2px solid #3b82f6 !important; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
</style>

<script>
  // Guardar el JSON path seleccionado en elementor_template_path
  document.querySelectorAll('.tpl-select-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const path = btn.dataset.path || '';
      if (!path) return;

      const input = document.getElementById('elementor_template_path');
      if (input) input.value = path;

      document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('tpl-selected'));
      btn.closest('.tpl-card')?.classList.add('tpl-selected');
    });
  });

  // Tu listener de imagen (queda igual)
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
@endsection
