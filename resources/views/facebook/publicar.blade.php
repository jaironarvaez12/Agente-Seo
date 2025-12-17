@extends('layouts.master')

@section('titulo', 'Publicar en Facebook')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Publicar en {{ $perfil->fb_page_name ?? $perfil->nombre }}</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Publicar en Página</li>
        </ul>
    </div>

    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <div class="row justify-content-center">
                <div class="col-xxl-6 col-xl-8 col-lg-10">
                    <div class="card border">
                        <div class="card-body">
                           <form method="POST" action="{{ route('facebook.publish', $perfil->id) }}" enctype="multipart/form-data">
                            @csrf

                            <div class="mb-20">
                                <label for="message" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Contenido de la publicación
                                </label>
                                <textarea name="message" id="message" rows="5"
                                        class="form-control radius-8"
                                        placeholder="Escribe el texto de tu publicación aquí..."></textarea>
                            </div>

                            <div class="mb-20">
                                <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Imágenes (puedes seleccionar varias)
                                </label>
                                <input type="file" name="images[]" class="form-control radius-8" accept="image/*" multiple>
                                <small class="text-primary-light d-block mt-6">Formatos comunes (jpg, png) – máx 5MB por archivo.</small>
                            </div>

                            <div class="mb-20">
                                <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    URLs de imágenes (opcional)
                                </label>
                                <input type="url" name="image_urls[]" class="form-control radius-8 mb-8" placeholder="https://...">
                                <input type="url" name="image_urls[]" class="form-control radius-8 mb-8" placeholder="https://...">
                                <small class="text-primary-light">Puedes pegar una o varias URLs directas a imagen.</small>
                            </div>

                            <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                                <button type="submit" class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                                    Publicar Ahora
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