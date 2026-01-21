@extends('layouts.master')

@section('titulo', 'Identidad de Dominios')


@section('contenido')

@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
<div class="dashboard-main-body">
 <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
       <h6 class="fw-semibold mb-0">Identidad Visual Dominios</h6>
    <div class="d-flex align-items-center gap-3">

        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
              <a href="{!! route('dominios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
                <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                Dominios
              </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Identidad</li>
        </ul>


          

    </div>
    </div>
      <form method="POST" action="{{ route('dominiosactualizaridentidad') }}" enctype="multipart/form-data">
      @csrf
        <div class="card h-100 p-0 radius-12">
          <div class="card-body p-24">
            <div class="mb-24 mt-16">
                <label for="url" class="form-label fw-semibold text-primary-light text-sm mb-8">
                  Logo 
                </label>
              <div class="avatar-upload position-relative">
                <div class="avatar-edit position-absolute bottom-0 end-0 me-24 mb-24 z-1 cursor-pointer">
                  <input type="file" id="imagen" name="imagen" accept=".png, .jpg, .jpeg" hidden>

                  <label for="imagen"
                    class="w-48-px h-48-px d-flex justify-content-center align-items-center
                          bg-primary-50 text-primary-600 border border-primary-600
                          bg-hover-primary-100 text-xl rounded-circle">
                    <iconify-icon icon="solar:camera-outline" class="icon"></iconify-icon>
                  </label>
                </div>

                @php
                  $imgRel = null;
                  $baseUrl = ($imgRel && file_exists(public_path($imgRel)))
                    ? asset($imgRel)
                    : asset('images/faq-img.png');
                  $imgUrl = $baseUrl . '?v=' . time();
                @endphp

                <div class="hover-scale-img border radius-16 overflow-hidden p-8" style="width:320px;">
                  <a href="{{ $imgUrl }}" class="popup-img w-100 h-100 d-flex radius-12 overflow-hidden">
                    <img id="avatar-img"
                        src="{{ $imgUrl }}"
                        alt="Imagen"
                        class="hover-scale-img__img w-100 h-100 object-fit-cover radius-12"
                        style="object-fit: cover; height:320px;">
                  </a>
                </div>
              </div>
              <br>
                <div class="mb-20">
                    <label for="nombre" class="form-label fw-semibold text-primary-light text-sm mb-8">
                        Datos de ubicacion o Direccion
                    </label>
                    <input type="text" class="form-control radius-8" id="nombre" name="nombre" placeholder="Ej: Antigua Casa del Mar, Av. Perfecto Palacio de la Fuente, 1, 03003 Alicante 641051145" >
                </div>



                {{-- Color de texto seleccionado --}}
              <div class="mb-20">
                  <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                    Color de texto
                  </label>

                  {{-- Input donde se guarda el color seleccionado --}}
                  <input type="text" class="form-control radius-8 mb-12"
                        id="color_texto_preview" placeholder="Selecciona un color..." readonly>

                  <input type="hidden" id="color_texto" name="color_texto" value="">

                  {{-- Paleta --}}
                  @php
                    $paletaTexto = [
                      ['hex' => '#487FFF', 'class' => 'text-primary-600'],
                      ['hex' => '#22C55E', 'class' => 'text-success-main'],
                      ['hex' => '#EAB308', 'class' => 'text-warning-main'],
                      ['hex' => '#EF4444', 'class' => 'text-danger-main'],
                      ['hex' => '#3B82F6', 'class' => 'text-info-main'],
                      ['hex' => '#FFFFFF', 'class' => 'text-primary-light'],
                    ];
                  @endphp

                  <div class="d-flex flex-wrap gap-12" id="paleta-texto">
                    @foreach($paletaTexto as $c)
                      <button type="button"
                              class="btn btn-sm border radius-8 d-flex align-items-center gap-8 palette-item {{ $c['class'] }}"
                              data-hex="{{ $c['hex'] }}"
                              data-class="{{ $c['class'] }}"
                              style="background: transparent;">
                        <span class="fw-semibold">Aa</span>
                        <span class="fw-semibold">({{ $c['hex'] }})</span>
                      </button>
                    @endforeach
                  </div>

                  <small class="text-secondary-light d-block mt-8">
                    Tip: al seleccionar un color, se guarda en el input oculto <code>color_texto</code>.
                  </small>
                </div>

            </div>
            
          </div>
        
          <div class="card-body p-24">
             
                  <label class="form-label fw-semibold text-primary-light text-lg mb-8">
                   Seleccione los Dominios donde aplicara los cambios
                  </label>
         
            <div class="mb-24 mt-16">
              <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                Dominios
              </label>
                 <div class="form-check checked-primary d-flex align-items-center gap-2 mb-16">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    id="select_all_dominios"
                    style="border-radius:50% !important;"
                  >
                  <label class="form-check-label line-height-1 fw-medium text-secondary-light"
                        for="select_all_dominios">
                    Seleccionar todos
                  </label>
                </div>
              <div class="d-flex flex-wrap gap-16">
             
                @foreach ($dominios as $dom)
                  <div class="form-check checked-primary d-flex align-items-center gap-2">
                    <input class="form-check-input dominio-check" type="checkbox" name="dominios[]" id="dominio_{{ $dom->id_dominio }}"
                        value="{{ $dom->id_dominio }}"
                        style="border-radius:50% !important;"
                      >
                      <label class="form-check-label line-height-1 fw-medium text-secondary-light"
                            for="dominio_{{ $dom->id_dominio }}">
                        {{ $dom->nombre ?? $dom->url ?? ('Dominio ' . $dom->nombre) }}
                      </label>
               
                  </div>
                @endforeach
              </div>
            </div>
            
                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                    <button type="button"
                            onclick="window.location.href='{{ route('dominios.index') }}'"
                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                        Guardar
                    </button>
                </div>
            </div>
            
          
        </div>
      </form>
    </div>

@endsection

@section('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const palette = document.getElementById('paleta-texto');
    if (!palette) return;

    const hidden = document.getElementById('color_texto');
    const preview = document.getElementById('color_texto_preview');

    const setSelected = (btn) => {
      // quitar selección previa
      palette.querySelectorAll('.palette-item').forEach(b => {
        b.classList.remove('active');
        b.classList.remove('border-primary-600');
      });

      // marcar seleccionado
      btn.classList.add('active');
      btn.classList.add('border-primary-600');

      const hex = btn.dataset.hex;
      const cls = btn.dataset.class;

      // ✅ Guarda HEX (recomendado para persistir en BD)
      hidden.value = hex;

      // Vista
      preview.value = hex + ' (' + cls + ')';
      preview.style.color = hex;
    };

    palette.addEventListener('click', (e) => {
      const btn = e.target.closest('.palette-item');
      if (!btn) return;
      setSelected(btn);
    });
  });
</script>
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
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const selectAll = document.getElementById('select_all_dominios');
    const checks = Array.from(document.querySelectorAll('.dominio-check'));

    if (!selectAll || checks.length === 0) return;

    // Marcar / desmarcar todos
    selectAll.addEventListener('change', () => {
      const checked = selectAll.checked;
      checks.forEach(chk => chk.checked = checked);

      // 🔥 aseguramos que nunca quede medio marcado
      selectAll.indeterminate = false;
    });

    // Si se marca/desmarca uno, solo actualiza si están TODOS marcados o no
    checks.forEach(chk => {
      chk.addEventListener('change', () => {
        const allChecked = checks.every(c => c.checked);

        selectAll.checked = allChecked;

        // 🔥 nunca medio marcado
        selectAll.indeterminate = false;
      });
    });
  });
</script>
@endsection

    
  

  