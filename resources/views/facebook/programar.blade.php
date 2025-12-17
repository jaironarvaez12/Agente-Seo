@extends('layouts.master')

@section('titulo', 'Programar Publicación')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Programar Publicación - {{ $perfil->fb_page_name ?? $perfil->nombre }}</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Programar</li>
        </ul>
    </div>

    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <div class="row justify-content-center">
                <div class="col-xxl-6 col-xl-8 col-lg-10">
                    <div class="card border">
                        <div class="card-body">
                            <form method="POST" action="{{ route('facebook.schedule', $perfil->id) }}">
                                @csrf

                                <div class="mb-20">
                                    <label for="message" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Mensaje de la publicación<span class="text-danger-600">*</span>
                                    </label>
                                    <textarea name="message" rows="5" class="form-control radius-8"
                                              placeholder="Escribe el texto de la publicación..."></textarea>
                                </div>

                                <div class="mb-20">
                                    <label for="scheduled_at" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Fecha y hora de publicación<span class="text-danger-600">*</span>
                                    </label>
                                    <input type="datetime-local" name="scheduled_at" class="form-control radius-8" required>
                                </div>

                                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                                    <button type="button"
                                            onclick="window.location.href='{{ route('facebook.publish.form', $perfil->id) }}'"
                                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                                        Cancelar
                                    </button>
                                    <button type="submit"
                                            class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                                        Programar Publicación
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