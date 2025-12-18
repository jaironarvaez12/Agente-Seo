@extends('layouts.master')

@section('titulo', 'Contenido WordPress')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')

<div class="dashboard-main-body">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Contenido WordPress</h6>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('dominios.index') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dominios
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Contenido WordPress</li>
    </ul>
  </div>

  <div class="card basic-data-table">
    <div class="card-header">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
          <div class="fw-semibold">Dominio: <span class="text-secondary-light">{{ $dominio->nombre ?? '' }}</span></div>
          <div class="text-sm text-secondary-light">{{ $dominio->url }}</div>
        </div>

        <div class="d-flex gap-2">
          {{-- <a href="{{ route('dominios.show', $dominio->id_dominio) }}"
             class="btn btn-outline-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
            <iconify-icon icon="lucide:edit" class="icon text-xl line-height-1"></iconify-icon>
            Editar Dominio
          </a> --}}

          <a href="{{ route('dominios.show', $dominio->id_dominio) }}"
             class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
            <iconify-icon icon="lucide:arrow-left" class="icon text-xl line-height-1"></iconify-icon>
            Volver
          </a>
        </div>
      </div>
    </div>

    <div class="card-body">
        <div class="row g-3 mb-24">
  <div class="col-xxl-6">
    <div class="card border radius-12 h-100">
      <div class="card-body">
        <h6 class="fw-semibold mb-16">Totales Posts</h6>

        <div class="d-flex flex-wrap gap-2">
          <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">
            Publicados: {{ $countPosts['publish'] ?? 0 }}
          </span>

          <span class="bg-warning-focus text-warning-600 border border-warning-main px-24 py-4 radius-4 fw-medium text-sm">
            Borradores: {{ $countPosts['draft'] ?? 0 }}
          </span>

          <span class="bg-info-focus text-info-600 border border-info-main px-24 py-4 radius-4 fw-medium text-sm">
            Programados: {{ $countPosts['future'] ?? 0 }}
          </span>

          <span class="bg-secondary-focus text-secondary-600 border border-secondary-main px-24 py-4 radius-4 fw-medium text-sm">
            Pendientes: {{ $countPosts['pending'] ?? 0 }}
          </span>

          <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">
            Privados: {{ $countPosts['private'] ?? 0 }}
          </span>
        </div>
      </div>
    </div>
  </div>

  <div class="col-xxl-6">
    <div class="card border radius-12 h-100">
      <div class="card-body">
        <h6 class="fw-semibold mb-16">Totales Páginas</h6>

        <div class="d-flex flex-wrap gap-2">
          <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">
            Publicadas: {{ $countPages['publish'] ?? 0 }}
          </span>

          <span class="bg-warning-focus text-warning-600 border border-warning-main px-24 py-4 radius-4 fw-medium text-sm">
            Borradores: {{ $countPages['draft'] ?? 0 }}
          </span>

          <span class="bg-info-focus text-info-600 border border-info-main px-24 py-4 radius-4 fw-medium text-sm">
            Programadas: {{ $countPages['future'] ?? 0 }}
          </span>

          <span class="bg-secondary-focus text-secondary-600 border border-secondary-main px-24 py-4 radius-4 fw-medium text-sm">
            Pendientes: {{ $countPages['pending'] ?? 0 }}
          </span>

          <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">
            Privadas: {{ $countPages['private'] ?? 0 }}
          </span>
        </div>
      </div>
    </div>
  </div>
</div>

      {{-- POSTS --}}
      <div class="mb-24">
        <h6 class="fw-semibold mb-12">Posts</h6>

        <div class="table-responsive scroll-sm">
          <table class="table bordered-table mb-0" id="dataTablePosts" data-page-length='10'>
            <thead>
              <tr>
                <th scope="col">ID</th>
                <th scope="col">Título</th>
                <th scope="col">Estado</th>
                <th scope="col">Fecha</th>
                <th scope="col" class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody>
              @forelse($posts as $p)
                <tr>
                  <td>{{ $p['id'] ?? '' }}</td>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="flex-grow-1">
                        <span class="text-md mb-0 fw-normal text-secondary-light">
                      {!! data_get($p, 'title.rendered', 'Sin título') !!}
                        </span>
                        @if(!empty($p['slug']))
                          <div class="text-xs text-secondary-light">{{ $p['slug'] }}</div>
                        @endif
                      </div>
                    </div>
                  </td>

                  <td>
                    @php $st = $p['status'] ?? ''; @endphp

                    @if($st === 'publish')
                      <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Publicado</span>
                    @elseif($st === 'draft')
                      <span class="bg-warning-focus text-warning-600 border border-warning-main px-24 py-4 radius-4 fw-medium text-sm">Borrador</span>
                    @elseif($st === 'future')
                      <span class="bg-info-focus text-info-600 border border-info-main px-24 py-4 radius-4 fw-medium text-sm">Programado</span>
                    @else
                      <span class="bg-secondary-focus text-secondary-600 border border-secondary-main px-24 py-4 radius-4 fw-medium text-sm">{{ $st }}</span>
                    @endif
                  </td>

                  <td>{{ $p['date'] ?? '' }}</td>

                  <td class="text-center">
                    <div class="d-flex align-items-center gap-10 justify-content-center">
                      @if(!empty($p['link']))
                        <a href="{{ $p['link'] }}" target="_blank" title="Ver"
                           class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle">
                          <iconify-icon icon="lucide:external-link" class="menu-icon"></iconify-icon>
                        </a>
                      @endif
                    </div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="5" class="text-center text-secondary-light">No hay posts para mostrar.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

      {{-- PÁGINAS --}}
      <div>
        <h6 class="fw-semibold mb-12">Páginas</h6>

        <div class="table-responsive scroll-sm">
          <table class="table bordered-table mb-0" id="dataTablePages" data-page-length='10'>
            <thead>
              <tr>
                <th scope="col">ID</th>
                <th scope="col">Título</th>
                <th scope="col">Estado</th>
                <th scope="col">Fecha</th>
                <th scope="col" class="text-center">Acción</th>
              </tr>
            </thead>
            <tbody>
              @forelse($pages as $pg)
                <tr>
                  <td>{{ $pg['id'] ?? '' }}</td>
                  <td>
                    <div class="d-flex align-items-center">
                      <div class="flex-grow-1">
                        <span class="text-md mb-0 fw-normal text-secondary-light">
                       {!! data_get($pg, 'title.rendered', 'Sin título') !!}
                        </span>
                        @if(!empty($pg['slug']))
                          <div class="text-xs text-secondary-light">{{ $pg['slug'] }}</div>
                        @endif
                      </div>
                    </div>
                  </td>

                  <td>
                    @php $st = $pg['status'] ?? ''; @endphp

                    @if($st === 'publish')
                      <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Publicado</span>
                    @elseif($st === 'draft')
                      <span class="bg-warning-focus text-warning-600 border border-warning-main px-24 py-4 radius-4 fw-medium text-sm">Borrador</span>
                    @elseif($st === 'future')
                      <span class="bg-info-focus text-info-600 border border-info-main px-24 py-4 radius-4 fw-medium text-sm">Programado</span>
                    @else
                      <span class="bg-secondary-focus text-secondary-600 border border-secondary-main px-24 py-4 radius-4 fw-medium text-sm">{{ $st }}</span>
                    @endif
                  </td>

                  <td>{{ $pg['date'] ?? '' }}</td>

                  <td class="text-center">
                    <div class="d-flex align-items-center gap-10 justify-content-center">
                      @if(!empty($pg['link']))
                        <a href="{{ $pg['link'] }}" target="_blank" title="Ver"
                           class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle">
                          <iconify-icon icon="lucide:external-link" class="menu-icon"></iconify-icon>
                        </a>
                      @endif
                    </div>
                  </td>
                </tr>
              @empty
                <tr>
                  <td colspan="5" class="text-center text-secondary-light">No hay páginas para mostrar.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('assets/js/lib/datatables.override.js') }}"></script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    new DataTable('#dataTablePosts', {
      columnDefs: [{ targets: -1, orderable: false, searchable: false }],
      order: [[0,'desc']]
    });

    new DataTable('#dataTablePages', {
      columnDefs: [{ targets: -1, orderable: false, searchable: false }],
      order: [[0,'desc']]
    });
  });
</script>
@endsection
