<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Ajuste_Ia;
use Illuminate\Support\Facades\Cache;

class PromptGlobalController extends Controller
{
    public function editar()
    {
        $guardado = (string) optional(
            Ajuste_Ia::where('clave', 'deepseek_prompt_global')->first()
        )->valor;

        $defaultPrompt = (string) $this->promptRecomendado();

        // ✅ si no hay guardado, usa recomendado
        $prompt = trim($guardado) !== '' ? $guardado : $defaultPrompt;

        return view('admin.prompt_global', compact('prompt', 'defaultPrompt'));
    }

    public function guardar(Request $request)
    {
        $request->validate([
            'prompt' => 'required|string|min:200',
        ]);

        $prompt = (string) $request->prompt;

        // ✅ Validación de placeholders mínimos para no romper el Job
        $obligatorios = [
            '{{SCHEMA_JSON}}',
            '{{KEYWORD}}',
            '{{TIPO}}',
            '{{EDITOR_LIST}}',
            '{{PLAIN_LIST}}',
        ];

        foreach ($obligatorios as $x) {
            if (!str_contains($prompt, $x)) {
                return back()
                    ->with('error', "El prompt NO es válido. Falta el placeholder obligatorio: $x")
                    ->withInput();
            }
        }

        Ajuste_Ia::updateOrCreate(
            ['clave' => 'deepseek_prompt_global'],
            ['valor' => $prompt]
        );

        Cache::forget('ajuste:deepseek_prompt_global');

      return back()->withSuccess('El Prompt Global se actualizó exitosamente');
    }

    private function promptRecomendado(): string
    {
        return <<<TXT
Devuelve SOLO JSON válido (sin markdown). RESPUESTA MINIFICADA.
Idioma: ES.

VARIATION (NO imprimir): {{VARIATION}}

### INICIO_EDITABLE
Rol:
Eres un Redactor SEO experto en conversión. Escribes como una landing real: propuesta de valor + beneficios + confianza + pasos + objeciones + CTA.
Debe funcionar para cualquier industria. No asumas un sector fijo.

PALABRAS CLAVE:
- Usa la keyword principal como eje.
- Deriva 4–8 variantes/LSI/long-tail/entidades relacionadas.
- NO imprimas la lista; aplícala natural.
- Si la keyword incluye ciudad/servicio ("X en Y"), úsalo. Si no, NO inventes ciudad.

ESTILO:
- Frases claras, sin relleno.
- Beneficios concretos.
- Refuerza confianza sin inventar datos.
- Tono editorial/landing humano.
- Evita lenguaje de consultoría ("herramienta estratégica", "valor percibido", etc.).

REGLAS ESTRICTAS:
- Devuelve EXACTAMENTE las keys del ESQUEMA (no agregues ni quites).
- PROHIBIDO valores vacíos: nada de "" ni null.
- TODOS los valores deben ser STRING.
- ❌ NO uses headings como “Introducción”, “Conclusión”, “¿Qué es...?”.
- ❌ NO repitas headings del bloque NO REPETIR (HEADINGS).
- PROHIBIDO imprimir o copiar el PLAN (no uses "SECTION_1:" ni "Enfoque:" ni "Tema:").
- PROHIBIDO usar la palabra "Enfoque:".
- PROHIBIDO empezar párrafos con "Tratamos..." / "Aquí aterrizamos..." / "Convertimos..." / "Aplicamos...".
- PROHIBIDO usar corchetes [] (nada de [CTA]).
- PROHIBIDO listas con guiones "-" o viñetas (sin bullets).
- PROHIBIDO promesas tipo "medible", "garantizado", "desde el primer día" si no hay datos.
- Máximo 1 <br> por valor (solo si aporta claridad).

FORMATO:
- SEO_TITLE (si existe): ≤ 60 caracteres, incluir keyword principal, enfoque comercial.
- Headings: 6–14 palabras.
- Párrafos: 60–140 palabras, 3–7 frases, naturales. NO uses <p>. Puedes usar <strong> y <br>.
- Keys plain: solo texto plano.
### FIN_EDITABLE

Contexto:
- Keyword principal: {{KEYWORD}}
- Tipo: {{TIPO}}

BRIEF:
- Ángulo: {{BRIEF_ANGLE}}
- Tono: {{BRIEF_TONE}}
- Público: {{BRIEF_AUDIENCE}}
- CTA: {{BRIEF_CTA}}

PLAN DE TEMAS (SOLO GUIA INTERNA - NO IMPRIMIR NI COPIAR):
{{PLAN_TEXT}}

NO REPETIR (HEADINGS existentes / analisis):
{{NO_REPETIR_HEADINGS}}

NO REPETIR TÍTULOS:
{{NO_REPETIR_TITLES}}

NO REPETIR TEXTOS:
{{NO_REPETIR_CORPUS}}

YA USADOS (evita repetir estos títulos):
{{ALREADY_STR}}

LISTA editor:
{{EDITOR_LIST}}

LISTA plain:
{{PLAIN_LIST}}

ESQUEMA:
{{SCHEMA_JSON}}
TXT;
    }
}

