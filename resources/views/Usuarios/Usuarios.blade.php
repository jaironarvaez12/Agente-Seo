@extends('layouts.master')

@section('titulo', 'Inicio')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Lista de Usuarios</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="index.html" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Usuarios
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Usuarios</li>
  </ul>
</div>

          <div class="card basic-data-table">
            <div class="card-header text-end">
              <div class="d-flex justify-content-end align-items-center gap-10 flex-wrap">
                  <a href="{!! route('usuarios.create') !!}"
                    class="btn btn-primary text-sm btn-sm px-16 py-10 radius-8 d-flex align-items-center gap-2">
                      <iconify-icon icon="ic:baseline-plus" class="icon text-lg line-height-1"></iconify-icon>
                      Nuevo usuario
                  </a>

                  <a href="{{ route('usuarios.dependientes.crear') }}"
                    class="btn btn-outline-primary text-sm btn-sm px-16 py-10 radius-8 d-flex align-items-center gap-2">
                      <iconify-icon icon="ic:baseline-person-add" class="icon text-lg line-height-1"></iconify-icon>
                      Crear dependiente
                  </a>
              </div>
            </div>
            <div class="card-body">
              <div class="table-responsive scroll-sm">
                <table class="table bordered-table mb-0" id="dataTable" data-page-length='10'>
                <thead>
                     <tr>
                        <th scope="col">Id</th>
                          <th scope="col">Nombre</th>
                          <th scope="col">Email</th>
                          <th scope="col" class="text-center">Estatus</th>
                          <th scope="col" class="text-center">Accion</th>
                    </tr>
                </thead>
                <tbody>
                         @if($usuarios!=null)
                             @foreach ($usuarios as $usuario)
                            <tr>
                                <td>{{ $usuario->id }}</td>
                                <td>  
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1">
                                            <span class="text-md mb-0 fw-normal text-secondary-light">{{ $usuario->name }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="text-md mb-0 fw-normal text-secondary-light">{{ $usuario->email }}</span></td>
                                <td>
                                      {{-- @if($usuario->activo=='SI') --}}
                                         <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Activo</span> 
                                      {{-- @else
                                      <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">Inactivo</span> 
                                      @endif --}}
                                    </td>
                                <td class="text-center"> 
                                    <div class="d-flex align-items-center gap-10 justify-content-center">
                                       
                                        <a href="{{ route('usuarios.edit', $usuario->id) }}" 
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                    d-flex justify-content-center align-items-center rounded-circle">
                                                <iconify-icon icon="lucide:edit" class="menu-icon"></iconify-icon>
                                        </a>
                                        {{-- @if($usuario->activo=='SI')
                                          <a href="#!"
                                              class="remove-item-btn bg-danger-focus bg-hover-danger-200 text-danger-600 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle"
                                              data-bs-toggle="modal" data-bs-target="#modal-eliminar"
                                              data-id="{{  $usuario->id}}"
                                              data-nombre="{{  $usuario->name}}">
                                              <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                                          </a>
                                        @endif
                                        @if($usuario->activo=='NO')
                            
                                           <a href="#!"
                                              class="remove-item-btn bg-info-focus bg-hover-info-200 text-info-600 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle"
                                              data-bs-toggle="modal" data-bs-target="#modal-activar"
                                              data-id="{{ $usuario->id }}"
                                              data-nombre="{{  $usuario->name  }}">
                                               <iconify-icon icon="material-symbols:check-circle-outline" class="menu-icon"></iconify-icon>
                                          </a>
                                        @endif --}}
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



<script>
    $('#modal-eliminar').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget) // Button que llama al modal
        var id = button.data('id')          // Extrae data-id
        var nombre = button.data('nombre')  // Extrae data-nombre

        action = $('#formdelete').attr('data-action').slice(0, -1); // quita el '0'
        action += id; // agrega el id real

        $('#formdelete').attr('action', action); // setea la ruta final

        var modal = $(this)
        modal.find('.modal-body h5').text('Desea Anular el Usuario ' + nombre + ' ?')
    })
</script>

<script>
    $('#modal-activar').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget) // Button que llama al modal
        var id = button.data('id')          // Extrae data-id
        var nombre = button.data('nombre')  // Extrae data-nombre

        action = $('#formactivar').attr('data-action').slice(0, -1); // quita el '0'
        action += id; // agrega el id real

        $('#formactivar').attr('action', action); // setea la ruta final

        var modal = $(this)
        modal.find('.modal-body h5').text('Desea Activar el Usuario ' + nombre + ' ?')
    })
</script>
@endsection

    
  

  