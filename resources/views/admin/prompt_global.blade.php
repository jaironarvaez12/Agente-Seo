{{-- resources/views/admin/prompt_global.blade.php --}}

@extends('layouts.master')

@section('titulo', 'Prompt Global')

@section('contenido')
@include('mensajes.MsjExitoso')
@include('mensajes.MsjError')
@include('mensajes.MsjAlerta')
@include('mensajes.MsjValidacion')

<div class="dashboard-main-body">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-24">
        <h6 class="fw-semibold mb-0">Prompt Global (IA)</h6>
        <ul class="d-flex align-items-center gap-2">
            <li class="fw-medium">
                <a href="{{ url('/') }}" class="d-flex align-items-center gap-1 hover-text-primary">
                    <iconify-icon icon="solar:home-smile-angle-outline" class="icon text-lg"></iconify-icon>
                    Inicio
                </a>
            </li>
            <li>-</li>
            <li class="fw-medium">Prompt Global</li>
        </ul>
    </div>

    <div class="card basic-data-table">
        <div class="card-header">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h6 class="mb-0">Editar Prompt Global</h6>
                    <small class="text-secondary-light">
                        Este prompt se usa para <b>todos los dominios</b>. Se reemplazan variables tipo
                        <code>@verbatim{{KEYWORD}}@endverbatim</code>,
                        <code>@verbatim{{SCHEMA_JSON}}@endverbatim</code>, etc.
                    </small>
                </div>

                <div class="d-flex gap-2">
                    <a href="{{ url('/') }}" class="btn btn-light text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
                        <iconify-icon icon="material-symbols:arrow-back-rounded" class="icon text-xl line-height-1"></iconify-icon>
                        Volver
                    </a>
                </div>
            </div>
        </div>

        <div class="card-body">
            {{-- ✅ Aviso: qué se puede editar --}}
            <div class="alert alert-info mb-20">
                <div class="fw-semibold mb-6">Recomendación</div>
                <div class="text-secondary-light">
                    Modifique solo el contenido entre:
                    <b>### INICIO_EDITABLE</b> y <b>### FIN_EDITABLE</b>.
                    No borres placeholders como <b>@verbatim{{SCHEMA_JSON}}@endverbatim</b>, <b>@verbatim{{KEYWORD}}@endverbatim</b>, etc.
                </div>
            </div>

            <form method="POST" action="{{ url('guardarprompt') }}">
                @csrf

                <div class="row">
                    <div class="col-12 col-xl-8">
                        <label class="form-label fw-semibold">Prompt Global</label>

                        <textarea
                            id="promptTextarea"
                            name="prompt"
                            rows="30"
                            class="form-control"
                            placeholder="Pega aquí tu prompt con placeholders como &#123;&#123;KEYWORD&#125;&#125;, &#123;&#123;TIPO&#125;&#125;, &#123;&#123;SCHEMA_JSON&#125;&#125;..."
                        >{{ old('prompt', $prompt ?? '') }}</textarea>

                        <div class="d-flex flex-wrap gap-2 mt-12">
                            <button type="submit" class="btn btn-primary text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
                                <iconify-icon icon="material-symbols:save-outline" class="icon text-xl line-height-1"></iconify-icon>
                                Guardar
                            </button>

                            {{-- ✅ Botón restaurar recomendado --}}
                            <button type="button" id="btnRestaurar" class="btn btn-warning text-sm btn-sm px-12 py-12 radius-8 d-flex align-items-center gap-2">
                                <iconify-icon icon="material-symbols:restart-alt" class="icon text-xl line-height-1"></iconify-icon>
                                Restaurar prompt recomendado
                            </button>
                        </div>
                    </div>

                    <div class="col-12 col-xl-4 mt-24 mt-xl-0">
                        <div class="card radius-8 border">
                            <div class="card-body">
                                <h6 class="fw-semibold mb-12">Placeholders disponibles</h6>

                                <div class="mb-12">
                                    <div class="fw-medium mb-6">Básicos</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-info-focus text-info-600 border border-info-main">@verbatim{{KEYWORD}}@endverbatim</span>
                                        <span class="badge bg-info-focus text-info-600 border border-info-main">@verbatim{{TIPO}}@endverbatim</span>
                                        <span class="badge bg-info-focus text-info-600 border border-info-main">@verbatim{{VARIATION}}@endverbatim</span>
                                    </div>
                                </div>

                                <div class="mb-12">
                                    <div class="fw-medium mb-6">Brief</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-warning-focus text-warning-600 border border-warning-main">@verbatim{{BRIEF_ANGLE}}@endverbatim</span>
                                        <span class="badge bg-warning-focus text-warning-600 border border-warning-main">@verbatim{{BRIEF_TONE}}@endverbatim</span>
                                        <span class="badge bg-warning-focus text-warning-600 border border-warning-main">@verbatim{{BRIEF_AUDIENCE}}@endverbatim</span>
                                        <span class="badge bg-warning-focus text-warning-600 border border-warning-main">@verbatim{{BRIEF_CTA}}@endverbatim</span>
                                    </div>
                                </div>

                                <div class="mb-12">
                                    <div class="fw-medium mb-6">Plan / No repetir</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-success-focus text-success-600 border border-success-main">@verbatim{{PLAN_TEXT}}@endverbatim</span>
                                        <span class="badge bg-success-focus text-success-600 border border-success-main">@verbatim{{NO_REPETIR_HEADINGS}}@endverbatim</span>
                                        <span class="badge bg-success-focus text-success-600 border border-success-main">@verbatim{{NO_REPETIR_TITLES}}@endverbatim</span>
                                        <span class="badge bg-success-focus text-success-600 border border-success-main">@verbatim{{NO_REPETIR_CORPUS}}@endverbatim</span>
                                        <span class="badge bg-success-focus text-success-600 border border-success-main">@verbatim{{ALREADY_STR}}@endverbatim</span>
                                    </div>
                                </div>

                                <div class="mb-12">
                                    <div class="fw-medium mb-6">Tokens / Esquema</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <span class="badge bg-danger-focus text-danger-600 border border-danger-main">@verbatim{{EDITOR_LIST}}@endverbatim</span>
                                        <span class="badge bg-danger-focus text-danger-600 border border-danger-main">@verbatim{{PLAIN_LIST}}@endverbatim</span>
                                        <span class="badge bg-danger-focus text-danger-600 border border-danger-main">@verbatim{{SCHEMA_JSON}}@endverbatim</span>
                                    </div>
                                </div>

                                <div class="alert alert-info mb-0">
                                    <div class="fw-semibold mb-6">Importante</div>
                                    <div class="text-secondary-light">
                                        Asegúrate de incluir <b>@verbatim{{SCHEMA_JSON}}@endverbatim</b>.
                                        Sin eso, la IA no sabrá qué keys devolver.
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btnRestaurar');
    const ta  = document.getElementById('promptTextarea');

    
    const defaultPrompt = @json((string)($defaultPrompt ?? ''));

    if (btn && ta) {
        btn.addEventListener('click', () => {
            if (!confirm('¿Quieres reemplazar el prompt actual por el recomendado? Se perderán cambios no guardados.')) return;
            ta.value = defaultPrompt;
        });
    }
});
</script>
@endsection
