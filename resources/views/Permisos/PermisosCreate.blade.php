@extends('layouts.master')

@section('titulo', 'Crear Permiso')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Añadir Permisos</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="{!! route('permisos.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Permisos
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Añadir Permisos</li>
  </ul>
</div>
        
        <div class="card h-100 p-0 radius-12">
    <div class="card-body">
      

      

        <form method="POST" action="{{ route('permisos.store') }}">
            @csrf
            <div class="row">
                <!-- Nombre -->
                <div class="col-md-4 mb-3">
                    <label for="name" class="form-label fw-semibold text-primary-light text-sm mb-8">
                        Nombre <span class="text-danger-600">*</span>
                    </label>
                    <input type="text" class="form-control radius-8" id="name" name="name" placeholder="Ingrese el nombre del permiso">
                </div>
              
               
               
               
            </div>

            <!-- Botones -->
            <div class="d-flex align-items-center justify-content-center gap-3 mt-3">
                <button type="button"
                        onclick="window.location.href='{{ route('permisos.index') }}'"
                        class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                    Cancelar
                </button>
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

@endsection


    
  

  