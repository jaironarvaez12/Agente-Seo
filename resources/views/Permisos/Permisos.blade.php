@extends('layouts.master')

@section('titulo', 'Inicio')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Lista de Permisos</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="{!! route('permisos.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Permisos
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Permisos</li>
  </ul>
</div>

       
       
        <div class="card basic-data-table">
           <div class="card-header text-end">
               <div class="d-flex justify-content-end">
                    <div style="width: auto;">
                        <a href="{!! route('permisos.create') !!}" class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2"> 
                            <iconify-icon icon="ic:baseline-plus" class="icon text-xl line-height-1"></iconify-icon>
                            Nuevo
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive scroll-sm">
                <table class="table bordered-table mb-0" id="dataTable" data-page-length='10'>
                <thead>
                     <tr>
                          <th scope="col">Id</th>
                          <th scope="col">Permisos</th>
                       
                      
                          <th scope="col" class="text-center">Accion</th>
                    </tr>
                </thead>
                <tbody>
                         @if($permisos!=null)
                            @foreach ($permisos as $permiso)
                                <tr>
                                    <td>{{ $permiso->id }}</td>
                                    <td>  
                                        <div class="d-flex align-items-center">
                                            <div class="flex-grow-1">
                                                <span class="text-md mb-0 fw-normal text-secondary-light">{{ $permiso->name }}</span>
                                            </div>
                                        </div>
                                    </td>
                                   
                                   
                                    
                                    <td class="text-center"> 
                                        <div class="d-flex align-items-center gap-10 justify-content-center">
                                          
                                            <button type="button" class="remove-item-btn bg-danger-focus bg-hover-danger-200 text-danger-600 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle"> 
                                                <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
<script src="{{ asset('assets/js/lib/datatables.override.js') }}"></script>


<script>
  document.addEventListener('DOMContentLoaded', () => {
    new DataTable('#dataTable', {
      columnDefs: [{ targets: -1, orderable: false, searchable: false }],
      order: [[0,'asc']]
    });
  });
</script>
@endsection

    
  

  