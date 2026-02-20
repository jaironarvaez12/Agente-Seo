@extends('layouts.master')

@section('titulo', 'Editar Dominios')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

@php
  $raw = old('palabras_claves', $generador->palabras_claves ?? []);

  // Normaliza a array
  if (is_string($raw)) {
      $decoded = json_decode($raw, true);

      if (is_array($decoded)) {
          $raw = $decoded; // era JSON
      } else {
          // era texto tipo: "uno, dos, tres"
          $raw = array_values(array_filter(array_map('trim', explode(',', $raw))));
      }
  }

  if (!is_array($raw)) $raw = [];

  $palabrasClaveValue = json_encode($raw, JSON_UNESCAPED_UNICODE);

@endphp

<div class="dashboard-main-body">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Generador de Contenido (Editar)</h6>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dominios
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Editar Generador de Contenido</li>
    </ul>
  </div>

  <div class="card h-100 p-0 radius-12">
    <div class="card-body p-24">
      <div class="row justify-content-center">
        <div class="col-xxl-6 col-xl-8 col-lg-10">
          <div class="card border">
            <div class="card-body">

              <form method="POST" action="{{ route('dominioguardarediciontipo',$generador->id_dominio_contenido) }}">
                @csrf
               

                <div class="mb-20">
                    <label for="tipo" class="form-label fw-semibold text-primary-light text-sm mb-8">
                        Nombre del Tipo de Contenido <span class="text-danger-600">*</span>
                    </label>

                    <select class="form-control radius-8 form-select"
                            id="tipo" name="tipo"
                            {{ !$puedeCambiar ? 'disabled' : '' }}>
                        @foreach($tiposDisponibles as $t)
                        <option value="{{ $t }}" {{ $generador->tipo == $t ? 'selected' : '' }}>
                            {{ $t }}
                        </option>
                        @endforeach
                    </select>

                    @if(!$puedeCambiar)
                        <small class="text-muted d-block mt-2">
                        No se puede cambiar el tipo porque este dominio ya tiene POST y PAGINAS.
                        </small>

                        {{-- IMPORTANTE: si deshabilitas el select, no se envía; manda el actual --}}
                        <input type="hidden" name="tipo" value="{{ $generador->tipo }}">
                    @endif
                    </div>

                <div class="mb-20">
                  <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                    Palabras Clave
                  </label>

                  <input type="text" class="form-control radius-8" id="keywordInput"
                         placeholder="Escribe una palabra y presiona Enter">

                  <div id="keywordChips" class="d-flex flex-wrap gap-2 mt-2"></div>

                  <input type="hidden" name="palabras_claves" id="palabrasClaveHidden"
                         value='{{ $palabrasClaveValue }}'>

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


                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                  <button type="button"
                          onclick="window.location.href='{{route('dominios.show', $generador->id_dominio)}}'"
                          class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                    Cancelar
                  </button>

                  <button type="submit"
                          class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                    Actualizar
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
<script type="text/javascript" src="{{ asset('assets/js/Articulos.js') }}"></script>
<script src="{{ asset('assets/js/lib/file-upload.js') }}"></script>

<script>
  (function () {
    const input  = document.getElementById('keywordInput');
    const chips  = document.getElementById('keywordChips');
    const hidden = document.getElementById('palabrasClaveHidden');

    let keywords = [];
    try {
      const parsed = JSON.parse(hidden.value || "[]");
      keywords = Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      keywords = [];
    }

    function escapeHtml(str) {
      return String(str).replace(/[&<>"']/g, (m) => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
      }[m]));
    }

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

    function addKeyword(raw) {
      const k = (raw || '').trim().replace(/\s+/g, ' ');
      if (!k) return;

      const exists = keywords.some(x => String(x).toLowerCase() === k.toLowerCase());
      if (exists) return;

      keywords.push(k);
      render();
    }

    // --- Prefill del input con lo que viene del servidor ---
    let inputPrefilled = false;
    if (keywords.length) {
      input.value = keywords.join(', ');
      inputPrefilled = true;
    }

    // Limpia el prefill al empezar a editar
    function clearPrefillIfNeeded() {
      if (inputPrefilled) {
        input.value = '';
        inputPrefilled = false;
      }
    }
    input.addEventListener('focus', clearPrefillIfNeeded);
    input.addEventListener('keydown', clearPrefillIfNeeded);

    // Eliminar chip
    chips.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-idx]');
      if (!btn) return;
      const idx = Number(btn.dataset.idx);
      keywords.splice(idx, 1);
      render();
    });

    // Agregar por Enter
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        addKeyword(input.value);
        input.value = '';
      }
    });

    // Agregar por coma
    input.addEventListener('input', () => {
      if (input.value.includes(',')) {
        const parts = input.value.split(',');
        parts.slice(0, -1).forEach(p => addKeyword(p));
        input.value = parts[parts.length - 1];
      }
    });

    // Render inicial
    render();
  })();
</script>
@endsection
