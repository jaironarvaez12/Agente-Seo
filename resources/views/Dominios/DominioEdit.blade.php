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

                            <form method="POST" action="{{ route('dominios.update', $dominio->id_dominio) }}" enctype="multipart/form-data">
                                @csrf
                                @method('put')

                                <div class="mb-20">
                                    <label for="nombre" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Nombre del Dominio<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="text" class="form-control radius-8" id="nombre" name="nombre"
                                           value="{{ old('nombre', $dominio->nombre ?? '') }}"
                                           placeholder="Ej: IdeiWeb.com" >
                                </div>

                                <div class="mb-20">
                                    <label for="url" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Url
                                    </label>
                                    <textarea class="form-control radius-8" id="url" name="url" readonly
                                              rows="2" placeholder="https://ideiweb.com/">{{ old('url', $dominio->url ?? '') }}</textarea>
                                </div>

                                <!-- Upload Image Start -->
                                <div class="mb-24 mt-16">
                                    <label for="url" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Logo
                                    </label>
                                    <div class="avatar-upload">
                                        <div class="avatar-edit position-absolute bottom-0 end-0 me-24 mt-16 z-1 cursor-pointer">
                                            <input type='file' id="imagen" name="imagen" accept=".png, .jpg, .jpeg" hidden >
                                            <label for="imagen" class="w-32-px h-32-px d-flex justify-content-center align-items-center bg-primary-50 text-primary-600 border border-primary-600 bg-hover-primary-100 text-lg rounded-circle">
                                                <iconify-icon icon="solar:camera-outline" class="icon"></iconify-icon>
                                            </label>
                                        </div>
                                        @php
                                            $imgRel = $dominio->imagen ?? null;
                                            $baseUrl = ($imgRel && file_exists(public_path($imgRel)))
                                                ? asset($imgRel)
                                                : asset('images/placeholder.jpg');
                                            $imgUrl = $baseUrl . '?v=' . time();
                                        @endphp

                                        <div class="hover-scale-img border radius-16 overflow-hidden p-8" style="width:160px;">
                                            <a href="{{ $imgUrl }}" class="popup-img w-100 h-100 d-flex radius-12 overflow-hidden">
                                                <img id="avatar-img"
                                                     src="{{ $imgUrl }}"
                                                     alt="Imagen"
                                                     class="hover-scale-img__img w-100 h-100 object-fit-cover radius-12"
                                                     style="object-fit: cover; height:160px;">
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-switch switch-primary d-flex align-items-center gap-3 mb-12">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        id="solo_html"
                                        name="solo_html"
                                        value="1"
                                        {{ old('solo_html', is_null($dominio->elementor_template_path)) ? 'checked' : '' }}
                                    >
                                    <label class="form-check-label line-height-1 fw-medium text-secondary-light" for="solo_html">
                                        Solo texto HTML (no usar plantilla Elementor)
                                    </label>
                                </div>

                                {{-- =========================================================
                                   PLANTILLAS -> elementor_template_path (WP + Local, sin duplicados)
                                   ========================================================= --}}
                                @php
                                    $wpBase = env('TESTINGSEO_WP_URL', 'https://testingseo.entornodedesarrollo.es');
                                    $secret = (string) env('TSEO_TPL_SECRET', '');

                                    // WP ID -> JSON path
                                    $wpIdToJsonPath = [
                                        179 => 'elementor/elementor-10.json',
                                        130 => 'elementor/elementor-idei.json',
                                        265 => 'elementor/elementor-nueva.json',
                                    ];

                                    // WP ID -> imagen (opcional)
                                    $wpIdToBg = [
                                        130 => asset('assets/images/PRUEBA.png'),
                                        179 => asset('assets/images/Plantilla Nueva.png'),
                                    ];

                                    $selectedPath = old('elementor_template_path', $dominio->elementor_template_path ?? '');

                                    // ---------- DEDUPE ----------
                                    // 1) paths cubiertos por WP (según tu mapeo wpIdToJsonPath)
                                    $wpPaths = [];
                                    if (!empty($plantillas)) {
                                        foreach ($plantillas as $tpl) {
                                            $id = $tpl['id'] ?? null;
                                            $p = $wpIdToJsonPath[$id] ?? '';
                                            if (!empty($p)) $wpPaths[$p] = true;
                                        }
                                    }

                                    // 2) filtrar locales: quitar las que ya estén cubiertas por WP
                                    $plantillasLocalFiltradas = [];
                                    if (!empty($plantillasLocal)) {
                                        foreach ($plantillasLocal as $t) {
                                            $p = $t['path'] ?? '';
                                            if (empty($p)) continue;
                                            if (!isset($wpPaths[$p])) {
                                                $plantillasLocalFiltradas[] = $t;
                                            }
                                        }
                                    }
                                @endphp

                                <div class="mb-20">
                                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Plantilla Elementor
                                    </label>

                                    <input type="hidden" id="elementor_template_path" name="elementor_template_path" value="{{ $selectedPath }}">

                                    {{-- =========================
                                       Sección WP (con previsualización)
                                       ========================= --}}
                                    <div class="mt-2 mb-2">
                                        <div class="fw-semibold text-secondary-light">Plantillas WordPress (con preview)</div>
                                        <small class="text-muted">Estas se cargan desde WordPress y permiten "Ver".</small>
                                    </div>

                                    @if(empty($plantillas))
                                        <div class="alert alert-warning mb-3">
                                            No se pudieron cargar las plantillas desde WordPress.
                                        </div>
                                    @else
                                        <div class="row g-3 mb-4">
                                            @foreach($plantillas as $tpl)
                                                @php
                                                    $id = $tpl['id'] ?? null;
                                                    $title = $tpl['title'] ?? 'Sin título';

                                                    // Preview WP (como lo tenías)
                                                    $ts = time();
                                                    $sigPrev = hash_hmac('sha256', $ts.'.preview.'.$id, $secret);
                                                    $previewUrl = rtrim($wpBase,'/').'/?tseo_preview=1&id='.$id.'&ts='.$ts.'&sig='.$sigPrev;

                                                    // Path que se guardará en DB
                                                    $jsonPath = $wpIdToJsonPath[$id] ?? '';
                                                    $isSelected = ($jsonPath !== '' && $selectedPath === $jsonPath);

                                                    $fixedBg = $wpIdToBg[$id] ?? null;
                                                @endphp

                                                <div class="col-md-6">
                                                    <div class="tpl-card border radius-12 overflow-hidden bg-white {{ $isSelected ? 'tpl-selected' : '' }}">
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
                                                                        <small class="text-warning">
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
                                    @endif

                                    {{-- =========================
                                       Sección Local (sin previsualización, sin duplicados)
                                       ========================= --}}
                                    <div class="mt-2 mb-2">
                                        <div class="fw-semibold text-secondary-light">Plantillas Locales (sin preview)</div>
                                        <small class="text-muted">Solo se muestran las que NO estén ya cubiertas por WordPress.</small>
                                    </div>

                                    @if(empty($plantillasLocalFiltradas))
                                        <div class="alert alert-warning mb-0">
                                            No hay plantillas locales nuevas (las demás ya están en WordPress).
                                        </div>
                                    @else
                                        <div class="row g-3">
                                            @foreach($plantillasLocalFiltradas as $tpl)
                                                @php
                                                    $path = $tpl['path'] ?? '';
                                                    $title = $tpl['name'] ?? 'Plantilla local';
                                                    $isSelected = ($path !== '' && $selectedPath === $path);
                                                @endphp

                                                <div class="col-md-6">
                                                    <div class="tpl-card border radius-12 overflow-hidden bg-white {{ $isSelected ? 'tpl-selected' : '' }}">
                                                        <div class="d-flex align-items-center justify-content-center position-relative overflow-hidden"
                                                             style="height:180px;background:#f6f7f9;">
                                                            <div class="text-center px-3">
                                                                <div class="fw-semibold text-dark">{{ $title }}</div>
                                                                <small class="text-muted">Guardará: {{ $path }}</small>
                                                            </div>
                                                        </div>

                                                        <div class="p-12 d-flex align-items-center justify-content-end gap-2">
                                                            <button type="button"
                                                                    class="btn btn-sm btn-outline-primary tpl-select-btn"
                                                                    data-path="{{ $path }}"
                                                                    {{ $path === '' ? 'disabled' : '' }}>
                                                                Seleccionar
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <small class="text-muted d-block mt-2">
                                        - <strong>Ver</strong> abre la plantilla completa en WordPress (solo WP).<br>
                                        - <strong>Seleccionar</strong> guardará el valor en <code>elementor_template_path</code>.
                                    </small>
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

  // Listener de imagen (igual)
  document.getElementById('imagen')?.addEventListener('change', function (e) {
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const url = URL.createObjectURL(file);
    const imgTag = document.getElementById('avatar-img');
    const link = document.querySelector('.popup-img');

    if (imgTag) imgTag.src = url;
    if (link) link.href = url;

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

<script>
  const soloHtml = document.getElementById('solo_html');
  const inputPath = document.getElementById('elementor_template_path');

  function applySoloHtmlState() {
    const checked = !!soloHtml?.checked;

    if (checked) {
      if (inputPath) inputPath.value = '';
      document.querySelectorAll('.tpl-card').forEach(c => c.classList.remove('tpl-selected'));
      document.querySelectorAll('.tpl-select-btn').forEach(b => b.disabled = true);
    } else {
      document.querySelectorAll('.tpl-select-btn').forEach(b => {
        const p = b.dataset.path || '';
        b.disabled = (p === '');
      });
    }
  }

  soloHtml?.addEventListener('change', applySoloHtmlState);
  applySoloHtmlState();

  // Si selecciona plantilla, apaga el switch automáticamente
  document.querySelectorAll('.tpl-select-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (soloHtml && soloHtml.checked) soloHtml.checked = false;
      applySoloHtmlState();
    });
  });
</script>
@endsection
