@extends('layouts.master')

@section('titulo', 'Backlinks Generados')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion') 


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Lista de Backlinks</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="#" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Backlinks
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Backlinks</li>
  </ul>
</div>

          <div class="card basic-data-table">
           <div class="card-header text-end">
              
            </div>
            <div class="card-body">
              <div class="table-responsive scroll-sm">
                <table class="table bordered-table mb-0" id="dataTable" data-page-length='10'>
                    <thead>
                        <tr>
                            <th scope="col">Id</th>
                            <th scope="col">Plataforma</th>
                            <th scope="col">Backlink</th>
                            <th scope="col">Estatus</th>
                            <th scope="col">Fecha</th>
                        
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($backlinks as $backlink)
                            <tr>
                            <td>{{ $loop->iteration }}</td>

                            <td>{{ $backlink->plataforma }}</td>

                            <td>
                                @if(!empty($backlink->url) && $backlink->url !== '—')
                                <a href="{{ $backlink->url }}" target="_blank" rel="noopener" title="{{ $backlink->url }}">
                                    <span style="display:inline-block; line-height:1.1;">
                                    {!! wordwrap(e($backlink->url), 50, "<br>\n", true) !!}
                                    </span>
                                </a>
                                @else
                                —
                                @if(!empty($backlink->error))
                                    <div class="text-danger text-sm mt-1">
                                   
                                        {!! wordwrap(e($backlink->error ), 50, "<br>\n", true) !!}
                                    </div>
                                @endif
                                @endif
                            </td>

                            <td data-order="{{ ($backlink->estatus === 'published') ? 0 : 1 }}">
                                @if(($backlink->estatus ?? '') === 'published')
                                <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Publicado</span>
                                @else
                                <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">Fallido</span>
                                @endif
                            </td>

                                @php $ts = !empty($backlink->fecha) ? strtotime($backlink->fecha) : 0; @endphp
<td data-order="{{ $ts }}">
  {{ $ts ? date('d-m-Y g:i:s A', $ts) : '—' }}
</td>
                            </tr>
                        @empty
                            <tr>
                            <td colspan="5" class="text-center text-secondary-light py-3">No hay backlinks.</td>
                            </tr>
                        @endforelse
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
   
       order: [
     
         [3, 'asc'],  // Estatus: 0=Publicado primero
    [4, 'desc']  // Fecha: más nuevo primero dentro del grupo

       ]
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

 
@endsection

    
  