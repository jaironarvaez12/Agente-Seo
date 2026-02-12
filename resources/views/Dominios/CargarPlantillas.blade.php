@extends('layouts.master')

@section('titulo', 'Cargar Plantillas')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion') 


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Cargar Plantillas</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="index.html" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Dominios
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Dominios</li>
  </ul>
</div>

          <div class="card basic-data-table">
           
           <div class="card-header text-end">
               <div class="d-flex justify-content-end">
                    <div style="width: auto;">
                          
                    </div>
                </div>
            </div>
            <div class="card-body">
              <form action="{{ route('guardarplantillas') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                    <label class="form-label">Nombre</label>
                    <input class="form-control" type="text" name="nombre" required>
                    </div>

                    <div class="mb-3">
                    <label class="form-label">Plantilla Elementor (.json)</label>
                    <input class="form-control" type="file" name="archivo" accept=".json,application/json" required>
                    </div>

                    <button class="btn btn-primary">Subir y tokenizar</button>
                </form>
            </div>
        </div>
    </div>

@endsection

@section('scripts')






@endsection

    
  

  