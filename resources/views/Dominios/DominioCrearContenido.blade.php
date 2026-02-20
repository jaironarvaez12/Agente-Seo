@extends('layouts.master')

@section('titulo', 'Crear Dominios')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')
<div class="dashboard-main-body">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Generador de Contenido</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="index.html" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Dominios
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Generador de Contenido</li>
  </ul>
</div>
      <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <div class="row justify-content-center">
                <div class="col-xxl-6 col-xl-8 col-lg-10">
                    <div class="card border">
                        <div class="card-body">

                           <form method="POST" action="{{ route('dominiotipogenerador',$IdDominio) }}">
                                @csrf

                                <div class="mb-20">
                                    <label for="nombre" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                       Nombre del Tipo de Contenido<span class="text-danger-600">*</span>
                                    </label>
                                   <select class="form-control radius-8 form-select" id="tipo" name="tipo" required>
                                        <option value="0">Seleccione un tipo de contenido</option>

                                        @foreach($tiposDisponibles as $t)
                                          <option value="{{ $t }}">{{ $t }}</option>
                                        @endforeach
                                      </select>

                                      @if(empty($tiposDisponibles))
                                        <small class="text-muted d-block mt-2">
                                          Este dominio ya tiene creados los 2 tipos (POST y PAGINAS).
                                        </small>
                                      @endif
                                </div>

                               <div class="mb-20">
                                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Palabras Clave
                                    </label>

                                    <!-- Input para escribir palabras -->
                                    <input type="text" class="form-control radius-8" id="keywordInput"
                                            placeholder="Escribe una palabra y presiona Enter">

                                    <!-- Donde se ven los chips -->
                                    <div id="keywordChips" class="d-flex flex-wrap gap-2 mt-2"></div>

                                    <!-- Lo que realmente se envía al servidor -->
                                    <input type="hidden" name="palabras_clave" id="palabrasClaveHidden"
                                            value='{{ old("palabras_clave", "[]") }}'>

                                    <small class="text-muted d-block mt-2">
                                        Enter o coma para agregar. Click en × para quitar.
                                    </small>
                                </div>
                                <div class="mt-3 p-16 radius-8 bg-warning-focus border border-warning-main">
                                    <div class="d-flex align-items-start gap-2">
                                      <iconify-icon icon="mdi:lightbulb-on-outline" class="icon text-xl"></iconify-icon>
                                      <div>
                                        <div class="fw-semibold mb-1">Tips para elegir palabras clave</div>

                                        <ul class="mb-0 text-sm">
                                          <li><strong>Mejor:</strong> usa frases específicas (2–5 palabras). Ej: <em>“abogado laboral en Bogotá”</em>, <em>“diseño web para restaurantes”</em>.</li>
                                          <li><strong>Incluye intención:</strong> agrega palabras como <em>precio</em>, <em>cotización</em>, <em>cerca de mí</em>, <em>servicio</em>, <em>contratar</em>.</li>
                                          <li><strong>Usa ubicación</strong> si el negocio es local: ciudad, barrio, país. Ej: <em>“clínica dental Medellín”</em>.</li>
                                          <li><strong>Evita genéricas:</strong> <em>“marketing”</em>, <em>“SEO”</em>, <em>“páginas web”</em> solas compiten demasiado.</li>
                                          <li><strong>No repitas variaciones iguales:</strong> si ya tienes <em>“diseño web”</em>, no agregues 10 cambios mínimos; mejor agrega otra intención (<em>precio</em>, <em>paquetes</em>, <em>ejemplos</em>).</li>
                                          <li><strong>Evita marcas ajenas</strong> si no tienes permiso (puede causar problemas en anuncios/SEO).</li>
                                          <li><strong>Calidad &gt; cantidad:</strong> 5–15 palabras clave bien elegidas rinden mejor que 50 sin foco.</li>
                                        </ul>

                                        <div class="text-sm mt-2">
                                          <strong>Ejemplos:</strong>
                                          <span class="badge bg-info-focus text-info-600 border border-info-main me-1">“página web precio fijo”</span>
                                          <span class="badge bg-info-focus text-info-600 border border-info-main me-1">“agencia SEO en Cali”</span>
                                          <span class="badge bg-info-focus text-info-600 border border-info-main">“landing page para psicólogos”</span>
                                        </div>
                                      </div>
                                    </div>
                                  </div>

                                {{-- <div class="mb-20">
                                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Texto de prueba
                                    </label>

                                    <textarea class="form-control radius-8" id="textoPrueba" name="texto_prueba"
                                            rows="10" placeholder="Aquí se generará el texto...">{{ old('texto_prueba') }}</textarea>
                                </div> --}}
                                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                                    <button type="button"
                                            onclick="window.location.href='{{route('dominios.show', $IdDominio) }}'"
                                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                                        Cancelar
                                    </button>
                                    {{-- <button type="button" id="btnGenerarTexto"
                                            class="btn btn-outline-primary border border-primary-600 text-md px-56 py-12 radius-8">
                                        Generar texto de prueba
                                    </button> --}}

                                    @if(!empty($tiposDisponibles))
                                      <button type="submit"
                                              class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                                          Guardar
                                      </button>
                                    @endif
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
  const input = document.getElementById('keywordInput');
  const chips = document.getElementById('keywordChips');
  const hidden = document.getElementById('palabrasClaveHidden');

  // Cargar old() si viene (JSON)
  let keywords = [];
  try {
    keywords = JSON.parse(hidden.value || "[]");
    if (!Array.isArray(keywords)) keywords = [];
  } catch (e) { keywords = []; }

  function syncHidden() {
    hidden.value = JSON.stringify(keywords);
  }

  function render() {
    chips.innerHTML = '';
    keywords.forEach((k, idx) => {
      const chip = document.createElement('span');
      chip.className = 'badge bg-primary-600 text-white d-inline-flex align-items-center gap-2 px-3 py-2 radius-8';
      chip.innerHTML = `
        <span>${escapeHtml(k)}</span>
        <button type="button" class="btn btn-sm p-0 text-white" aria-label="Eliminar" style="line-height:1"
                data-idx="${idx}">×</button>
      `;
      chips.appendChild(chip);
    });
    syncHidden();
  }

  // Eliminar chip
  chips.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-idx]');
    if (!btn) return;
    const idx = Number(btn.dataset.idx);
    keywords.splice(idx, 1);
    render();
  });

  // Agregar por Enter o coma
  function addKeyword(raw) {
    const k = (raw || '').trim().replace(/\s+/g, ' ');
    if (!k) return;

    // evitar duplicados (case-insensitive)
    const exists = keywords.some(x => x.toLowerCase() === k.toLowerCase());
    if (exists) return;

    keywords.push(k);
    render();
  }

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      addKeyword(input.value);
      input.value = '';
    }
  });

  input.addEventListener('input', () => {
    // si escribe coma, agrega lo que haya antes de la coma
    if (input.value.includes(',')) {
      const parts = input.value.split(',');
      parts.slice(0, -1).forEach(p => addKeyword(p));
      input.value = parts[parts.length - 1];
    }
  });

  function escapeHtml(str) {
    return str.replace(/[&<>"']/g, (m) => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }

  // Render inicial
  render();
</script>

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
<script>
  const btnGenerarTexto = document.getElementById('btnGenerarTexto');
  const textareaTexto = document.getElementById('textoPrueba');
  const selectTipo = document.getElementById('id_dominio');

  btnGenerarTexto.addEventListener('click', () => {
    let kws = [];
    try {
      kws = JSON.parse(document.getElementById('palabrasClaveHidden').value || '[]');
      if (!Array.isArray(kws)) kws = [];
    } catch(e) { kws = []; }

    const tipo = (selectTipo.value && selectTipo.value !== '0') ? selectTipo.value : 'CONTENIDO';
    const lista = kws.length ? kws.join(', ') : 'sin palabras clave aún';

    const texto =
`[${tipo}] Texto de prueba

Palabras clave: ${lista}

Introducción:
Este es un texto de prueba generado automáticamente para ayudarte a validar el formulario y el flujo de guardado.

Desarrollo:
Aquí puedes expandir el contenido. La idea es simular un artículo/landing con estructura básica, incluyendo las palabras clave de forma natural.
- Punto 1 relacionado con: ${kws[0] || 'tema principal'}
- Punto 2 relacionado con: ${kws[1] || 'tema secundario'}
- Punto 3 relacionado con: ${kws[2] || 'tema adicional'}

Cierre:
Ajusta el texto según tu necesidad y luego presiona Guardar.
`;

    textareaTexto.value = texto;
    textareaTexto.focus();
  });
</script>
@endsection


    
  

  