@extends('layouts.master')

@section('titulo', 'Inicio')


@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')
<div class="dashboard-main-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
  <h6 class="fw-semibold mb-0">Editar Usuario</h6>
  <ul class="d-flex align-items-center gap-2">
    <li class="fw-medium">
      <a href="{!! route('usuarios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
        <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
        Usuarios
      </a>
    </li>
    <li>-</li>
    <li class="fw-medium">Editar Usuario</li>
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
                                            <input type='file' id="imagen" name="imagen" accept=".png, .jpg, .jpeg" hidden >
                                            <label for="imagen" class="w-32-px h-32-px d-flex justify-content-center align-items-center bg-primary-50 text-primary-600 border border-primary-600 bg-hover-primary-100 text-lg rounded-circle">
                                                <iconify-icon icon="solar:camera-outline" class="icon"></iconify-icon>
                                            </label>
                                        </div>
                                        @php
                                                $imgRel = $articulo->imagen_articulo ?? null;  // p.ej. "images/tiendas/tienda/123/123.jpg"
                                                $imgUrl = ($imgRel && file_exists(public_path($imgRel)))
                                                    ? asset($imgRel)
                                                    : asset('images/placeholder.jpg'); // pon tu placeholder si quieres
                                            @endphp

                                        <div id="avatar-preview" class="avatar-preview" style="
                                                    width:160px; height:160px;
                                                    background-image:url('{{ $imgUrl }}');
                                                    background-size:cover; background-position:center; background-repeat:no-repeat;
                                                    border-radius:12px;">
                                        
                                        </div>
                                    </div> --}}
                                </div>
                                <!-- Upload Image End -->
                                
                             <form method="POST" action=" {{ route('usuarios.update', $usuario->id) }}" enctype="multipart/form-data">
                                @csrf 
                                @method('put')
                                    <div class="mb-20">
                                        <label for="name" class="form-label fw-semibold text-primary-light text-sm mb-8">Nombre<span class="text-danger-600">*</span></label>
                                        
                                         <input type="text" class="form-control radius-8 @error('name') is-invalid @enderror"
                                        name="name" value=" {{ old('name', $usuario->name ?? '') }}" placeholder="Ingrese su nombre completo" readonly>



                                    </div>
                                    <div class="mb-20">
                                        <label for="contraseña" class="form-label fw-semibold text-primary-light text-sm mb-8">Contraseña<span class="text-danger-600">*</span></label>
                                        
                                        <input type="password" class="form-control radius-8 @error('contraseña') is-invalid @enderror"
                                        name="contraseña" value="{{ old('contraseña') }}" placeholder="Ingrese su contraseña">

                                    </div>
                                    <div class="mb-20">
                                        <label for="email" class="form-label fw-semibold text-primary-light text-sm mb-8">Correo <span class="text-danger-600">*</span></label>
                                        <input type="text" class="form-control radius-8 @error('email') is-invalid @enderror"
                                            name="email" value=" {{ old('email', $usuario->email ?? '') }}"placeholder="Ingrese su correo">
                                    </div>
                                    {{-- <div class="mb-20">
                                        <label for="number" class="form-label fw-semibold text-primary-light text-sm mb-8">Telefono</label>
                                        <input type="text" class="form-control radius-8" id="telefono" name="telefono" placeholder="Ingrese su telefono">
                                        
                                    </div> --}}
                                    <div class="mb-20">
                                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                            Licencia (dejar vacío si no se cambia)
                                        </label>

                                        <input type="text"
                                                class="form-control radius-8 @error('license_key') is-invalid @enderror"
                                                name="license_key"
                                                value="{{ old('license_key') }}"
                                                placeholder="Pega aquí la licencia nueva">
                                        </div>

                                        <div class="mb-20">
                                        <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                            Email de licencia (opcional)
                                        </label>

                                        <input type="text"
                                                class="form-control radius-8 @error('license_email') is-invalid @enderror"
                                                name="license_email"
                                                value="{{ old('license_email', $usuario->license_email ?? '') }}"
                                                placeholder="email del cliente (opcional)">
                                        </div>
                                    
                                    <div class="mb-20">
                                       <label for="depart" class="form-label fw-semibold text-primary-light text-sm mb-8">Rol</label>
                                            <select class="form-control radius-8 form-select" id="roles" name="roles" >
                                                <option value="0">Seleccione un Rol</option>
                                                     @foreach ($roles as $role)
                                                        <option value="{{ $role->name }}" 
                                                            @if ($role->name == old('roles', $usuario->roles[0]->name ?? ''))  selected = "selected"
                                                            @endif>{!! $role->name !!}</option>
                                                    @endforeach
                                            </select>
                                        
                                    </div>
                                    
                                      <div class="row">
                                        <div class="col-md-8">
                                            <label for="depart" class="form-label fw-semibold text-primary-light text-sm mb-8">Dominios</label>
                                
                                             <select class="form-control radius-8 form-select" id="id_dominio" name="id_dominio" >
                                                <option value="0">Seleccione un Dominio</option>
                                                     @foreach ($dominios as $dominio)
                                                        <option value="{{ $dominio->id_dominio }}" 
                                                            @if ($dominio->id_dominio == old('id_dominio', $dominio->id_dominio ?? ''))  selected = "selected"
                                                            @endif>{!! $dominio->nombre !!} - {!! $dominio->url !!}</option>
                                                    @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="button" 
                                                    class="btn btn-primary d-flex align-items-center gap-2 px-10 py-6 radius-8" onClick="CargarTablaTiendas()">
                                                <iconify-icon icon="ic:baseline-plus" class="icon text-lg"></iconify-icon>
                                                
                                            </button>
                                        </div>


                                    </div>
                                   
                                   <br>
                                      <div class="table-responsive scroll-sm">
                                        <table class="table bordered-table sm-table mb-0" id="tabla_dominios">
                                            <thead>
                                                <th style="display:none;">Id_Dominio Usuario</th>
                                                <th style="display:none;">Id_Dominio</th>
                                         
                                                <th>Dominio</th>
                                                <th >Url</th>
                                                <th>Accion</th>
                                            </thead>
                                            <tbody>
                                            
                                           
                                                @if($DominiosUsuario != null)
                                                    @foreach($DominiosUsuario as $dominio)
                                                        <tr>
                                                            
                                                            <td id='id_dominio_usuario'style="display:none;">{{$dominio->id_dominio_usuario}}</td>
                                                            <td id='id_dominio' style="display:none;">{{$dominio->id_dominio}}</td>
                                                            <td id='nombre'>{{$dominio->nombre}}</td>
                                                            <td id='url' >{{$dominio->url}}</td>
                                                            <th>
                                                          



                                                            <button type="button"
                                                                    onclick="EliminarTiendas({{$dominio->id_dominio_usuario}})"
                                                                    class="borrar remove-item-btn bg-danger-focus bg-hover-danger-200 text-danger-600 fw-medium w-40-px h-40-px d-flex justify-content-center align-items-center rounded-circle">
                                                                <iconify-icon icon="fluent:delete-24-regular" class="menu-icon"></iconify-icon>
                                                            </button>
                                                            </th>
                                                        </tr>
                                                    @endforeach
                                                @endif
                                            
                                            </tbody>
                                        </table>
                                    </div>
                                    <br>
                                  
                                  
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
    var eliminartienda= "{{ url('eliminartienda') }}";
</script>
<script type="text/javascript" src="{{ asset('assets\js\UsuariosEdit.js') }}"></script>
@endsection


    
  

  