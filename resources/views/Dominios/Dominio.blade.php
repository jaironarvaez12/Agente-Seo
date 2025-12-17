@extends('layouts.master')

@section('titulo', 'Dominios')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion') 


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Lista de Dominios</h6>
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
                          <a href="{!! route('dominios.create') !!}" class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2"> 
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
                          <th scope="col">Nombre Dominio</th>
                          <th scope="col">URL</th>
                          <th scope="col">Estatus</th>
                          <th scope="col" class="text-center">Accion</th>
                    </tr>
                </thead>
                <tbody>
                         @if($dominios!=null)
                             @foreach ($dominios as $perfil)
                            <tr>
                                <td>{{ $perfil->id_dominio }}</td>
                                <td>  
                                    <div class="d-flex align-items-center">
                                        <div class="flex-grow-1">
                                            <span class="text-md mb-0 fw-normal text-secondary-light">{{ $perfil->nombre }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $perfil->url }}</td>
                                <td>   @if($perfil->estatus=='SI')
                                         <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Activo</span> 
                                      @else
                                        <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">Inactivo</span> 
                                      @endif
                                    </td>
                                <td class="text-center"> 
                                    <div class="d-flex align-items-center gap-10 justify-content-center">
                                        
                                        <!-- Editar -->
                                       
                                        {{-- <a href="{{ route('dominios.edit', $perfil->id_dominio) }}" 
                                            title="Editar"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:edit" class="menu-icon"></iconify-icon>
                                        </a> --}}
                                        <a href="{{ route('dominios.show', $perfil->id_dominio) }}" 
                                            title="Editar"
                                            class="bg-info-focus text-info-600 bg-hover-info-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="majesticons:eye-line" class="menu-icon"></iconify-icon>
                                        </a>
                                        {{-- <a href="{{ route('dominioscrearcontenido', $perfil->id_dominio) }}" 
                                            title="Crear"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:plus" class="menu-icon"></iconify-icon>
                                        </a>
                                        <a href="{{ route('dominios.wp', $perfil->id_dominio) }}" 
                                            title="CONTENIDO"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:plus" class="menu-icon"></iconify-icon>
                                        </a>
                                     
                                        <form action="{{ route('generador', $perfil->id_dominio) }}" method="POST" style="display:inline;">
                                        @csrf
                                        <button type="submit"
                                            title="GENERADOR"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle border-0">
                                            <iconify-icon icon="lucide:plus" class="menu-icon"></iconify-icon>
                                        </button>
                                        </form>

                                        <a href="{{ route('dominios.contenido_generado', $perfil->id_dominio) }}"
                                            class="btn btn-outline-info btn-sm">
                                            Ver contenido generado
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

    
  

  