@extends('layouts.master')

@section('titulo', 'Contenido Publicado')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion') 
@include('Backlinks.ModalBacklinks') 


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Lista de Contenido</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="#" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Contenido
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Contenido</li>
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
                          <th scope="col">Titulo</th>
                          <th scope="col">Fecha</th>
                          <th scope="col">Backlinks Publicados</th>

                          <th scope="col" class="text-center">Accion</th>
                    </tr>
                </thead>
                <tbody>
                         @if($contenidos!=null)
                             @foreach ($contenidos as $contenido)
                           
                            <tr>
                                <td>{{ $loop->iteration }}</td>
                                <td>
                                    {!!wordwrap($contenido->title, 40, "<br>")!!}
                                </td>
                                <td>{{ $contenido->fecha_publicado }}</td>
                                <td>
                                    @if($contenido->estatus_backlinks =='listo')
                                        <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Publicado</span> 
                                    @elseif($contenido->estatus_backlinks =='parcial')
                                        <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Parcial</span> 
                                    @elseif($contenido->estatus_backlinks =='en_proceso')
                                        <span class="bg-warning-focus text-warning-600 border border-warning-main px-24 py-4 radius-4 fw-medium text-sm">En Proceso</span> 
                                    @else
                                       <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">No generado</span>
                                    @endif
                               
                              
                                {{-- <td>   @if($perfil->estatus=='SI')
                                         <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Activo</span> 
                                      @else
                                        <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">Inactivo</span> 
                                      @endif
                                      --}}
                                </td> 
                                <td class="text-center"> 
                                    
                                    <div class="d-flex align-items-center gap-10 justify-content-center">


                                      @php
                                        $runs = $contenido->backlinksRuns ?? collect();
                                      @endphp




                                        <form method="POST" action="{{ route('dominios.contenido.generar_backlinks', [$contenido->id_dominio, $contenido->id_dominio_contenido_detalle]) }}"
                                            class="m-0">
                                            @csrf
                                            <button type="submit"
                                                class="bg-success-focus text-success-600 bg-hover-primary-200 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle border-0"
                                                title="Generar backlinks"
                                                {{ ($contenido->estatus !== 'publicado' || empty($contenido->wp_link) || ($contenido->estatus_backlinks ?? '') === 'en_proceso') ? 'disabled' : '' }}>
                                                <iconify-icon icon="ic:baseline-plus"></iconify-icon>
                                            </button>
                                            
                            
                                        </form>
                                        {{-- Ver backlinks (solo abre modal, no form) --}}
                                       <button type="button"
                                        class="bg-info-focus text-info-600 bg-hover-info-200 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle border-0 btn-view-backlinks"
                                        title="Ver backlinks"
                                        data-title="{{ e($contenido->title ?: '(Sin título)') }}"
                                        data-runs='@json($runs)'
                                        {{ $runs->isEmpty() ? 'disabled' : '' }}>
                                        <iconify-icon icon="mdi:link-variant" class="menu-icon"></iconify-icon>
                                        </button>
                                        <!-- Editar -->
                                       
                                        {{-- <a href="{{ route('dominios.edit', $perfil->id_dominio) }}" 
                                            title="Editar"
                                            class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="lucide:edit" class="menu-icon"></iconify-icon>
                                        </a>
                                        <a href="{{ route('dominios.show', $perfil->id_dominio) }}" 
                                            title="Contenido Publicado"
                                            class="bg-info-focus text-info-600 bg-hover-info-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="majesticons:eye-line" class="menu-icon"></iconify-icon>
                                        </a>
                                        <a href="{{ route('dominios.show', $perfil->id_dominio) }}" 
                                            title="Backlinks"
                                            class="bg-info-focus text-info-600 bg-hover-info-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="majesticons:eye-line" class="menu-icon"></iconify-icon>
                                        </a> --}}
                                        {{-- @if($perfil->estatus == 'SI')
                                          
                                            <form action="{{ route('dominios.licencia.desactivar', $perfil->id_dominio) }}" method="POST" style="display:inline;">
                                                @csrf
                                                <button type="submit"
                                                    title="Desactivar"
                                                    class="bg-danger-focus text-danger-600 bg-hover-danger-200 fw-medium w-40-px h-40-px 
                                                        d-flex justify-content-center align-items-center rounded-circle border-0">
                                                    <iconify-icon icon="material-symbols:pause-circle-outline" class="menu-icon"></iconify-icon>
                                                </button>
                                            </form>
                                        @else
                                            
                                            <form action="{{ route('dominios.licencia.activar', $perfil->id_dominio) }}" method="POST" style="display:inline;">
                                                @csrf
                                                <button type="submit"
                                                    title="Activar"
                                                    class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                        d-flex justify-content-center align-items-center rounded-circle border-0">
                                                    <iconify-icon icon="material-symbols:play-circle-outline" class="menu-icon"></iconify-icon>
                                                </button>
                                            </form>
                                        @endif       --}}
                                       
                                       

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


<script>
document.addEventListener('DOMContentLoaded', () => {
  const backlinksModalEl = document.getElementById('modalBacklinks');
  const backlinksModal = new bootstrap.Modal(backlinksModalEl);

  const blTitle = document.getElementById('modalBacklinksTitle');
  const blRuns = document.getElementById('blRuns');

  const esc = (s) => String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;');

  document.querySelectorAll('.btn-view-backlinks').forEach(btn => {
    btn.addEventListener('click', () => {
      const title = btn.dataset.title || 'Backlinks';
      const runs = btn.dataset.runs ? JSON.parse(btn.dataset.runs) : [];

      blTitle.textContent = `Backlinks - ${title}`;

      if (!runs.length) {
        blRuns.innerHTML = `<div class="text-secondary-light">Sin historial.</div>`;
        backlinksModal.show();
        return;
      }

      blRuns.innerHTML = runs.map((run, idx) => {
        const res = run.respuesta || null;
        const r0 = res?.data?.results?.[0] || null;

        const sum = r0?.summary || null;
        const pubs = r0?.published_urls || [];
        const fails = r0?.failed_platforms || [];

        const fecha = run.created_at ? esc(run.created_at) : '';
        const rawStatus = (run.estatus || '').toLowerCase();
        const statusLabel =
        rawStatus === 'listo' ? 'Publicado' :
        rawStatus === 'parcial' ? 'Parcial' :
        rawStatus === 'error' ? 'Error' : rawStatus;

        const badge =
        rawStatus === 'error' ? 'bg-danger' :
        rawStatus === 'parcial' ? 'bg-warning' :
        rawStatus === 'listo' ? 'bg-success' :
        'bg-secondary';

        const resumenHtml = sum ? `
          <div class="text-sm">
            Total: <strong>${sum.total_platforms ?? '-'}</strong> |
            Publicados: <strong>${sum.published ?? 0}</strong> |
            Fallidos: <strong>${sum.failed ?? 0}</strong>
          </div>` : `<div class="text-sm text-secondary-light">Sin resumen.</div>`;

        const pubsHtml = pubs.length ? pubs.map(p => `
          <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
            <div>
              <div class="fw-semibold">${esc(p.platform)}</div>
              <div class="text-sm">
                <a href="${p.url}" target="_blank" rel="noopener">${esc(p.url)}</a>
              </div>
            </div>
           <span class="badge bg-success">Publicado</span>
          </div>
        `).join('') : `<div class="text-sm text-secondary-light">No hay publicados.</div>`;

        const failsHtml = fails.length ? fails.map(f => `
          <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between">
              <div class="fw-semibold">${esc(f.platform)}</div>
              <span class="badge bg-danger">Fallido</span>
            </div>
            <div class="text-sm mt-1">${esc(f.error)}</div>
          </div>
        `).join('') : `<div class="text-sm text-secondary-light">Sin fallos.</div>`;

        const errHtml = run.error ? `<div class="text-sm text-danger mt-2"><strong>Error:</strong> ${esc(run.error)}</div>` : '';

        return `
          <div class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center">
              <div class="fw-semibold">Corrida #${idx + 1}
                <span class="text-secondary-light text-sm ms-2">${fecha}</span>
              </div>
              <span class="badge ${badge}">${esc(statusLabel)}</span>
            </div>

            <div class="mt-2">${resumenHtml}</div>

            <hr>
            <div class="fw-semibold mb-2">Publicados</div>
            ${pubsHtml}

            <hr>
            <div class="fw-semibold mb-2">Fallidos</div>
            ${failsHtml}
            ${errHtml}
          </div>
        `;
      }).join('');

      backlinksModal.show();
    });
  });
});
</script>
@endsection

    
  

  