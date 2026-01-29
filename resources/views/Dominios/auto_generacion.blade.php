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
      <a href="{{ route('dominios.show', $dominio->id_dominio ?? $dominio->id_dominio) }}" class="btn btn-outline-secondary btn-sm">
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

      <form action="{{ route('dominios.auto_generacion.actualizar', $dominio->id_dominio) }}" method="POST">
        @csrf

        <div class="row g-16">
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

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Frecuencia  de Generacion</label>
            <select class="form-select" name="auto_frecuencia" id="auto_frecuencia">
              @php $freq = $dominio->auto_frecuencia ?? 'daily'; @endphp
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
            <input type="number" class="form-control" name="auto_cada_minutos" id="auto_cada_minutos"
              value="{{ old('auto_cada_minutos', $dominio->auto_cada_minutos) }}"
              min="1" max="10080">
            <small class="text-secondary-light">Solo aplica si eliges “Personalizado”.</small>
            @error('auto_cada_minutos')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Contenido por ejecución</label>
            <input type="number" class="form-control" name="auto_tareas_por_ejecucion"
              value="{{ old('auto_tareas_por_ejecucion', $dominio->auto_tareas_por_ejecucion ?? 2) }}"
              min="1" max="50">
            <small class="text-secondary-light">
              Cuánto Contenido máximo intentará crear por corrida (sin saltarse licencias).
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

          <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              Guardar configuración
            </button>

            <form action="{{ route('dominios.auto_generacion.ejecutar_ahora', $dominio->id_dominio) }}" method="POST" style="display:inline;">
              @csrf
              <button type="submit" class="btn btn-warning">
                Ejecutar ahora
              </button>
            </form>
          </div>
        </div>
      </form>

      <hr class="my-24">

      <div class="text-secondary-light">
        <div class="fw-semibold mb-1">Nota:</div>
        <ul class="mb-0">
          <li>Para que funcione, deben estar corriendo: <b>daemon</b> y <b>queue:work</b>.</li>
          <li>Si no hay cupo/licencia, se registrará en logs y no generará hasta que haya cupo.</li>
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

  document.addEventListener('DOMContentLoaded', function () {
    actualizarCustomMinutos();
    document.getElementById('auto_frecuencia')?.addEventListener('change', actualizarCustomMinutos);
  });
</script>
@endsection
