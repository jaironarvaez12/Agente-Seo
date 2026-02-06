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

    
    <div class="row gy-4">
        <div class="col-xxl-6">
            <div class="card">
                <div class="card-body p-20">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="trail-bg h-100 text-center d-flex flex-column justify-content-between align-items-center p-16 radius-8">

                                <h6 class="text-white text-xl">
                                    Tu plan: {{ strtoupper($planName ?? 'FREE') }}
                                </h6>

                                <div>
                                    @if(!empty($expiresAt))
                                        <p class="text-white mb-2">
                                            Vigencia hasta: {{ $expiresAt->format('d/m/Y') }}
                                        </p>

                                        <p class="text-white">
                                            @if(($daysLeft ?? 0) > 0)
                                                Te quedan {{ $daysLeft }} día(s)
                                            @else
                                                Tu licencia está vencida
                                            @endif
                                        </p>
                                    @else
                                        <p class="text-white">
                                            No hay información de vigencia.
                                        </p>
                                    @endif

                                    @if(($planName ?? 'free') === 'free' || empty($isActive) || ($daysLeft ?? 0) <= 0)
                                        <a href="#"
                                        class="btn py-8 rounded-pill w-100 bg-gradient-blue-warning text-sm">
                                            Mejorar plan
                                        </a>
                                    @else
                                        <a href="#"
                                        class="btn py-8 rounded-pill w-100 bg-gradient-blue-warning text-sm">
                                            Administrar plan
                                        </a>
                                    @endif
                                </div>

                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="row g-3">
                                <div class="col-sm-6 col-xs-6">
                                    <div class="radius-8 h-100 text-center p-20 bg-purple-light">
                                        <span class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-xl mb-12 bg-lilac-200 border border-lilac-400 text-lilac-600">
                                            <i class="ri-price-tag-3-fill"></i>
                                        </span>
                                        <span class="text-neutral-700 d-block">Total Contenido Gen.</span>
                                        <h6 class="mb-0 mt-4">{{$generado}}</h6>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xs-6">
                                    <div class="radius-8 h-100 text-center p-20 bg-success-100">
                                        <span class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-xl mb-12 bg-success-200 border border-success-400 text-success-600">
                                            <i class="ri-shopping-cart-2-fill"></i>
                                        </span>
                                        <span class="text-neutral-700 d-block">Total Dominios Reg.</span>
                                        <h6 class="mb-0 mt-4">{{$dominios}}</h6>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xs-6">
                                    <div class="radius-8 h-100 text-center p-20 bg-info-focus">
                                        <span class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-xl mb-12 bg-info-200 border border-info-400 text-info-600">
                                            <i class="ri-group-3-fill"></i>
                                        </span>
                                        <span class="text-neutral-700 d-block">Total Reportes</span>
                                        <h6 class="mb-0 mt-4">{{$reportes}} </h6>
                                    </div>
                                </div>
                                <div class="col-sm-6 col-xs-6">
                                    <div class="radius-8 h-100 text-center p-20 bg-danger-100">
                                        <span class="w-44-px h-44-px radius-8 d-inline-flex justify-content-center align-items-center text-xl mb-12 bg-danger-200 border border-danger-400 text-danger-600">
                                            <i class="ri-refund-2-line"></i>
                                        </span>
                                        <span class="text-neutral-700 d-block">Total Backlinks</span>
                                        <h6 class="mb-0 mt-4">2756</h6>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xxl-6">
            <div class="card h-100">
                <div class="card-body p-24 mb-8">
                    <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                        <h6 class="mb-2 fw-bold text-lg mb-0">Publicados vs Programados</h6>
                        <select class="form-select form-select-sm w-auto bg-base border text-secondary-light radius-8">
                            <option>Yearly</option>
                            <option>Monthly</option>
                            <option>Weekly</option>
                            <option>Today</option>
                        </select>
                    </div>
                    <ul class="d-flex flex-wrap align-items-center justify-content-center my-3 gap-24">
                        <li class="d-flex flex-column gap-1">
                            <div class="d-flex align-items-center gap-2">
                                <span class="w-8-px h-8-px rounded-pill bg-primary-600"></span>
                                <span class="text-secondary-light text-sm fw-semibold">Publicados </span>
                            </div>
                            <div class="d-flex align-items-center gap-8">
                                <h6 class="mb-0">$26,201</h6>
                                <span class="text-success-600 d-flex align-items-center gap-1 text-sm fw-bolder">
                                    10%
                                    <i class="ri-arrow-up-s-fill d-flex"></i>
                                </span>
                            </div>
                        </li>
                        <li class="d-flex flex-column gap-1">
                            <div class="d-flex align-items-center gap-2">
                                <span class="w-8-px h-8-px rounded-pill bg-lilac-600"></span>
                                <span class="text-secondary-light text-sm fw-semibold">Programados </span>
                            </div>
                            <div class="d-flex align-items-center gap-8">
                                <h6 class="mb-0">$18,120</h6>
                                <span class="text-danger-600 d-flex align-items-center gap-1 text-sm fw-bolder">
                                    10%
                                    <i class="ri-arrow-down-s-fill d-flex"></i>
                                </span>
                            </div>
                        </li>
                    </ul>
                    <div id="revenueChart" class="apexcharts-tooltip-style-1"></div>
                </div>
            </div>
        </div>
        <div class="col-xxl-4 col-xl-6">
            <div class="card h-100">
                <div class="card-body p-24">
                    <div class="d-flex align-items-center flex-wrap gap-2 justify-content-between">
                        <h6 class="mb-2 fw-bold text-lg">Tipo de Contenidos Generado</h6>
                        <select class="form-select form-select-sm w-auto bg-base border text-secondary-light radius-8">
                            <option>Yearly</option>
                            <option>Monthly</option>
                            <option>Weekly</option>
                            <option>Today</option>
                        </select>
                    </div>
                    <div class="mt-32 d-flex flex-wrap gap-24 align-items-center justify-content-between">
                        <div class="d-flex flex-column gap-24">
                            <div class="d-flex align-items-center gap-3">
                                <div class="w-40-px h-40-px rounded-circle d-flex justify-content-center align-items-center bg-primary-100 flex-shrink-0">
                                    <img src="assets/images/home-nine/ticket1.png" alt="" class="">
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="text-md mb-0 fw-bold">172</h6>
                                    <span class="text-sm text-secondary-light fw-normal">Post </span>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div class="w-40-px h-40-px rounded-circle d-flex justify-content-center align-items-center bg-warning-100 flex-shrink-0">
                                    <img src="assets/images/home-nine/ticket2.png" alt="" class="">
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="text-md mb-0 fw-bold">172</h6>
                                    <span class="text-sm text-secondary-light fw-normal">Paginas</span>
                                </div>
                            </div>
                         
                        </div>
                        <div class="position-relative">
                            <div id="userOverviewDonutChart" class="apexcharts-tooltip-z-none"></div>
                            <div class="text-center max-w-135-px max-h-135-px bg-warning-focus rounded-circle p-16 aspect-ratio-1 d-flex flex-column justify-content-center align-items-center position-absolute start-50 top-50 translate-middle">
                                <h6 class="fw-bold">120</h6>
                                <span class="text-secondary-light">Total Tickets</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <div class="col-xxl-8">
            <div class="card h-100">
            <div class="card-header border-bottom bg-base py-16 px-24 d-flex align-items-center justify-content-between">
                <h6 class="text-lg fw-semibold mb-0">Contenido Generado Recientemente</h6>
                <a href="javascript:void(0)" class="text-primary-600 hover-text-primary d-flex align-items-center gap-1">
                View All
                <iconify-icon icon="solar:alt-arrow-right-linear" class="icon"></iconify-icon>
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive scroll-sm">
                <table class="table bordered-table mb-0 rounded-0 border-0">
                    <thead>
                        <tr>
                            <th scope="col" class="bg-transparent rounded-0">Customer</th>
                            <th scope="col" class="bg-transparent rounded-0">ID</th>
                            <th scope="col" class="bg-transparent rounded-0">Retained</th>
                            <th scope="col" class="bg-transparent rounded-0">Amount</th>
                            <th scope="col" class="bg-transparent rounded-0">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="assets/images/user-grid/user-grid-img1.png" alt="" class="w-40-px h-40-px rounded-circle flex-shrink-0 me-12 overflow-hidden">
                                    <div class="flex-grow-1">
                                        <h6 class="text-md mb-0">Kristin Watson</h6>
                                        <span class="text-sm text-secondary-light fw-medium">ulfaha@mail.ru</span>
                                    </div>
                                </div>
                            </td>
                            <td>#63254</td>
                            <td>5 min ago</td>
                            <td>$12,408.12</td>
                            <td> <span class="bg-success-focus text-success-main px-10 py-4 radius-8 fw-medium text-sm">Member</span> </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="assets/images/user-grid/user-grid-img2.png" alt="" class="w-40-px h-40-px rounded-circle flex-shrink-0 me-12 overflow-hidden">
                                    <div class="flex-grow-1">
                                        <h6 class="text-md mb-0">Theresa Webb</h6>
                                        <span class="text-sm text-secondary-light fw-medium">joie@gmail.com</span>
                                    </div>
                                </div>
                            </td>
                            <td>#63254</td>
                            <td>12 min ago</td>
                            <td>$12,408.12</td>
                            <td> <span class="bg-lilac-100 text-lilac-600 px-10 py-4 radius-8 fw-medium text-sm">New Customer</span> </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="assets/images/user-grid/user-grid-img3.png" alt="" class="w-40-px h-40-px rounded-circle flex-shrink-0 me-12 overflow-hidden">
                                    <div class="flex-grow-1">
                                        <h6 class="text-md mb-0">Brooklyn Simmons</h6>
                                        <span class="text-sm text-secondary-light fw-medium">warn@mail.ru</span>
                                    </div>
                                </div>
                            </td>
                            <td>#63254</td>
                            <td>15 min ago</td>
                            <td>$12,408.12</td>
                            <td> <span class="bg-warning-focus text-warning-main px-10 py-4 radius-8 fw-medium text-sm">Signed Up </span> </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="assets/images/user-grid/user-grid-img4.png" alt="" class="w-40-px h-40-px rounded-circle flex-shrink-0 me-12 overflow-hidden">
                                    <div class="flex-grow-1">
                                        <h6 class="text-md mb-0">Robert Fox</h6>
                                        <span class="text-sm text-secondary-light fw-medium">fellora@mail.ru</span>
                                    </div>
                                </div>
                            </td>
                            <td>#63254</td>
                            <td>17 min ago</td>
                            <td>$12,408.12</td>
                            <td> <span class="bg-success-focus text-success-main px-10 py-4 radius-8 fw-medium text-sm">Member</span> </td>
                        </tr>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="assets/images/user-grid/user-grid-img5.png" alt="" class="w-40-px h-40-px rounded-circle flex-shrink-0 me-12 overflow-hidden">
                                    <div class="flex-grow-1">
                                        <h6 class="text-md mb-0">Jane Cooper</h6>
                                        <span class="text-sm text-secondary-light fw-medium">zitka@mail.ru</span>
                                    </div>
                                </div>
                            </td>
                            <td>#63254</td>
                            <td>25 min ago</td>
                            <td>$12,408.12</td>
                            <td> <span class="bg-warning-focus text-warning-main px-10 py-4 radius-8 fw-medium text-sm">Signed Up </span> </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>
            </div>
        </div>
    </div>
          <div class="card basic-data-table">
            @if(isset($maximo))
                <div class="alert alert-info mb-20">
                    <b>Plan:</b> {{ $plan }} |
                    <b>Dominios activados:</b> {{ $usados }} / {{ $maximo }} |
                    <b>Disponibles:</b> {{ $restantes }}
                </div>
            @endif
           <div class="card-header text-end">
               <div class="d-flex justify-content-end">
                    <div style="width: auto;">
                     
                    </div>
                </div>
            </div>
            <div class="card-body">
              <div class="table-responsive scroll-sm">
               
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

    