@extends('layouts.master')

@section('titulo', 'Inicio')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')
<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Añadir Usuario</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="{!! route('usuarios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Usuarios
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Añadir Usuario</li>
  </ul>
</div>
        
        <div class="card h-100 p-0 radius-12">
            <div class="card-body p-24">
                <div class="row justify-content-center">
                    <div class="col-xxl-6 col-xl-8 col-lg-10">
                        <div class="card border">
                            <div class="card-body">
                           
                                {{-- <h6 class="text-md text-primary-light mb-16">Profile Image</h6> --}}
                                
                                <!-- Upload Image Start -->
                                <div class="mb-24 mt-16">
                                    {{-- <div class="avatar-upload">
                                        <div class="avatar-edit position-absolute bottom-0 end-0 me-24 mt-16 z-1 cursor-pointer">
                                            <input type='file' id="imageUpload" accept=".png, .jpg, .jpeg" hidden>
                                            <label for="imageUpload" class="w-32-px h-32-px d-flex justify-content-center align-items-center bg-primary-50 text-primary-600 border border-primary-600 bg-hover-primary-100 text-lg rounded-circle">
                                                <iconify-icon icon="solar:camera-outline" class="icon"></iconify-icon>
                                            </label>
                                        </div>
                                        <div class="avatar-preview">
                                            <div id="imagePreview"> </div>
                                        </div>
                                    </div> --}}
                                </div>
                                <!-- Upload Image End -->
                                
                               <form class="" method="POST" action=" {{ route('usuarios.store') }}">
                                @csrf
                                    <div class="mb-20">
                                        <label for="name" class="form-label fw-semibold text-primary-light text-sm mb-8">Nombre<span class="text-danger-600">*</span></label>
                                        <input type="text" class="form-control radius-8" id="name" name="name" placeholder="Ingrese su nombre completo">
                                    </div>
                                    <div class="mb-20">
                                        <label for="contraseña" class="form-label fw-semibold text-primary-light text-sm mb-8">Contraseña<span class="text-danger-600">*</span></label>
                                        <input type="password" class="form-control radius-8" id="contraseña" name="contraseña" placeholder="Ingrese su contraseña ">
                                    </div>
                                    <div class="mb-20">
                                        <label for="email" class="form-label fw-semibold text-primary-light text-sm mb-8">Correo <span class="text-danger-600">*</span></label>
                                        <input type="email" class="form-control radius-8" id="email" name="email" placeholder="Ingrese su correo">
                                    </div>
                                    {{-- <div class="mb-20">
                                        <label for="number" class="form-label fw-semibold text-primary-light text-sm mb-8">Telefono</label>
                                        <input type="text" class="form-control radius-8" id="telefono" name="telefono" placeholder="Ingrese su telefono">
                                    </div> --}}
                                   
                                  
                                      <div class="mb-20">
                                       <label for="depart" class="form-label fw-semibold text-primary-light text-sm mb-8">Rol</label>
                                            <select class="form-control radius-8 form-select" id="roles" name="roles" >
                                                <option value="0">Seleccione un Rol</option>
                                                     @foreach ($roles as $role)
                                                        <option value="{{ $role->name }}" 
                                                            @if ($role->name == old('roles'))  selected = "selected"
                                                            @endif>{!! $role->name !!}</option>
                                                    @endforeach
                                            </select>
                                        
                                    </div>
                                    
                                    
                                  
                                      <input type="hidden" name="datos_tiendas" id="datos_tiendas">
                                    
                                    <div class="d-flex align-items-center justify-content-center gap-3">
                                        <button type="button"
                                                onclick="window.location.href='{{ route('usuarios.index') }}'"
                                                class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                                            Cancelar
                                        </button>
                                        <button type="submit" class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8" OnClick="CapturarDatosTabla()"> 
                                            Guardar
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


@section('scripts')
<script>
    var obtenertienda = "{{ url('obtenertienda') }}"; 
</script>
<script type="text/javascript" src="{{ asset('assets\js\Usuarios.js') }}"></script>
@endsection


    
  

  