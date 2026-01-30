@extends('layouts.master')

@section('titulo', 'Auto-Generación')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')

<div class="dashboard-main-body">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Auto-Generación de Contenido</h6>

    <div class="d-flex align-items-center gap-3">
      <a href="{{ route('dominios.index') }}" class="btn btn-outline-secondary btn-sm">
        Volver a Dominios
      </a>

      {{-- Si no tienes dominios.show, cambia esta ruta o elimina el botón --}}
      <a href="{{ route('dominios.show', $dominio->id_dominio) }}" class="btn btn-outline-secondary btn-sm">
        Volver al Dominio
      </a>
    </div>
  </div>

  <div class="card h-100 p-0 radius-12">
    <div class="card-body p-24">

      <div class="mb-16">
        <div class="fw-semibold">Dominio:</div>
        <div class="text-secondary-light">{{ $dominio->nombre }} — {{ $dominio->url }}</div>
      </div>

      {{-- FORM PRINCIPAL: Auto-generación + Auto WordPress --}}
      <form action="{{ route('dominios.auto_generacion.actualizar', $dominio->id_dominio) }}" method="POST">
        @csrf

        <div class="row g-16">

          {{-- =========================
               AUTO-GENERACIÓN
          ========================== --}}
          <div class="col-12">
            <h6 class="fw-semibold mb-12">Auto-Generación</h6>
          </div>

          <div class="col-12">
            <div class="form-switch switch-primary d-flex align-items-center gap-3 mb-12">
              <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="auto_generacion_activa"
                  name="auto_generacion_activa"
                  value="1"
                  {{ old('auto_generacion_activa', (int)($dominio->auto_generacion_activa ?? 0)) ? 'checked' : '' }}
              >
              <label class="form-check-label line-height-1 fw-medium text-secondary-light" for="auto_generacion_activa">
                Activar auto-generación
              </label>
            </div>

            <small class="text-secondary-light">
              Si está activo, el daemon revisará y generará contenido automáticamente según la frecuencia.
            </small>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Frecuencia de Generación</label>

            @php
              $freq = old('auto_frecuencia', $dominio->auto_frecuencia ?? 'daily');
            @endphp

            <select class="form-select" name="auto_frecuencia" id="auto_frecuencia">
              <option value="daily"  {{ $freq === 'daily' ? 'selected' : '' }}>Diario</option>
              <option value="hourly" {{ $freq === 'hourly' ? 'selected' : '' }}>Cada hora</option>
              <option value="weekly" {{ $freq === 'weekly' ? 'selected' : '' }}>Semanal</option>
              <option value="custom" {{ $freq === 'custom' ? 'selected' : '' }}>Personalizado (minutos)</option>
            </select>

            @error('auto_frecuencia')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-12 col-md-6" id="contenedor_custom_minutos" style="display:none;">
            <label class="form-label fw-semibold">Cada cuántos minutos</label>
            <input
              type="number"
              class="form-control"
              name="auto_cada_minutos"
              id="auto_cada_minutos"
              value="{{ old('auto_cada_minutos', $dominio->auto_cada_minutos) }}"
              min="1"
              max="10080"
            >
            <small class="text-secondary-light">Solo aplica si eliges “Personalizado”.</small>

            @error('auto_cada_minutos')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Contenido por ejecución</label>
            <input
              type="number"
              class="form-control"
              name="auto_tareas_por_ejecucion"
              value="{{ old('auto_tareas_por_ejecucion', $dominio->auto_tareas_por_ejecucion ?? 2) }}"
              min="1"
              max="50"
            >
            <small class="text-secondary-light">
              Cuánto contenido máximo intentará crear por corrida (sin saltarse licencias).
            </small>

            @error('auto_tareas_por_ejecucion')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Próxima ejecución</label>
            <input type="text" class="form-control" value="{{ $dominio->auto_siguiente_ejecucion ?? 'N/D' }}" disabled>
            <small class="text-secondary-light">Se actualiza cuando el daemon corre o cuando la fuerzas.</small>
          </div>

          <div class="col-12">
            <hr class="my-8">
          </div>

          {{-- =========================
               AUTO WORDPRESS
          ========================== --}}
          <div class="col-12">
            <h6 class="fw-semibold mb-12">Auto WordPress</h6>
          </div>

          <div class="col-12">
            <div class="form-switch switch-primary d-flex align-items-center gap-3 mb-12">
              <input
                  class="form-check-input"
                  type="checkbox"
                  role="switch"
                  id="wp_auto_activo"
                  name="wp_auto_activo"
                  value="1"
                  {{ old('wp_auto_activo', (int)($dominio->wp_auto_activo ?? 0)) ? 'checked' : '' }}
              >
              <label class="form-check-label line-height-1 fw-medium text-secondary-light" for="wp_auto_activo">
                Enviar automáticamente a WordPress
              </label>
            </div>

            <small class="text-secondary-light">
              Si está activo, cada contenido que quede en <b>generado</b> se enviará a WordPress según el modo.
            </small>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Modo de envío</label>

            @php
              $modoWp = old('wp_auto_modo', $dominio->wp_auto_modo ?? 'manual');
            @endphp

            <select class="form-select" name="wp_auto_modo" id="wp_auto_modo">
              <option value="manual"    {{ $modoWp === 'manual' ? 'selected' : '' }}>Manual</option>
              <option value="publicar"  {{ $modoWp === 'publicar' ? 'selected' : '' }}>Publicar</option>
              <option value="programar" {{ $modoWp === 'programar' ? 'selected' : '' }}>Programar</option>
            </select>

            @error('wp_auto_modo')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-12 col-md-6" id="contenedor_wp_minutos" style="display:none;">
            <label class="form-label fw-semibold">Programar cada (minutos)</label>
            <input
              type="number"
              class="form-control"
              name="wp_programar_cada_minutos"
              id="wp_programar_cada_minutos"
              value="{{ old('wp_programar_cada_minutos', $dominio->wp_programar_cada_minutos ?? 60) }}"
              min="1"
              max="10080"
            >
            <small class="text-secondary-light">
              Se programará una publicación cada X minutos (10 min inicial + intervalo).
            </small>

            @error('wp_programar_cada_minutos')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Siguiente programación</label>
            <input type="text" class="form-control" value="{{ $dominio->wp_siguiente_programacion ?? 'N/D' }}" disabled>
            <small class="text-secondary-light">
              Solo aplica si el modo es <b>Programar</b>.
            </small>
          </div>

          {{-- BOTONES --}}
          <div class="col-12 d-flex flex-wrap gap-2 mt-8">
            <button type="submit" class="btn btn-primary">
              Guardar configuración
            </button>
          </div>

        </div>
      </form>

      {{-- FORM SEPARADO: Ejecutar ahora (no anidar) --}}
      <form action="{{ route('dominios.auto_generacion.ejecutar_ahora', $dominio->id_dominio) }}" method="POST" class="mt-12">
        @csrf
        <button type="submit" class="btn btn-warning">
          Ejecutar ahora
        </button>
      </form>

      <hr class="my-24">

      <div class="text-secondary-light">
        <div class="fw-semibold mb-1">Nota:</div>
        <ul class="mb-0">
          <li>Para que funcione, deben estar corriendo: <b>daemon</b> y <b>queue:work</b>.</li>
          <li>Si no hay cupo/licencia, se registrará en logs y no generará hasta que haya cupo.</li>
          <li>Para Auto WordPress, el contenido debe llegar a estatus <b>generado</b>.</li>
        </ul>
      </div>

    </div>
  </div>
</div>
@endsection

@section('scripts')
<script>
  function actualizarCustomMinutos() {
    const freq = document.getElementById('auto_frecuencia')?.value;
    const cont = document.getElementById('contenedor_custom_minutos');
    if (!cont) return;
    cont.style.display = (freq === 'custom') ? 'block' : 'none';
  }

  function actualizarWpMinutos() {
    const modo = document.getElementById('wp_auto_modo')?.value;
    const cont = document.getElementById('contenedor_wp_minutos');
    if (!cont) return;
    cont.style.display = (modo === 'programar') ? 'block' : 'none';
  }

  document.addEventListener('DOMContentLoaded', function () {
    actualizarCustomMinutos();
    actualizarWpMinutos();

    document.getElementById('auto_frecuencia')?.addEventListener('change', actualizarCustomMinutos);
    document.getElementById('wp_auto_modo')?.addEventListener('change', actualizarWpMinutos);
  });
</script>
@endsection
