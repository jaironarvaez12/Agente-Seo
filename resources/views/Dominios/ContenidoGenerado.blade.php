@extends('layouts.master')

@section('titulo', 'Contenido WordPress')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Contenido generado - Dominio {{ $dominio->url }}</h6>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ url('dominios') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Dominios
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Contenido WordPress</li>
    </ul>
  </div>

  {{-- Filtros --}}
  <div class="card mb-24">
    <div class="card-header">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="fw-semibold">Filtros</div>
        <a href="{{ url('dominios') }}"
           class="btn btn-outline-secondary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
          <iconify-icon icon="ic:round-arrow-back" class="icon text-xl line-height-1"></iconify-icon>
          Volver
        </a>
      </div>
    </div>

    <div class="card-body">
      <form method="GET">
        <div class="row g-2">
          <div class="col-md-3">
            <select name="tipo" class="form-select">
              <option value="">Tipo (todos)</option>
              <option value="post" {{ ($tipo==='post')?'selected':'' }}>post</option>
              <option value="page" {{ ($tipo==='page')?'selected':'' }}>page</option>
            </select>
          </div>

          <div class="col-md-3">
            <select name="estatus" class="form-select">
              <option value="">Estatus (todos)</option>
              <option value="pendiente" {{ ($estatus==='pendiente')?'selected':'' }}>pendiente</option>
              <option value="en_proceso" {{ ($estatus==='en_proceso')?'selected':'' }}>en_proceso</option>
              <option value="generado" {{ ($estatus==='generado')?'selected':'' }}>generado</option>
              <option value="publicado" {{ ($estatus==='publicado')?'selected':'' }}>publicado</option>
              <option value="error" {{ ($estatus==='error')?'selected':'' }}>error</option>
            </select>
          </div>

          <div class="col-md-3">
            <button class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 w-100 d-flex align-items-center justify-content-center gap-2">
              <iconify-icon icon="mdi:filter" class="icon text-xl line-height-1"></iconify-icon>
              Filtrar
            </button>
          </div>

          <div class="col-md-3">
            <a href="{{ route('dominios.contenido_generado', $IdDominio) }}"
               class="btn btn-outline-secondary text-sm btn-sm px-12 py-12 radius-8 w-100 d-flex align-items-center justify-content-center gap-2">
              <iconify-icon icon="ic:round-refresh" class="icon text-xl line-height-1"></iconify-icon>
              Limpiar
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>

  {{-- Tabla --}}
  <div class="card basic-data-table">
    <div class="card-body">
      <div class="table-responsive scroll-sm">
        <table class="table bordered-table mb-0" id="dataTable" data-page-length="10">
          <thead>
            <tr>
              <th>ID</th>
              <th>Tipo</th>
              <th>Estatus</th>
              <th>Título</th>
              <th>Keyword</th>
              <th>Fecha</th>
              <th class="text-center">Acción</th>
            </tr>
          </thead>
          <tbody>
            @foreach($items as $it)
              <tr>
                <td>{{ $it->id_dominio_contenido_detalle }}</td>
                <td><span class="fw-medium text-secondary-light">{{ $it->tipo }}</span></td>
                <td>
                  @php
                    $badgeClass = match($it->estatus) {
                      'publicado' => 'bg-success-focus text-success-600 border border-success-main',
                      'generado' => 'bg-info-focus text-info-600 border border-info-main',
                      'en_proceso' => 'bg-warning-focus text-warning-600 border border-warning-main',
                      'pendiente' => 'bg-secondary-focus text-secondary-600 border border-secondary-main',
                      'error' => 'bg-danger-focus text-danger-600 border border-danger-main',
                      default => 'bg-secondary-focus text-secondary-600 border border-secondary-main'
                    };
                  @endphp
                  <span class="{{ $badgeClass }} px-24 py-4 radius-4 fw-medium text-sm">
                    {{ $it->estatus }}
                  </span>
                </td>
                <td>{{ $it->title ?: '(Sin título)' }}</td>
                <td>{{ $it->keyword }}</td>
                <td>{{ $it->created_at }}</td>
                <td class="text-center">
                  <div class="d-flex align-items-center gap-10 justify-content-center">
                    <button type="button"
                      class="bg-info-focus text-info-600 bg-hover-info-200 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle border-0 btn-view-html"
                      title="Ver HTML"
                      data-title="{{ e($it->title ?: '(Sin título)') }}"
                      data-html="{{ e($it->contenido_html) }}"
                      data-error="{{ e($it->error ?? '') }}">
                      <iconify-icon icon="majesticons:eye-line" class="menu-icon"></iconify-icon>
                    </button>
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

{{-- Modal Ver HTML --}}
<div class="modal fade" id="modalHtml" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-semibold" id="modalHtmlTitle">HTML</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="modalHtmlError" class="d-none mb-12 p-12 radius-8 bg-danger-focus border border-danger-main text-danger-600">
          <span class="fw-semibold">Error:</span> <span id="modalHtmlErrorText"></span>
        </div>

        <div class="p-16 radius-8 border mb-16">
          <div class="fw-semibold mb-8">Vista render</div>
          <div id="modalHtmlRender"></div>
        </div>

        <div>
          <label class="form-label fw-semibold">HTML crudo</label>
          <textarea class="form-control" id="modalHtmlRaw" rows="10" readonly></textarea>
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
  new DataTable('#dataTable', {
    columnDefs: [{ targets: -1, orderable: false, searchable: false }],
    order: [[0,'desc']]
  });

  const modalEl = document.getElementById('modalHtml');
  const modal = new bootstrap.Modal(modalEl);

  const titleEl = document.getElementById('modalHtmlTitle');
  const renderEl = document.getElementById('modalHtmlRender');
  const rawEl = document.getElementById('modalHtmlRaw');

  const errorBox = document.getElementById('modalHtmlError');
  const errorText = document.getElementById('modalHtmlErrorText');

  document.querySelectorAll('.btn-view-html').forEach(btn => {
    btn.addEventListener('click', () => {
      const title = btn.dataset.title || 'HTML';
      const html = btn.dataset.html || '';
      const error = btn.dataset.error || '';

      titleEl.textContent = title;

      // ojo: dataset trae HTML escapado con e()
      // Para renderizar, lo interpretamos:
      const decoded = new DOMParser().parseFromString(html, 'text/html').documentElement.textContent;

      renderEl.innerHTML = decoded;
      rawEl.value = decoded;

      if (error.trim().length) {
        errorBox.classList.remove('d-none');
        errorText.textContent = error;
      } else {
        errorBox.classList.add('d-none');
        errorText.textContent = '';
      }

      modal.show();
    });
  });
});
</script>
@endsection