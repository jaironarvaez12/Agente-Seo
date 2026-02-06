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

      {{-- FORM PRINCIPAL: Auto-generación + Auto WordPress (UNIFICADO) --}}
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
               AUTO WORDPRESS (INDEPENDIENTE DE AUTO-GENERACIÓN)
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
              Si está activo, el sistema tomará contenido en estado <b>generado</b> y lo publicará/programará según la regla,
              <b>aunque la auto-generación esté apagada</b>.
            </small>
          </div>

          {{-- ACCIÓN (tu campo actual wp_auto_modo) --}}
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Acción</label>

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

          {{-- REGLA WP --}}
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Regla de ejecución</label>
            @php
              $reglaWp = old('wp_regla_tipo', $dominio->wp_regla_tipo ?? 'manual');
            @endphp
            <select class="form-select" name="wp_regla_tipo" id="wp_regla_tipo">
              <option value="manual" {{ $reglaWp==='manual'?'selected':'' }}>Manual</option>
              <option value="cada_n_dias" {{ $reglaWp==='cada_n_dias'?'selected':'' }}>Cada N días</option>
              <option value="cada_x_minutos" {{ $reglaWp==='cada_x_minutos'?'selected':'' }}>Cada X minutos</option>
              <option value="diario" {{ $reglaWp==='diario'?'selected':'' }}>Diario (hora)</option>
              <option value="semanal" {{ $reglaWp==='semanal'?'selected':'' }}>Semanal (días + hora)</option>
            </select>

            @error('wp_regla_tipo')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- CADA N DÍAS --}}
          <div class="col-12 col-md-6" id="contenedor_wp_cada_dias" style="display:none;">
            <label class="form-label fw-semibold">Cada cuántos días</label>
            <input
              type="number"
              class="form-control"
              name="wp_cada_dias"
              value="{{ old('wp_cada_dias', $dominio->wp_cada_dias ?? 2) }}"
              min="1"
              max="365"
            >
            @error('wp_cada_dias')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- CADA X MINUTOS (regla) --}}
          <div class="col-12 col-md-6" id="contenedor_wp_cada_minutos" style="display:none;">
            <label class="form-label fw-semibold">Cada cuántos minutos</label>
            <input
              type="number"
              class="form-control"
              name="wp_cada_minutos"
              value="{{ old('wp_cada_minutos', $dominio->wp_cada_minutos ?? 60) }}"
              min="1"
              max="10080"
            >
            @error('wp_cada_minutos')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- HORA DEL DÍA (diario/semanal) --}}
          <div class="col-12 col-md-6" id="contenedor_wp_hora" style="display:none;">
            <label class="form-label fw-semibold">Hora del día</label>
            <input
              type="time"
              class="form-control"
              name="wp_hora_del_dia"
              value="{{ old('wp_hora_del_dia', $dominio->wp_hora_del_dia ?? '09:00') }}"
            >
            @error('wp_hora_del_dia')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- DÍAS DE SEMANA (semanal) --}}
          <div class="col-12 col-md-6" id="contenedor_wp_dias_semana" style="display:none;">
            <label class="form-label fw-semibold">Días de semana</label>

            @php
              $seleccion = old('wp_dias_semana', $dominio->wp_dias_semana ?? []);
              if (!is_array($seleccion)) $seleccion = [];
              $dias = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
            @endphp

            <div class="d-flex flex-wrap gap-2">
              @foreach($dias as $num => $label)
                <label class="btn btn-outline-secondary btn-sm">
                  <input
                    type="checkbox"
                    name="wp_dias_semana[]"
                    value="{{ $num }}"
                    {{ in_array($num, $seleccion, true) ? 'checked' : '' }}
                  >
                  {{ $label }}
                </label>
              @endforeach
            </div>

            <small class="text-secondary-light">Se guarda como ISO [1..7] (1=Lunes … 7=Domingo).</small>

            @error('wp_dias_semana')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- EXCLUIR FINES DE SEMANA (para cada_n_dias) --}}
          <div class="col-12 col-md-6" id="contenedor_wp_excluir_finde" style="display:none;">
            <label class="form-label fw-semibold d-block">Excluir fines de semana</label>
            <div class="form-check">
              <input
                class="form-check-input"
                type="checkbox"
                id="wp_excluir_fines_semana"
                name="wp_excluir_fines_semana"
                value="1"
                {{ old('wp_excluir_fines_semana', (int)($dominio->wp_excluir_fines_semana ?? 0)) ? 'checked' : '' }}
              >
              <label class="form-check-label" for="wp_excluir_fines_semana">
                No contar sábados ni domingos
              </label>
            </div>
            @error('wp_excluir_fines_semana')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- CONTENIDO POR EJECUCIÓN WP --}}
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Contenido por ejecución (WP)</label>
            <input
              type="number"
              class="form-control"
              name="wp_tareas_por_ejecucion"
              value="{{ old('wp_tareas_por_ejecucion', $dominio->wp_tareas_por_ejecucion ?? 3) }}"
              min="1"
              max="100"
            >
            <small class="text-secondary-light">
              Cuántos contenidos en estado <b>generado</b> tomará por corrida para publicar/programar.
            </small>
            @error('wp_tareas_por_ejecucion')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- PROGRAMAR CADA (min) (solo si acción=programar) --}}
          <div class="col-12 col-md-6" id="contenedor_wp_minutos_programar" style="display:none;">
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
              Si programas, se programará una publicación cada X minutos (offset inicial + intervalo).
            </small>

            @error('wp_programar_cada_minutos')
              <div class="text-danger mt-1">{{ $message }}</div>
            @enderror
          </div>

          {{-- PRÓXIMA EJECUCIÓN WP --}}
          <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Próxima ejecución WordPress</label>
            <input
              type="text"
              class="form-control"
              value="{{ $dominio->wp_siguiente_ejecucion ?? $dominio->wp_siguiente_programacion ?? 'N/D' }}"
              disabled
            >
            <small class="text-secondary-light">
              Controla cuándo el scheduler volverá a intentar enviar contenido a WordPress.
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

      {{-- FORM SEPARADO: Ejecutar ahora (GENERACIÓN) --}}
      <form action="{{ route('dominios.auto_generacion.ejecutar_ahora', $dominio->id_dominio) }}" method="POST" class="mt-12">
        @csrf
        <button type="submit" class="btn btn-warning">
          Ejecutar generación ahora
        </button>
      </form>

      {{-- FORM SEPARADO: Ejecutar ahora (WORDPRESS) --}}
      <form action="{{ route('dominios.wp.ejecutar_ahora', $dominio->id_dominio) }}" method="POST" class="mt-12">
        @csrf
        <button type="submit" class="btn btn-info">
          Enviar a WordPress ahora
        </button>
      </form>

      <hr class="my-24">

      <div class="text-secondary-light">
        <div class="fw-semibold mb-1">Nota:</div>
        <ul class="mb-0">
          <li>Para que funcione, deben estar corriendo: <b>queue:work</b> y el <b>scheduler</b> (schedule:run).</li>
          <li>Auto WordPress toma contenido desde BD en estatus <b>generado</b>, aunque no se use auto-generación.</li>
          <li>Ejemplo: “3 posts cada 2 días sin fines de semana” = Regla: <b>Cada N días</b>, N=2, excluir finde ✅, contenido por ejecución=3, acción=Publicar.</li>
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

  function actualizarWpProgramarMinutos() {
    const modo = document.getElementById('wp_auto_modo')?.value;
    const cont = document.getElementById('contenedor_wp_minutos_programar');
    if (!cont) return;
    cont.style.display = (modo === 'programar') ? 'block' : 'none';
  }

  function actualizarWpReglaCampos() {
    const regla = document.getElementById('wp_regla_tipo')?.value;

    const cadaDias = document.getElementById('contenedor_wp_cada_dias');
    const cadaMin = document.getElementById('contenedor_wp_cada_minutos');
    const hora = document.getElementById('contenedor_wp_hora');
    const dias = document.getElementById('contenedor_wp_dias_semana');
    const finde = document.getElementById('contenedor_wp_excluir_finde');

    if (cadaDias) cadaDias.style.display = (regla === 'cada_n_dias') ? 'block' : 'none';
    if (finde) finde.style.display = (regla === 'cada_n_dias') ? 'block' : 'none';

    if (cadaMin) cadaMin.style.display = (regla === 'cada_x_minutos') ? 'block' : 'none';

    const showHora = (regla === 'diario' || regla === 'semanal');
    if (hora) hora.style.display = showHora ? 'block' : 'none';

    if (dias) dias.style.display = (regla === 'semanal') ? 'block' : 'none';
  }

  document.addEventListener('DOMContentLoaded', function () {
    actualizarCustomMinutos();
    actualizarWpProgramarMinutos();
    actualizarWpReglaCampos();

    document.getElementById('auto_frecuencia')?.addEventListener('change', actualizarCustomMinutos);
    document.getElementById('wp_auto_modo')?.addEventListener('change', actualizarWpProgramarMinutos);
    document.getElementById('wp_regla_tipo')?.addEventListener('change', actualizarWpReglaCampos);
  });
</script>
@endsection
