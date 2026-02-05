@extends('layouts.master')

@section('titulo', 'Dominios')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
<div class="dashboard-main-body">
 <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
       <h6 class="fw-semibold mb-0">Datos del Dominio</h6>
    <div class="d-flex align-items-center gap-3">

        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
              <a href="{!! route('dominios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
                <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                Dominios
              </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Datos del Dominio</li>
        </ul>


          <a href="{{ route('dominios.contenido_generado',$dominio->id_dominio) }}" 
           class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
           
            Contenido Generado
        </a>
          <a href="{{ route('dominios.wp',$dominio->id_dominio) }}" 
           class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
           
            Contenido WordPress
        </a>

          <form action="{{ route('generador', $dominio->id_dominio) }}" method="POST" style="display:inline;">
          @csrf
          <button type="submit"
              title="GENERAR"
              class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
              Generar Contenido
          </button>
          </form>



      <form action="{{ route('dominios.reporte_seo.generar', $dominio->id_dominio) }}" method="POST" style="display:inline;">
        @csrf
        <button type="submit" class="btn btn-danger text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
          Generar Reporte SEO 
        </button>
      </form>

      <a href="{{ route('dominios.reporte_seo.ver', $dominio->id_dominio) }}"
        class="btn btn-outline-danger text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
       Ver Reporte Generado
      </a>
      {{-- <a href="{{ route('dominios.auto_generacion.editar', $dominio->id_dominio) }}"
        class="btn btn-warning text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
        Auto-Generación
      </a>     --}}

    </div>
    </div>

        <div class="card h-100 p-0 radius-12">
            
            <div class="card-body p-24">
                <div class="table-responsive scroll-sm">
                    <table class="table bordered-table sm-table mb-0">
                      <thead>
                        <tr>
                          <th scope="col">Dominio</th>
                          <th scope="col">Url </th>
                          <th scope="col">Usuario</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td><span class="text-md mb-0 fw-normal text-secondary-light">{{ $dominio->nombre }}</span></td>
                          <td><span class="text-md mb-0 fw-normal text-secondary-light">{{ $dominio->url }}</span></td>
                          <td><span class="text-md mb-0 fw-normal text-secondary-light">{{ $dominio->usuario }}</span></td>
                        
                        </tr>
                         
                        
                      
                      </tbody>
                    </table>
                </div>

                {{-- === Detalles de pago + Resumen lateral === --}}
                <div class="row g-16">
                    
                    <div class="col-12 col-lg-8">
                        <div class="bg-base radius-12 p-16 h-100">
                      
                          <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-md text-primary-light mb-16">Generadores de Contenido</h6>

                            <a href="{{ route('dominioscrearcontenido',$dominio->id_dominio) }}"
                              class="btn btn-primary">
                              Añadir
                            </a>
                          </div>
                          
                          <div class="table-responsive scroll-sm">
                              <table class="table bordered-table sm-table mb-0">
                              <thead>
                                  <tr class="text-secondary-light">
                                      <th>Tipo de Generador</th>
                                      <th>Num de Palabras</th>
                                      <th>Palabras Clave</th>
                                      <th>Estatus</th>
                                      <th>Accion</th>
                                  </tr>
                              </thead>
                              <tbody>
                                
                                  @foreach ($generadores as $generador)
                                      <tr>
                                          <td>{{ $generador->tipo }}</td>
                                        
                                          <td>{{ $generador->total_palabras_clave }}</td>
                                          <td>{!! nl2br(e(str_replace(',', ",\n", $generador->palabras_claves))) !!}</td>

                                         
                                          <td>
                                            @if($generador->estatus=='SI')
                                              <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Activo</span> 
                                            @else
                                              <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">Inactivo</span> 
                                            @endif
                                          </td>
                                          <td class="text-center"> 
                                              <div class="d-flex align-items-center gap-10 justify-content-center">
                                                <a href="{{ route('dominioeditartipogenerador', $generador->id_dominio_contenido) }}" 
                                                  title="Editar"
                                                  class="bg-success-focus text-success-600 bg-hover-success-200 fw-medium w-40-px h-40-px 
                                                      d-flex justify-content-center align-items-center rounded-circle">
                                                  <iconify-icon icon="lucide:edit" class="menu-icon"></iconify-icon>
                                                </a>
                                              </div>
                                          </td>

                                      </tr>
                                  @endforeach 
                              </tbody>
                              </table>
                          </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-8">
                        <div class="bg-base radius-12 p-16 h-100">
                      
                          <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="text-md text-primary-light mb-16">Auto Generacion de Contenido</h6>

                            <a href="{{ route('dominios.auto_generacion.editar', $dominio->id_dominio) }}"
                              class="btn btn-success">
                              Editar
                            </a>
                          </div>
                          
                          <div class="table-responsive scroll-sm">
                              <table class="table bordered-table sm-table mb-0">
                              <thead>
                                  <tr class="text-secondary-light">
                                      <th>Estatus</th>
                                      <th>Frecuencia</th>
                                      <th>Contenido por Ejecucion</th>
                                      <th>Proxima Ejecucion</th>
                                   
                                  </tr>
                              </thead>
                              <tbody>
                                
                             
                                      <tr>
                                         <td>
                                            @if($dominio->auto_generacion_activa=='1')
                                              <span class="bg-success-focus text-success-600 border border-success-main px-24 py-4 radius-4 fw-medium text-sm">Activo</span> 
                                            @else
                                              <span class="bg-danger-focus text-danger-600 border border-danger-main px-24 py-4 radius-4 fw-medium text-sm">Inactivo</span> 
                                            @endif
                                          </td>
                                          <td>
                                            @if($dominio->auto_frecuencia=='daily')
                                              Diario
                                            @elseif($dominio->auto_frecuencia=='hourly')
                                              Cada hora
                                            @elseif($dominio->auto_frecuencia=='weekly')
                                              Semanal
                                            @elseif($dominio->auto_frecuencia=='custom')
                                            Personalizado cada  {{ $dominio->auto_cada_minutos}} minutos
                                      
                                            @endif
                                    
                                          </td>
                                        
                                          <td>{{ $dominio->auto_tareas_por_ejecucion }}</td>
                                          <td>
                                             
                                              {{date('d-m-Y g:i:s A', strtotime($dominio->auto_siguiente_ejecucion))}}
                                          </td>
                                         

                                      </tr>
                              
                              </tbody>
                              </table>
                          </div>
                        </div>
                    </div>

                    {{-- Resumen de totales (lateral derecho) --}}
                    {{-- <div class="col-12 col-lg-4">
                        <div class="bg-base radius-12 p-16 h-100">
                        <div class="mb-8 fw-semibold"></div>
                        <div class="d-flex justify-content-between align-items-center mb-12">
                            <span class="text-secondary-light">Subtotal</span>
                            <span class="fw-medium"> {{  $sumaTotales }} €</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-12">
                            <span class="text-secondary-light">Impuesto</span>
                            <span class="fw-medium"> {{ $pedido->impuesto }}€</span>
                        </div>

                        
                        <div class="d-flex justify-content-between align-items-center mb-12">
                            <span class="text-secondary-light">Descuento</span>
                            <span class="fw-medium">{{ $pedido->descuento }}€ </span>
                        </div>

                        
                        <div class="d-flex justify-content-between align-items-center mb-16">
                            <span class="text-secondary-light">Envío</span>
                            <span class="fw-medium"> {{ $pedido->envio }}€ </span>
                        </div>

                        <div class="border-top pt-12 d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Total</span>
                            <span class="fw-bold text-danger-600">{{ $pedido->total }}€ </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center ">
                            <span class="text-secondary-light">Pagado</span>
                            <span class="fw-medium"> {{ $pedido->total_pagado }}€ </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-12">
                            <span class="text-secondary-light">Cambio</span>
                            <span class="fw-medium"> {{ $pedido->cambio }}€ </span>
                        </div>
                        </div>
                    </div> --}}
                </div>

               
            </div>
        </div>
    </div>

@endsection

@section('scripts')

@endsection

    
  

  