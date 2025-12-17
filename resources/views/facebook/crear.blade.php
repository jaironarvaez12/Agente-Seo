@extends('layouts.master')

@section('titulo', 'Añadir Página de Facebook')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Añadir Página de Facebook</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Añadir Página</li>
        </ul>
    </div>

    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <div class="row justify-content-center">
                <div class="col-xxl-6 col-xl-8 col-lg-10">
                    <div class="card border">
                        <div class="card-body">

                            <form method="POST" action="{{ route('facebook.store') }}">
                                @csrf

                                <div class="mb-20">
                                    <label for="nombre" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Nombre interno<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="text" class="form-control radius-8" id="nombre" name="nombre"
                                           placeholder="Ej: Salaos Fast Food">
                                </div>

                                <div class="mb-20">
                                    <label for="fb_page_id" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        ID de la Página (page_id)<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="text" class="form-control radius-8" id="fb_page_id" name="fb_page_id"
                                           placeholder="Ej: 123456789012345">
                                </div>

                                <div class="mb-20">
                                    <label for="fb_page_name" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Nombre de la Página
                                    </label>
                                    <input type="text" class="form-control radius-8" id="fb_page_name" name="fb_page_name"
                                           placeholder="Ej: Salaos Fast Food Oficial">
                                </div>

                               <div class="mb-20">
  <label for="fb_system_user_token" class="form-label fw-semibold text-primary-light text-sm mb-8">
      Token del System User (token global)<span class="text-danger-600">*</span>
  </label>
  <textarea class="form-control radius-8" id="fb_system_user_token" name="fb_system_user_token"
            rows="4" placeholder="Pega aquí tu token del usuario del sistema"></textarea>
</div>

<div class="mb-20">
  <label for="fb_page_token" class="form-label fw-semibold text-primary-light text-sm mb-8">
      Token de la Página (opcional, se puede generar después)
  </label>
  <textarea class="form-control radius-8" id="fb_page_token" name="fb_page_token"
            rows="4" placeholder="Si ya tienes el token de la página, pégalo aquí."></textarea>
</div>

                                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                                    <button type="button"
                                            onclick="window.location.href='{{ route('inicio') }}'"
                                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                                        Cancelar
                                    </button>
                                    <button type="submit"
                                            class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                                        Guardar Página
                                    </button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection