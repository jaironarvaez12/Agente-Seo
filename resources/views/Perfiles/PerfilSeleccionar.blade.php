@extends('layouts.master')

@section('titulo', 'Seleccionar Página')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <h6 class="fw-semibold mb-16">Selecciona la página a conectar</h6>

            <form method="POST" action="{{ route('perfiles.facebook.selectPage') }}">
                @csrf

                <div class="mb-20">
                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">Páginas</label>
                    <select name="page_id" class="form-control radius-8" required>
                        @foreach($pages as $p)
                            <option value="{{ $p['id'] }}">{{ $p['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                    <a href="{{ route('perfiles.create') }}"
                       class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                        Cancelar
                    </a>

                    <button type="submit"
                            class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                        Guardar Perfil
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection