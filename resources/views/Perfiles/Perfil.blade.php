@extends('layouts.master')

@section('titulo', 'Perfiles')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
{{-- @include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion') --}}


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Lista de Perfiles</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="index.html" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Perfiles
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Perfiles</li>
  </ul>
</div>

          <div class="card basic-data-table">
           <div class="card-header text-end">
               <div class="d-flex justify-content-end">
                    <div style="width: auto;">
                          <a href="{!! route('perfiles.create') !!}" class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2"> 
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
                          <th scope="col">Nombre Perfil</th>
                          <th scope="col">Nombre Facebook</th>
                          <th scope="col">Nombre Instagram</th>
                          <th scope="col" class="text-center">Accion</th>
                    </tr>
                </thead>
                <tbody>
                         @if($perfiles!=null)
                             @foreach ($perfiles as $perfil)
                            <tr>
                                <td>{{ $perfil->id }}</td>
                                <td>  
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1">
                                            <span class="text-md mb-0 fw-normal text-secondary-light">{{ $perfil->nombre }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="text-md mb-0 fw-normal text-secondary-light">{{ $perfil->fb_page_name }}</span></td>
                                <td><span class="text-md mb-0 fw-normal text-secondary-light">{{ $perfil->fb_page_name }}</span></td>
                                <td class="text-center"> 
                                    <div class="d-flex align-items-center gap-10 justify-content-center">
                                        
                                        <!-- Editar -->
                                        {{-- <a href="{{ route('perfiles.edit', $perfil->id) }}" 
                                            title="Editar perfil"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:edit" class="menu-icon"></iconify-icon>
                                        </a> --}}
                                        <a href="{{ route('perfilprogramar', $perfil->id) }}" 
                                            title="PROGRAMAR"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:edit" class="menu-icon"></iconify-icon>
                                        </a>

                                        <!-- Ver detalles -->
                                        <a href="{{ route('perfilpublicar', $perfil->id) }}" 
                                            title="Crear nueva publicación"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:plus" class="menu-icon"></iconify-icon>
                                        </a>
                                        <a href="{{ route('perfilesface', $perfil->id) }}" 
                                            title="Ver Publicaciones en Facebook"
                                            class="fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle text-white
                                                transform transition-transform hover:scale-110"
                                            style="background: linear-gradient(135deg, #1877F2 0%, #3b5998 100%);
                                                transition: transform 0.2s ease;">
                                            <iconify-icon icon="mdi:facebook" class="menu-icon" style="font-size: 20px;"></iconify-icon>
                                        </a>
                                       <a href="{{ route('perfilesinsta', $perfil->id) }}" 
                                            title="Ver Publicaciones en Instagram"
                                            class="fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle text-white"
                                            style="background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%);
                                                transition: transform 0.2s ease;">
                                            <iconify-icon icon="mdi:instagram" class="menu-icon" style="font-size: 20px;"></iconify-icon>
                                        </a>

                                        {{-- <!-- Eliminar -->
                                        <a href="{{ route('perfiles.destroy', $perfil->id) }}" 
                                            title="Eliminar perfil"
                                            class="bg-danger-focus text-danger-600 bg-hover-danger-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:trash-2" class="menu-icon"></iconify-icon>
                                        </a> --}}

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

    
  

  