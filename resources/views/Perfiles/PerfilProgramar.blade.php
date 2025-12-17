@extends('layouts.master')

@section('titulo', 'Programar Publicación en Facebook')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Programar publicación en {{ $perfil->fb_page_name ?? $perfil->nombre }}</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ route('inicio') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Programar publicación</li>
        </ul>
    </div>

    <div class="card h-100 p-0 radius-12">
        <div class="card-body p-24">
            <div class="row">
              {{-- COLUMNA DERECHA: IA GLOBAL + HISTORIAL --}}
                <div class="col-lg-4 col-md-12 mt-4 mt-lg-0">
                    <div class="card border h-100">
                        <div class="card-body">
                            <h6 class="fw-semibold mb-12">Generador de contenido con IA</h6>
                            <p class="text-xs text-primary-light mb-12">
                                Escribe una idea, genera textos con IA y luego copia el que quieras al contenido
                                de la publicación que tengas seleccionada (último campo de contenido donde hiciste clic).
                            </p>

                            {{-- INPUT IDEA PARA IA --}}
                            <div class="mb-3">
                                <label for="idea_ia"
                                       class="form-label fw-semibold text-primary-light text-sm mb-8">
                                    Texto de ejemplo para mejorar con IA
                                    <span class="text-muted">(opcional)</span>
                                </label>
                                <textarea id="idea_ia" rows="4"
                                          class="form-control radius-8"
                                          placeholder="Ej: Promo en combos familiares este fin de semana, 2x1 en hamburguesas, ambiente familiar y delivery disponible."></textarea>
                                <small class="text-primary-light d-block mt-6">
                                    La IA generará diferentes textos. Los verás abajo y podrás elegir cuál usar.
                                </small>

                                <button type="button"
                                        id="btn-generar-ia"
                                        class="btn btn-outline-primary border border-primary-600 text-sm mt-10 w-100">
                                    Generar texto con IA
                                </button>
                            </div>

                            {{-- HISTORIAL DE TEXTOS IA --}}
                            <div class="mb-2">
                                <label class="form-label text-xs">Historial de textos generados</label>
                                <div id="ia-suggestions-empty" class="text-xs text-muted">
                                    Aún no has generado ningún texto. Usa el botón
                                    <strong>“Generar texto con IA”</strong>.
                                </div>
                                <div id="ia-suggestions-list"
                                     class="mt-2 d-flex flex-column gap-2">
                                    {{-- Aquí se irán agregando las sugerencias --}}
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
                {{-- COLUMNA PRINCIPAL: FORMULARIO MULTI-POST --}}
                <div class="col-lg-8 col-md-12">
                    <div class="card border mb-0">
                        <div class="card-body">

                            <form method="POST"
                                  action="{{ route('perfilprogramarya', $perfil->id) }}"
                                  enctype="multipart/form-data"
                                  id="multi-post-form">
                                @csrf

                                <div id="posts-wrapper" class="d-flex flex-column gap-4">
                                    <div class="post-block card border p-3" data-index="0">
                                        <h6 class="mb-2">
                                            Publicación #<span class="post-number">1</span>
                                        </h6>

                                        {{-- IMÁGENES + PREVIEW --}}
                                        <div class="mb-3">
                                            <label class="form-label">Imágenes (varias)</label>
                                            <input type="file"
                                                   name="posts[0][images][]"
                                                   class="form-control post-images"
                                                   accept="image/*"
                                                   multiple>
                                            <small class="text-primary-light d-block mt-1">
                                                Puedes seleccionar varias imágenes. Abajo verás una vista previa.
                                            </small>

                                            <div class="image-preview d-flex flex-wrap gap-2 mt-2"></div>
                                        </div>

                                        {{-- CONTENIDO FINAL QUE SE ENVÍA --}}
                                        <div class="mb-3">
                                            <label class="form-label">Contenido</label>
                                            <textarea name="posts[0][message]"
                                                      class="form-control post-message"
                                                      rows="3"
                                                      placeholder="Escribe aquí el contenido o luego copia uno generado por la IA (columna derecha)..."></textarea>
                                            <small class="text-primary-light d-block mt-1">
                                                Este es el texto que se enviará a Facebook para esta publicación.
                                            </small>
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label class="form-label">Fecha</label>
                                                <input type="date"
                                                       name="posts[0][schedule_date]"
                                                       class="form-control schedule-date">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Hora</label>
                                                <input type="time"
                                                       name="posts[0][schedule_time]"
                                                       class="form-control schedule-time">
                                            </div>
                                        </div>

                                        <input type="hidden"
                                               name="posts[0][client_tz]"
                                               class="client-tz">
                                        <input type="hidden"
                                               name="posts[0][scheduled_epoch]"
                                               class="scheduled-epoch"><!-- opcional -->

                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger mt-2 remove-post d-none">
                                            Eliminar
                                        </button>
                                    </div>
                                </div>

                                <div class="mt-3 d-flex gap-2 justify-content-between">
                                    <button type="button"
                                            id="add-post"
                                            class="btn btn-outline-primary">
                                        Añadir otra publicación
                                    </button>
                                    <button type="submit"
                                            class="btn btn-primary">
                                        Programar publicaciones
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
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
  const wrapper = document.getElementById('posts-wrapper');
  const addBtn  = document.getElementById('add-post');
  const form    = document.getElementById('multi-post-form');

  // IA (columna derecha)
  const iaUrl   = "{{ route('perfilgenerar', $perfil->id) }}";
  const ideaIA  = document.getElementById('idea_ia');
  const btnIA   = document.getElementById('btn-generar-ia');
  const iaList  = document.getElementById('ia-suggestions-list');
  const iaEmpty = document.getElementById('ia-suggestions-empty');

  let iaCounter = 0;              // contador global de sugerencias
  let currentMessageEl = null;    // último textarea de contenido enfocado

  // Prefill el primer bloque (fecha / hora)
  prefillBlock(wrapper.querySelector('.post-block'));

  // Track del textarea de contenido "activo"
  wrapper.addEventListener('focusin', (e) => {
    if (e.target.classList.contains('post-message')) {
      currentMessageEl = e.target;
    }
  });

  // Añadir nueva publicación
  addBtn.addEventListener('click', () => {
    const blocks = wrapper.querySelectorAll('.post-block');
    const nextIndex = blocks.length;

    const tpl = blocks[0].cloneNode(true);
    tpl.dataset.index = nextIndex;

    // limpiar valores y actualizar name="posts[n][...]"
    tpl.querySelectorAll('input, textarea').forEach(el => {
      if (el.type === 'file') {
        el.value = '';
      } else {
        el.value = '';
      }
      const name = el.getAttribute('name');
      if (name) {
        el.setAttribute('name', name.replace(/\[\d+\]/, `[${nextIndex}]`));
      }
    });

    // limpiar preview de imágenes del clon
    const preview = tpl.querySelector('.image-preview');
    if (preview) preview.innerHTML = '';

    tpl.querySelector('.post-number').textContent = String(nextIndex + 1);
    tpl.querySelector('.remove-post').classList.remove('d-none');

    wrapper.appendChild(tpl);
    prefillBlock(tpl);
  });

  // Eliminar publicación
  wrapper.addEventListener('click', (e) => {
    if (!e.target.classList.contains('remove-post')) return;
    const blocks = wrapper.querySelectorAll('.post-block');
    if (blocks.length === 1) return;

    e.target.closest('.post-block').remove();

    // reindex
    wrapper.querySelectorAll('.post-block').forEach((block, i) => {
      block.dataset.index = i;
      block.querySelector('.post-number').textContent = String(i + 1);
      block.querySelectorAll('input, textarea').forEach(el => {
        const name = el.getAttribute('name');
        if (name) {
          el.setAttribute('name', name.replace(/\[\d+\]/, `[${i}]`));
        }
      });
    });
  });

  // PREVIEW DE IMÁGENES POR BLOQUE
  wrapper.addEventListener('change', (e) => {
    if (!e.target.classList.contains('post-images')) return;

    const input   = e.target;
    const block   = input.closest('.post-block');
    const preview = block.querySelector('.image-preview');

    if (!preview) return;

    // limpiar previews anteriores
    preview.innerHTML = '';

    const files = input.files;
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

  // Generar texto con IA (global, para el textarea que tenga el "focus" más reciente)
  if (btnIA) {
    btnIA.addEventListener('click', async () => {
      const idea = (ideaIA?.value || '').trim();

      if (!idea) {
        alert('Escribe primero una idea para que la IA pueda generar contenido.');
        ideaIA?.focus();
        return;
      }

      try {
        const response = await fetch(iaUrl, {
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
        const textoGenerado = data.texto || data.descripcion || data.descripcionMejorada || '';

        if (!textoGenerado) {
          alert('La IA no devolvió un texto válido. Intenta de nuevo.');
          return;
        }

        iaCounter++;

        if (iaEmpty) {
          iaEmpty.style.display = 'none';
        }

        const safeText = textoGenerado
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;');

        const item = document.createElement('div');
        item.className = 'ia-suggestion-item border radius-8 p-8 mb-2 bg-neutral-50';
        item.dataset.text = textoGenerado;

        item.innerHTML = `
          <div class="d-flex justify-content-between align-items-center mb-6">
              <span class="text-xs fw-semibold text-primary-600">Opción #${iaCounter}</span>
              <button type="button" class="btn btn-xs btn-outline-secondary btn-copy-suggestion">
                  Usar en contenido
              </button>
          </div>
          <div class="text-xs"
               style="white-space: pre-wrap; max-height: 180px; overflow-y: auto;">
              ${safeText}
          </div>
        `;

        iaList.prepend(item);

      } catch (error) {
        console.error(error);
        alert('No se pudo generar el texto con IA. Intenta de nuevo en unos minutos.');
      }
    });
  }

  // Copiar sugerencia al textarea de contenido activo
  iaList.addEventListener('click', (e) => {
    if (!e.target.classList.contains('btn-copy-suggestion')) return;

    if (!currentMessageEl) {
      alert('Primero haz clic dentro del contenido de la publicación donde quieres usar este texto.');
      return;
    }

    const item  = e.target.closest('.ia-suggestion-item');
    if (!item) return;

    const texto = item.dataset.text || '';
    if (!texto) {
      alert('No se encontró el texto de esta sugerencia.');
      return;
    }

    currentMessageEl.value = texto;
    currentMessageEl.focus();
    currentMessageEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  // Al enviar: setear TZ y (opcional) epoch por bloque
  form.addEventListener('submit', () => {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    wrapper.querySelectorAll('.post-block').forEach(block => {
      const dateEl = block.querySelector('.schedule-date');
      const timeEl = block.querySelector('.schedule-time');
      const tzEl   = block.querySelector('.client-tz');
      const epEl   = block.querySelector('.scheduled-epoch');

      if (tzEl) tzEl.value = tz;

      if (dateEl?.value && timeEl?.value && epEl) {
        const [Y, M, D] = dateEl.value.split('-').map(Number);
        const [H, I]    = timeEl.value.split(':').map(Number);
        const local = new Date(Y, (M-1), D, H, I, 0, 0);
        epEl.value = Math.floor(local.getTime() / 1000);
      }
    });
  }, { once:true });

  function prefillBlock(block) {
    const d = new Date();
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const dd = String(d.getDate()).padStart(2,'0');
    const d2 = new Date(d.getTime() + 20*60*1000);
    const hh = String(d2.getHours()).padStart(2,'0');
    const mi = String(d2.getMinutes()).padStart(2,'0');

    const dateEl = block.querySelector('.schedule-date');
    const timeEl = block.querySelector('.schedule-time');
    if (dateEl && !dateEl.value) dateEl.value = `${yyyy}-${mm}-${dd}`;
    if (timeEl && !timeEl.value) timeEl.value = `${hh}:${mi}`;
  }
});
</script>
@endsection