@extends('layouts.master')
@section('titulo', 'Sincronizar Token de Página')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
    <h6 class="fw-semibold mb-0">Sincronizar Token de Página</h6>
    <ul class="d-flex align-items-center gap-2">
      <li class="fw-medium">
        <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
          <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
          Inicio
        </a>
      </li>
      <li>-</li>
      <li class="fw-medium">Facebook</li>
    </ul>
  </div>

  <div class="card h-100 p-0 radius-12">
    <div class="card-body p-24">
      <div class="row justify-content-center">
        <div class="col-xxl-6 col-xl-8 col-lg-10">
          <div class="card border">
            <div class="card-body">
              <form method="POST" action="{{ route('facebook.sync.run', $perfil->id) }}">
                @csrf

                <div class="mb-20">
                  <label class="form-label fw-semibold text-primary-light text-sm mb-8">Page ID</label>
                  <input type="text" class="form-control radius-8" value="{{ $perfil->fb_page_id }}" disabled>
                </div>

                <div class="mb-20">
                  <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                    System User Token (puedes pegar uno nuevo si quieres)
                  </label>
                  <textarea name="fb_system_user_token" rows="3" class="form-control radius-8"
                            placeholder="EAA... (opcional)">{{ old('fb_system_user_token') }}</textarea>
                  <small class="text-primary-light">
                    Guardado: {{ $perfil->fb_system_user_token ? 'Sí' : 'No' }}
                  </small>
                </div>

                <div class="mb-12">
                  <span class="badge bg-{{ $perfil->fb_page_token ? 'success' : 'danger' }}-subtle text-{{ $perfil->fb_page_token ? 'success' : 'danger' }}-600">
                    {{ $perfil->fb_page_token ? 'Page token configurado' : 'Page token no configurado' }}
                  </span>
                </div>

                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                  <button type="submit" class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                    Sincronizar ahora
                  </button>
                </div>
              </form>

              <hr class="my-24">

              <div class="text-sm text-primary-light">
                <p class="mb-6">Recuerda:</p>
                <ul class="ps-3">
                  <li>/me/accounts → usar <strong>System User token</strong>.</li>
                  <li>Publicar (/PAGE_ID/feed) → usar <strong>Page token</strong>.</li>
                  <li>Asigna la Página al System User en Business Manager con permisos de publicación.</li>
                </ul>
              </div>

            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection