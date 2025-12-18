<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Models\Dominios_Contenido_DetallesModel;
use Illuminate\Support\Str;

class GenerarContenidoKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public $timeout = 300;   // 5 min (ajusta según tu caso)
    public $tries = 1;       // para que no se duplique mientras pruebas
    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {}

    public function handle(): void
    {
        // ✅ SIEMPRE genera un registro nuevo (historial)
        $registro = Dominios_Contenido_DetallesModel::create([
            // NO enviar id_dominio_contenido_detalle (AUTO_INCREMENT)
            'id_dominio_contenido' => (int)$this->idDominioContenido,
            'id_dominio' => (int)$this->idDominio,
            'tipo' => $this->tipo,
            'keyword' => $this->keyword,
            'estatus' => 'en_proceso',
            'modelo' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ]);

        try {
            $apiKey = env('DEEPSEEK_API_KEY');
            $model  = env('DEEPSEEK_MODEL', 'deepseek-chat');

            // ✅ Títulos anteriores para NO repetir (limitado para no inflar el prompt)
            $existentes = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int)$this->idDominioContenido)
                ->whereNotNull('title')
                ->orderByDesc('id_dominio_contenido_detalle')
                ->limit(12)
                ->pluck('title')
                ->toArray();

            $noRepetir = implode(' | ', array_filter($existentes));

            // 1) Redactor -> HTML borrador
            $draftPrompt = $this->promptRedactor($this->tipo, $this->keyword, $noRepetir);
            $draftHtml   = $this->deepseekText($apiKey, $model, $draftPrompt);

            // 2) Auditor -> HTML final mejorado
            $auditPrompt = $this->promptAuditorHtml($this->tipo, $this->keyword, $draftHtml, $noRepetir);
            $finalHtml   = $this->deepseekText($apiKey, $model, $auditPrompt);

            // Title desde H1
            $title = null;
            if (preg_match('~<h1[^>]*>(.*?)</h1>~is', $finalHtml, $m)) {
                $title = trim(strip_tags($m[1]));
            }

            // Slug único (si el title se repite igual, al menos el slug no choca)
            $slugBase = $title ? Str::slug($title) : Str::slug($this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // Actualiza el registro con resultados
            $registro->update([
                'title' => $title,
                'slug' => $slug,
                'contenido_html' => $finalHtml,
                'draft_html' => $draftHtml,
                'estatus' => 'generado',
                'error' => null,
            ]);

        } catch (\Throwable $e) {
            $registro->update([
                'estatus' => 'error',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Llamada a DeepSeek (OpenAI-compatible) y extracción de texto.
     */
    private function deepseekText(string $apiKey, string $model, string $prompt): string
    {
        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout(15)
            ->timeout(150)
            ->retry(1, 700)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                // opcional:
                // 'temperature' => 0.8,
            ]);

        if (!$resp->successful()) {
            throw new \RuntimeException("DeepSeek error {$resp->status()}: {$resp->body()}");
        }

        $data = $resp->json();

        $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));

        if ($text === '') {
            throw new \RuntimeException("DeepSeek returned empty text.");
        }

        return $text;
    }

    /**
     * Redactor: genera un borrador HTML (sin repetir títulos previos).
     */
 private function promptRedactor(string $tipo, string $keyword, string $noRepetir): string
{
    $base = "Devuelve SOLO HTML válido.
NO incluyas <!DOCTYPE>, <html>, <head>, <meta>, <title>, <body>.
NO uses markdown. NO expliques nada.
NO uses headings: Introducción, Conclusión, ¿Qué es...?
NO uses casos de éxito ni testimonios.
NO uses el texto 'guía práctica' ni variantes.

Títulos ya usados (NO repetir ni hacer muy similares):
{$noRepetir}

REGLA CLAVE:
Aunque la keyword sea la misma, crea una versión totalmente distinta:
- título diferente
- H2/H3 diferentes
- orden distinto
- ejemplos/argumentos diferentes
- evita frases tipo: 'en este artículo veremos...'.";

    return "{$base}

Keyword objetivo: {$keyword}
Tipo: {$tipo}

" . $this->nictorysContract() . "

INSTRUCCIONES EXTRA:
- El <h1> debe ir dentro del HERO (hero-slider).
- Mantén la estructura HTML de cada sección como la plantilla (containers, rows, cols, grids).
- CTAs: al inicio (hero), mitad (cta-section-s2) y cierre (última sección).
- FAQ: inclúyela dentro de la sección que mejor encaje (por ejemplo dentro de about o why-choose-us) usando <h3> + <ul><li> o <div>.

Devuelve SOLO el HTML.";
}

    /**
     * Auditor: mejora el borrador y devuelve HTML final.
     */
  private function promptAuditorHtml(string $tipo, string $keyword, string $draftHtml, string $noRepetir): string
{
    return "Eres un consultor SEO senior. Tu tarea es AUDITAR y MEJORAR el contenido y devolver UNA VERSIÓN FINAL.

Devuelve SOLO HTML válido.
NO incluyas <!DOCTYPE>, <html>, <head>, <meta>, <title>, <body>.
NO uses markdown.
NO expliques nada.
No headings: Introducción, Conclusión, ¿Qué es...?
No casos de éxito ni testimonios reales.
No 'guía práctica' ni variantes.

Títulos ya usados (NO repetir ni hacer muy similares):
{$noRepetir}

DEBES RESPETAR este contrato de estructura/clases y el wrapper:
" . $this->nictorysContract() . "

Objetivo:
- Mejorar intención de búsqueda y conversión
- Evitar repetición y relleno
- H2/H3 más específicos
- CTA más claro
- Mantener 1 solo <h1> (en hero)

Tipo: {$tipo}
Keyword: {$keyword}

HTML A MEJORAR (reescribe y devuelve HTML final):
{$draftHtml}";
}







    private function nictorysContract(): string
{
    return <<<TXT
DEVUELVE SOLO HTML (contenido del post/page). NO incluyas <!DOCTYPE>, <html>, <head>, <body>, <script>, <link>, <style>, header, footer.

OBLIGATORIO: envuelve TODO en:
<div class="nictorys-content"> ... </div>

Usa EXACTAMENTE estas secciones y clases (en este orden):
1) <section class="hero-slider hero-style-2"> ... (AQUÍ va el ÚNICO <h1>)
   - Debe incluir .swiper-container, .swiper-wrapper, .swiper-slide, y dentro .slide-inner.slide-bg-image con data-background="assets/images/slider/slide-1.jpg"
   - Incluye 2 CTAs con clases theme-btn y theme-btn-s2

2) <section class="features-section-s2"> ... (4 features .grid)

3) <section class="about-us-section-s2 section-padding p-t-0"> ...
   - Incluye .img-holder y .about-details, y lista <ul><li>...

4) <section class="services-section-s2 section-padding" id="services"> ...
   - 6 cards .grid con .img-holder + .details + icon <i class="fi ..."></i>

5) <section class="contact-section section-padding" id="contact"> ...
   - Mantén estructura de formulario similar (inputs + textarea), action "#"

6) <section class="cta-section-s2"> ... (CTA fuerte)

7) <section class="latest-projects-section-s2 section-padding"> ...
   - 6 items .grid con imagen + título

8) <section class="why-choose-us-section section-padding p-t-0"> ...
   - skills con progress-bar data-percent

9) <section class="team-section section-padding p-t-0"> ... (4 miembros)

10) <section class="testimonials-section section-padding"> ...
   - PROHIBIDO testimonios reales, casos de éxito o “clientes dijeron”.
   - Usa esta sección como "Garantías/Compromisos" (2 bloques tipo quote pero sin personas reales)

11) <section class="blog-section section-padding"> ...
   - 3 entradas sugeridas (títulos, fecha, extracto)

REGLAS DE CONTENIDO:
- Español natural, orientado a conversión.
- No headings genéricos: “Introducción”, “Conclusión”, “¿Qué es…?”
- No definiciones estilo diccionario.
- No “guía práctica” ni variantes.
- Nada de Lorem ipsum.
- Enlaces siempre href="#".
- Imágenes siempre con rutas tipo assets/images/... (tu plugin las reescribe).
TXT;
}
}
