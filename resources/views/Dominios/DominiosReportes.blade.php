@extends('layouts.master')

@section('titulo', 'Dominios')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion') 


<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Reportes de Seo  — {{ $dominio->nombre }}</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="{!! route('dominios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Dominios
      </a>
    </li>
    <li>-</li>
     <li class="fw-medium">
      <a href="{{ route('dominios.show', $dominio->id_dominio) }}" class="d-flex align-items-center gap-1 hover-text-primary">
        {{ $dominio->nombre }}
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Lista de Reportes</li>
  </ul>
</div>

          <div class="card basic-data-table">
           <div class="card-header text-end">
               <div class="d-flex justify-content-end">
                  
                </div>
            </div>
            <div class="card-body">
              <div class="table-responsive scroll-sm">
                <table class="table bordered-table mb-0" id="dataTable" data-page-length='10'>
                <thead>
                     <tr>
                        <th scope="col">Id</th>
                          <th scope="col">Periodo Inicio</th>
                          <th scope="col">Periodo Fin</th>
                          <th scope="col">Estatus</th>
                          <th scope="col" class="text-center">Accion</th>
                    </tr>
                </thead>
                <tbody>
                         @if($reportes!=null)
                             @foreach ($reportes  as $reporte )
                            <tr>
                                <td>{{ $reporte->id }}</td>
                                <td  style="text-align:left;">  
                                 
                                      {{date('d-m-Y', strtotime($reporte->period_start))}}
                                </td>
                                <td  style="text-align:left;">
                                    {{date('d-m-Y', strtotime($reporte->period_end ))}}
                                </td>
                                <td> 
                                    @if($reporte->status=='ok')
                                        <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">GENERADO</span> 
                                    @elseif($reporte->status=='error')
                                        <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">ERROR</span> 
                                    @elseif($reporte->status=='generando')
                                        <span class="bg-info-focus text-info-600 border border-info-main px-24 py-4 radius-4 fw-medium text-sm">GENERANDO</span> 
                                    @else  
                                    @endif
                                      
                                    </td>
                                <td class="text-center"> 
                                    <div class="d-flex align-items-center gap-10 justify-content-center">
                                        
                                   
                                       <a href="{{ route('dominiosreportefecha', [$dominio->id_dominio, $reporte->id]) }}"
                                            title="Ver"
                                            class="bg-info-focus text-info-600 bg-hover-info-200 fw-medium w-40-px h-40-px 
                                                d-flex justify-content-center align-items-center rounded-circle">
                                            <iconify-icon icon="majesticons:eye-line" class="menu-icon"></iconify-icon>
                                        </a>
                                        @if($reporte->status=='ok')
                                            <a href="{{ route('dominiosreportepdf', ['id_dominio' => $dominio->id_dominio, 'id_reporte' => $reporte->id]) }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                title="PDF"
                                                class="bg-warning-focus text-warning-600 bg-hover-warning-200 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle">
                                                <iconify-icon icon="mdi:printer-outline" class="menu-icon"></iconify-icon>
                                            </a>
                                        @endif
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
      order: [[0,'desc']]
    });
  });
</script>




@endsection

    
  

  