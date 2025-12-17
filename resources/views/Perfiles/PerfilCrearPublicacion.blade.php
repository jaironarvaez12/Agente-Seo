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
            <div class="row">
                {{-- COLUMNA DERECHA: HISTORIAL DE TEXTOS IA --}}
                <div class="col-lg-4 col-md-12 mt-4 mt-lg-0">
                    <div class="card border h-100">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-12">Historial de textos generados por IA</h6>
                            <p class="text-xs text-primary-light mb-12">
                                Cada vez que generes un texto, se guardará aquí. Puedes probar varios y luego elegir cuál usar.
                            </p>
                            <div id="ia-suggestions-empty" class="text-xs text-muted">
                                Aún no has generado ningún texto. Usa el botón <strong>“Generar texto con IA”</strong>.
                            </div>

                            {{-- APARTADO: TEXTO DE EJEMPLO PARA IA --}}
                            <div class="mb-20">
                                <label for="idea_ia" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Texto de ejemplo para mejorar con IA <span class="text-muted">(opcional)</span>
                                </label>
                                <textarea id="idea_ia" rows="4"
                                          class="form-control radius-8"
                                          placeholder="Ej: Promo en combos familiares este fin de semana, 2x1 en hamburguesas, ambiente familiar y delivery disponible."></textarea>
                                <small class="text-primary-light d-block mt-6">
                                    Escribe una idea corta. La IA generará diferentes textos y los verás en el historial de abajo.
                                </small>

                                <button type="button"
                                        id="btn-generar-ia"
                                        class="btn btn-outline-primary border border-primary-600 text-sm mt-10">
                                    Generar texto con IA
                                </button>
                            </div>

                            <div id="ia-suggestions-list" class="mt-12 d-flex flex-column gap-2">
                                {{-- Aquí se irán agregando las sugerencias --}}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- COLUMNA PRINCIPAL: FORMULARIO --}}
                <div class="col-lg-8 col-md-12">
                    <div class="card border mb-0">
                        <div class="card-body">
                            <form method="POST" action="{{ route('perfilpublicarya', $perfil->id) }}" enctype="multipart/form-data">
                                @csrf

                                {{-- IMÁGENES + PREVIEW --}}
                                <div class="mb-20">
                                    <label class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Imágenes (puedes seleccionar varias)
                                    </label>
                                    <input type="file"
                                           name="images[]"
                                           class="form-control radius-8 post-images"
                                           accept="image/*"
                                           multiple>
                                    <small class="text-primary-light d-block mt-6">
                                        Formatos comunes (jpg, png) – máx 5MB por archivo.
                                    </small>

                                    {{-- contenedor de vista previa --}}
                                    <div id="image-preview"
                                         class="image-preview d-flex flex-wrap gap-2 mt-2"></div>
                                </div>

                                {{-- TEXTO FINAL DE LA PUBLICACIÓN --}}
                                <div class="mb-20">
                                    <label for="message" class="form-label fw-semibold text-primary-light text-sm mb-8">
                                        Contenido de la publicación
                                    </label>
                                    <textarea name="message" id="message" rows="5"
                                              class="form-control radius-8"
                                              placeholder="Escribe el texto de tu publicación aquí, o selecciona uno del historial de la derecha."></textarea>
                                    <small class="text-primary-light d-block mt-6">
                                        Este es el texto final que se enviará a Facebook.
                                    </small>
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

            </div> {{-- row --}}
        </div>
    </div>
</div>

{{-- SCRIPT PARA LLAMAR A LA IA, GESTIONAR HISTORIAL Y VISTA PREVIA --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const btnGenerarIA   = document.getElementById('btn-generar-ia');
        const ideaIA         = document.getElementById('idea_ia');
        const message        = document.getElementById('message');
        const listContainer  = document.getElementById('ia-suggestions-list');
        const emptyLabel     = document.getElementById('ia-suggestions-empty');

        // input y contenedor de preview de imágenes
        const fileInput      = document.querySelector('.post-images');
        const preview        = document.getElementById('image-preview');

        let iaCounter = 0; // contador de sugerencias

        // ====== VISTA PREVIA DE IMÁGENES ======
        if (fileInput && preview) {
            fileInput.addEventListener('change', function () {
                preview.innerHTML = '';

                const files = fileInput.files;
                if (!files || !files.length) return;

                Array.from(files).forEach(file => {
                    if (!file.type.startsWith('image/')) return;

                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.alt = file.name;
                    img.style.width = '70px';
                    img.style.height = '70px';
                    img.style.objectFit = 'cover';
                    img.classList.add('radius-8', 'border');

                    preview.appendChild(img);
                });
            });
        }

        // ====== IA: GENERAR TEXTO Y GUARDAR EN HISTORIAL ======
        if (!btnGenerarIA) return;

        btnGenerarIA.addEventListener('click', async function () {
            const idea = ideaIA.value.trim();

            if (!idea) {
                alert('Por favor escribe primero una idea o texto de ejemplo para que la IA lo pueda mejorar.');
                ideaIA.focus();
                return;
            }

            try {
                const response = await fetch("{{ route('perfilgenerar', $perfil->id) }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ prompt: idea })
                });

                if (!response.ok) {
                    throw new Error('Error HTTP: ' + response.status);
                }

                const data = await response.json();

                const textoGenerado = data.texto ?? data.descripcion ?? data.descripcionMejorada ?? '';

                if (!textoGenerado) {
                    alert('La IA no devolvió un texto válido. Intenta de nuevo.');
                    return;
                }

                iaCounter++;

                // Ocultamos el mensaje vacío al menos la primera vez
                if (emptyLabel) {
                    emptyLabel.style.display = 'none';
                }

                // Escapar < >
                const safeText = textoGenerado
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');

                // Crear tarjeta en el historial (texto completo pero scrolleable)
                const item = document.createElement('div');
                item.className = 'ia-suggestion-item border radius-8 p-8 mb-8 bg-neutral-50';
                item.dataset.text = textoGenerado;

                item.innerHTML = `
                    <div class="d-flex justify-content-between align-items-center mb-6">
                        <span class="text-xs fw-semibold text-primary-600">Opción #${iaCounter}</span>
                        <button type="button" class="btn btn-xs btn-outline-secondary btn-copy-suggestion">
                            Copiar al contenido
                        </button>
                    </div>
                    <div class="text-xs"
                         style="white-space: pre-wrap; max-height: 180px; overflow-y: auto;">
                        ${safeText}
                    </div>
                `;

                // La más nueva arriba
                listContainer.prepend(item);
            } catch (error) {
                console.error(error);
                alert('No se pudo generar el texto con IA. Intenta de nuevo en unos minutos.');
            }
        });

        // Delegación de eventos: copiar una sugerencia al textarea principal
        listContainer.addEventListener('click', function (e) {
            if (!e.target.classList.contains('btn-copy-suggestion')) return;

            const parent = e.target.closest('.ia-suggestion-item');
            if (!parent) return;

            const texto = parent.dataset.text || '';

            if (!texto) {
                alert('No se encontró el texto de esta sugerencia.');
                return;
            }

            message.value = texto;
            message.focus();
            message.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
</script>
@endsection