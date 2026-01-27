@extends('layouts.master')

@section('titulo', 'Identidad de Dominios')


@section('contenido')
<style>
  .paleta-texto .palette-item.active{
    outline: 2px solid #3b82f6;
    outline-offset: 2px;
  }
</style>
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
<div class="dashboard-main-body">
 <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
       <h6 class="fw-semibold mb-0">Identidad Visual Dominios</h6>
    <div class="d-flex align-items-center gap-3">

        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
              <a href="{!! route('dominios.index') !!}" class="d-flex align-items-center gap-1 hover-text-primary">
                <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                Dominios
              </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Identidad</li>
        </ul>


          

    </div>
    </div>
      <form method="POST" action="{{ route('dominiosactualizaridentidad') }}" enctype="multipart/form-data">
      @csrf
        <div class="card h-100 p-0 radius-12">
         
           @php
                    $paletaTexto = [
                      ['hex' => '#487FFF', 'class' => 'text-primary-600'],
                      ['hex' => '#22C55E', 'class' => 'text-success-main'],
                      ['hex' => '#EAB308', 'class' => 'text-warning-main'],
                      ['hex' => '#EF4444', 'class' => 'text-danger-main'],
                      ['hex' => '#3B82F6', 'class' => 'text-info-main'],
                      ['hex' => '#FFFFFF', 'class' => 'text-primary-light'],
                    ];
                  @endphp
          <div class="card-body">
              <div class="table-responsive scroll-sm">
                <table class="table bordered-table mb-0" id="tabla_identidad" data-page-length='10'>
                  <thead>
                      <tr>
                            <th style="display:none;">Id_Dominio</th>
                            <th scope="col">Nombre Dominio</th>
                            <th scope="col">Datos de ubicacion o Direccion</th>
                            <th scope="col">Imagen</th>
                            <th scope="col">Color</th>
                      
                      </tr>
                  </thead>
                  <tbody>
                     @foreach ($dominios as $dom)
                      <tr>
                        <td id="id_dominio" style="display:none;">{{ $dom->id_dominio}}</td>
                        <td id="nombre_dominio">{{ $dom->nombre}}</td>
                        <td>
                      
                          <textarea class="form-control radius-8 direccion"
                            name="direcciones[{{ $dom->id_dominio }}]"
                            rows="4" placeholder="Ingresa Datos de ubicacion o Direccion">{{ old('direcciones.'.$dom->id_dominio, $dom->direccion ?? '') }}</textarea>
                        </td>
                        <td id="imagen">
                          <div class="mb-24 mt-16">
                                 
                                <div class="avatar-upload">
                                   <div class="avatar-edit position-absolute bottom-0 end-0 me-24 mt-16 z-1 cursor-pointer">
                                     
                                        
                                        <input type="file" id="imagen_{{ $dom->id_dominio }}" name="imagenes[{{ $dom->id_dominio }}]" class="input-imagen" accept=".png, .jpg, .jpeg" hidden>
                                        
                                   
                                        <label for="imagen_{{ $dom->id_dominio }}" class="w-32-px h-32-px d-flex justify-content-center align-items-center bg-primary-50 text-primary-600 border border-primary-600 bg-hover-primary-100 text-lg rounded-circle">
                                            <iconify-icon icon="solar:camera-outline" class="icon"></iconify-icon>
                                        </label>

                                        
                                    </div>
                                    @php
                                        $imgRel = $dom->imagen ?? null;
                                        $baseUrl = ($imgRel && file_exists(public_path($imgRel)))
                                            ? asset($imgRel)
                                            : asset('images/placeholder.jpg');

                                        // ✅ Versión súper simple para evitar caché:
                                        $imgUrl = $baseUrl . '?v=' . time();
                                    @endphp

                                    <div class="hover-scale-img border radius-16 overflow-hidden p-8" style="width:160px;">
                                        <a href="{{ $imgUrl }}" class="popup-img w-100 h-100 d-flex radius-12 overflow-hidden">
                                            <img class="avatar-img"
                                                src="{{ $imgUrl }}"
                                                alt="Imagen"
                                                class="hover-scale-img__img w-100 h-100 object-fit-cover radius-12"
                                                style="object-fit: cover; height:160px;">
                                        </a>
                                    </div>
                                </div>
                            </div>


                                                                                  



                        </td>
                        @php
                          $hex = old('color_texto', $dom->color ?? '');
                        @endphp
                        <td id="color" style="min-width:180px;">
                            <div class="d-flex align-items-center gap-8 mb-8">
                              {{-- Swatch con color de BD --}}
                              <span class="color-swatch border radius-4"
                                    style="width:18px;height:18px;display:inline-block; background: {{ $hex ?: 'transparent' }};">
                              </span>

                              {{-- Preview mostrando el HEX de BD --}}
                              <input type="text"
                                class="form-control form-control-sm radius-4 color_texto_preview"
                                value="{{ $hex }}"
                                placeholder="#------"
                                readonly
                                style="
                                  max-width:150px;
                                  font-size:20px;
                                  height:35px;
                                  color: {{ $hex ?: '#000' }};
                                  border-color: {{ $hex ?: '#ced4da' }};
                                  {{ strtoupper($hex) === '#FFFFFF' ? 'background:#111827; border-color:#111827;' : '' }}
                                ">
                            </div>

                            {{-- Hidden con el color de BD --}}
                            <input type="hidden"
                                  class="color_texto"
                                  name="color_texto"
                                  value="{{ $hex }}">

                            {{-- Paleta en puntitos + marcar el guardado como activo --}}
                            <div class="d-flex flex-wrap gap-6 paleta-texto">
                              @foreach($paletaTexto as $c)
                                <button type="button"
                                  class="palette-item border radius-4 {{ strtoupper($c['hex']) === strtoupper($hex) ? 'active border-primary-600' : '' }}"
                                  data-hex="{{ $c['hex'] }}"
                                  data-class="{{ $c['class'] }}"
                                  title="{{ $c['hex'] }}"
                                  style="width:20px;height:20px;padding:0;background:{{ $c['hex'] }};">
                                </button>
                              @endforeach
                            </div>
                          </td>
                      </tr>
                     @endforeach
                          
                  </tbody>
                </table>
              </div>
          </div>
          <div class="card-body p-24">
             
                
              <input type="hidden" name="datos" id="datos">
                <div class="d-flex align-items-center justify-content-center gap-3 mt-4">
                    <button type="button"
                            onclick="window.location.href='{{ route('dominios.index') }}'"
                            class="border border-danger-600 bg-hover-danger-200 text-danger-600 text-md px-56 py-11 radius-8">
                        Cancelar
                    </button>
                    <button type="submit" onclick="CapturarDatosTabla()"
                            class="btn btn-primary border border-primary-600 text-md px-56 py-12 radius-8">
                        Guardar
                    </button>
                </div>
            </div>
            
          
        </div>
      </form>
    </div>

@endsection

@section('scripts')
<script type="text/javascript" src="{{ asset('assets\js\IdentidadDominios.js') }}"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {

  // ✅ Pintar por defecto por cada fila según el hidden
  document.querySelectorAll('#tabla_identidad tbody tr').forEach(tr => {
    const hidden = tr.querySelector('.color_texto');
    const preview = tr.querySelector('.color_texto_preview');
    const swatch = tr.querySelector('.color-swatch');
    const palette = tr.querySelector('.paleta-texto');

    const hex = (hidden?.value || '').trim();
    if (!hex) return;

    if (preview) {
      preview.value = hex;
      preview.style.setProperty('color', hex, 'important');
      preview.style.setProperty('border-color', hex, 'important');

      if (hex.toUpperCase() === '#FFFFFF') {
        preview.style.setProperty('background-color', '#111827', 'important');
        preview.style.setProperty('border-color', '#111827', 'important');
      } else {
        preview.style.removeProperty('background-color');
      }
    }

    if (swatch) swatch.style.background = hex;

    // marcar activo el dot correspondiente
    if (palette) {
      palette.querySelectorAll('.palette-item').forEach(b => {
        b.classList.toggle('active', (b.dataset.hex || '').toUpperCase() === hex.toUpperCase());
      });
    }
  });

  // ✅ Tu listener de click (el que ya tienes)
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.palette-item');
    if (!btn) return;

    const tr = btn.closest('tr');
    const palette = btn.closest('.paleta-texto');

    const hidden = tr.querySelector('.color_texto');
    const preview = tr.querySelector('.color_texto_preview');
    const swatch = tr.querySelector('.color-swatch');

    palette.querySelectorAll('.palette-item').forEach(b => {
      b.classList.remove('active', 'border-primary-600');
    });

    btn.classList.add('active', 'border-primary-600');

    const hex = btn.dataset.hex;
    const cls = btn.dataset.class;

    if (hidden) hidden.value = hex;

    if (preview) {
      preview.value = `${hex} (${cls})`;
      preview.style.setProperty('color', hex, 'important');
      preview.style.setProperty('border-color', hex, 'important');

      if (hex.toUpperCase() === '#FFFFFF') {
        preview.style.setProperty('background-color', '#111827', 'important');
        preview.style.setProperty('border-color', '#111827', 'important');
      } else {
        preview.style.removeProperty('background-color');
      }
    }

    if (swatch) swatch.style.background = hex;
  });

});
</script>
<script src="{{ asset('assets/js/lib/file-upload.js') }}"></script>

<style>
  .tpl-selected { border:2px solid #3b82f6 !important; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
</style>

<script>
 
  // Tu listener de imagen (queda igual)
  document.getElementById('imagen')?.addEventListener('change', function (e) {
    const file = e.target.files && e.target.files[0];
    if (!file) return;

    const url = URL.createObjectURL(file);
    const imgTag = document.getElementById('avatar-img');
    const link = document.querySelector('.popup-img');

    if (imgTag) {
      imgTag.src = url;
    }
    if (link) {
      link.href = url;
    }

    const img = new Image();
    img.onload = () => URL.revokeObjectURL(url);
    img.src = url;
  });document.addEventListener('change', (e) => {
  const input = e.target;
  if (!input.classList.contains('input-imagen')) return;

  const file = input.files && input.files[0];
  if (!file) return;

  const tr = input.closest('tr');
  const imgTag = tr.querySelector('.avatar-img');
  const link = tr.querySelector('.popup-img');

  const url = URL.createObjectURL(file);

  if (imgTag) imgTag.src = url;
  if (link) link.href = url;

  const img = new Image();
  img.onload = () => URL.revokeObjectURL(url);
  img.src = url;
});
</script>

<script src="{{ asset('assets/js/lib/magnifc-popup.min.js') }}"></script>

<script>
    $('.popup-img').magnificPopup({
        type: 'image',
        gallery: { enabled: true }
    });
</script>

@endsection

    
  

  