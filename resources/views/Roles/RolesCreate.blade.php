@extends('layouts.master')

@section('titulo', 'Crear Rol')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')

{{-- Estilos que aplican al resultado del plugin (ms-container) --}}
<style>
  .ms-container {
    display: flex !important;
    gap: 28px !important;
    align-items: flex-start !important;
    width: 100% !important;
  }
  .ms-container .ms-selectable,
  .ms-container .ms-selection {
    width: 48% !important;
    background: #fff !important;
    border-radius: 10px !important;
    padding: 10px !important;
  }
  .ms-container .ms-list {
    height: 340px !important;
    overflow: auto !important;
    border-radius: 8px !important;
    border: 1px solid #e5e7eb !important;
  }
  .ms-container .ms-elem-selectable,
  .ms-container .ms-elem-selection {
    padding: 8px 10px !important;
    font-size: 15px !important;
  }
  .ms-container .ms-elem-selectable.ms-hover,
  .ms-container .ms-elem-selection.ms-hover {
    background: #222121 !important;
  }
  .ms-container .ms-selectable input,
  .ms-container .ms-selection input {
    width: 100% !important;
    border-radius: 8px !important;
    margin-bottom: 10px !important;
    padding: 10px 12px !important;
    font-size: 14px !important;
    border: 1px solid #e5e7eb !important;
  }
</style>

<div class="dashboard-main-body">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Añadir roles</h6>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{!! route('roles.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Roles
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Añadir roles</li>
    </ul>
  </div>

  <div class="card h-100 p-0 radius-12">
    <div class="card-body">
      <form method="POST" action="{{ route('roles.store') }}">
        @csrf

        {{-- Nombre (fila 1) --}}
        <div class="row mb-3">
          <div class="col-12 col-md-4">
            <label for="name" class="form-label fw-semibold text-primary-light text-sm mb-8">
              Nombre <span class="text-danger-600">*</span>
            </label>
            <input type="text"
                   class="form-control radius-8 @error('name') is-invalid @enderror"
                   id="name" name="name"
                   placeholder="Ingrese el nombre del Rol"
                   value="{{ old('name') }}">
            @error('name')
              <span class="text-danger text-sm">{{ $message }}</span>
            @enderror
          </div>
        </div>

        {{-- Permisos (fila 2) --}}
        <div class="row mb-4">
          <div class="col-12 col-md-10">
            <label for="public-methods" class="form-label fw-semibold text-primary-light fs-6 mb-3">
              Permisos <span class="text-danger-600">*</span>
            </label>

            <div class="mb-3 d-flex gap-3">
              <button type="button" class="btn btn-primary px-4 py-2" id="select-all">Agregar Todos</button>
              <button type="button" class="btn btn-secondary px-4 py-2" id="deselect-all">Quitar Todos</button>
            </div>

            {{-- ESTE select será transformado por el plugin en doble lista --}}
            <select name="permisos[]" id="public-methods" multiple="multiple">
              @foreach ($permisos as $permiso)
                <option value="{{ $permiso->name }}"
                  @if(in_array($permiso->name, old('permisos', []))) selected @endif>
                  {{ $permiso->name }}
                </option>
              @endforeach
            </select>
          </div>
        </div>

        <!-- Botones -->
        <div class="d-flex align-items-center justify-content-center gap-3 mt-3">
          <a href="{{ route('roles.index') }}" 
             class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
            Cancelar
          </a>
          <button type="submit" class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
            Guardar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection

@section('scripts')
  {{-- CARGA UNA SOLA VEZ, Y EN ESTE ORDEN --}}
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/multi-select/0.9.12/css/multi-select.min.css"/>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/multi-select/0.9.12/js/jquery.multi-select.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.quicksearch/2.4.0/jquery.quicksearch.min.js"></script>

  <script>
    (function () {
      // Si por alguna razón Select2 estaba montado, lo destruimos antes
      if (window.jQuery && $.fn.select2) {
        try { $('#public-methods').select2('destroy'); } catch(e){}
      }

      // Inicializamos cuando el DOM esté listo
      $(function () {
        var $sel = $('#public-methods');

        // Asegurar que el select está visible antes de iniciar (evita problemas en tabs)
        setTimeout(function () {
          $sel.multiSelect({
            selectableHeader:
              "<input type='text' class='form-control' autocomplete='off' placeholder='Buscar disponibles'>",
            selectionHeader:
              "<input type='text' class='form-control' autocomplete='off' placeholder='Buscar seleccionados'>",
            afterInit: function (ms) {
              var that = this,
                  $selectableSearch = that.$selectableUl.prev(),
                  $selectionSearch  = that.$selectionUl.prev(),
                  selectableSearchString = '#' + that.$container.attr('id') + ' .ms-elem-selectable:not(.ms-selected)',
                  selectionSearchString  = '#' + that.$container.attr('id') + ' .ms-elem-selection.ms-selected';

              that.qs1 = $selectableSearch.quicksearch(selectableSearchString).on('keydown', function (e) {
                if (e.which === 40) { that.$selectableUl.focus(); return false; }
              });

              that.qs2 = $selectionSearch.quicksearch(selectionSearchString).on('keydown', function (e) {
                if (e.which === 40) { that.$selectionUl.focus(); return false; }
              });
            },
            afterSelect: function () { this.qs1.cache(); this.qs2.cache(); },
            afterDeselect: function () { this.qs1.cache(); this.qs2.cache(); }
          });
        }, 0);

        // Botones Agregar/Quitar todos
        $('#select-all').on('click', function (e) {
          e.preventDefault();
          $sel.multiSelect('select_all');
        });
        $('#deselect-all').on('click', function (e) {
          e.preventDefault();
          $sel.multiSelect('deselect_all');
        });
      });
    })();
  </script>
@endsection
