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

    public $timeout = 900;
    public $tries = 1;

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {}

    public function handle(): void
    {
        $registro = Dominios_Contenido_DetallesModel::create([
            'id_dominio_contenido' => (int)$this->idDominioContenido,
            'id_dominio' => (int)$this->idDominio,
            'tipo' => $this->tipo,
            'keyword' => $this->keyword,
            'estatus' => 'en_proceso',
            'modelo' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ]);

        try {
            $apiKey = (string) env('DEEPSEEK_API_KEY', '');
            $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');

            if ($apiKey === '') {
                throw new \RuntimeException('DEEPSEEK_API_KEY no configurado');
            }

            // Evitar títulos repetidos
            $existentes = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int)$this->idDominioContenido)
                ->whereNotNull('title')
                ->orderByDesc('id_dominio_contenido_detalle')
                ->limit(12)
                ->pluck('title')
                ->toArray();

            $noRepetir = implode(' | ', array_filter($existentes));

            // 1) Generar COPY como JSON
            $copyPrompt = $this->promptCopyElementor($this->tipo, $this->keyword, $noRepetir);
            $copyRaw    = $this->deepseekText($apiKey, $model, $copyPrompt, maxTokens: 1800);

            $copy = $this->parseJsonStrict($copyRaw);
            $this->validateCopySchema($copy);

            // 2) Cargar template Elementor base desde storage/app/...
            $tpl = $this->loadElementorTemplateFromStorage();

            // 3) Rellenar el template con el copy (por IDs)
            $filled = $this->fillElementorTemplate($tpl, $copy);

            // 4) Title + slug
            $title = trim(strip_tags((string)($copy['seo_title'] ?? $copy['hero_h1'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // 5) Guardar en BD (NO publica a WP; tú lo haces con tu método publicar())
            $registro->update([
                'title' => $title,
                'slug' => $slug,
                'draft_html' => json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'contenido_html' => json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
     * ✅ Carga el template Elementor desde storage/app/<ELEMENTOR_TEMPLATE_PATH>
     * ELEMENTOR_TEMPLATE_PATH debe ser ruta relativa, NO URL
     * ejemplo: elementor/elementor-64.json
     */
    private function loadElementorTemplateFromStorage(): array
    {
        $templateRel = (string) env('ELEMENTOR_TEMPLATE_PATH', '');
        if ($templateRel === '') {
            throw new \RuntimeException('ELEMENTOR_TEMPLATE_PATH no configurado. Ej: elementor/elementor-64.json');
        }

        // No aceptar URL (para evitar el error que te salió)
        if (preg_match('~^https?://~i', $templateRel)) {
            throw new \RuntimeException('ELEMENTOR_TEMPLATE_PATH debe ser una ruta local (ej: elementor/elementor-64.json), no una URL.');
        }

        $templatePath = storage_path('app/' . ltrim($templateRel, '/'));

        if (!is_file($templatePath)) {
            throw new \RuntimeException("No existe el template Elementor en disco: {$templatePath}");
        }

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('Template Elementor inválido: debe contener "content" (array).');
        }

        return $tpl;
    }

    /**
     * DeepSeek (OpenAI compatible)
     */
    private function deepseekText(string $apiKey, string $model, string $prompt, int $maxTokens = 1200): string
    {
        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout(15)
            ->timeout(160)
            ->retry(0, 0)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens'  => $maxTokens,
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
     * Prompt: devuelve SOLO JSON (copy)
     */
    private function promptCopyElementor(string $tipo, string $keyword, string $noRepetir): string
    {
        return <<<PROMPT
Devuelve SOLO JSON válido (sin markdown, sin explicación, sin texto fuera del JSON).

Keyword: {$keyword}
Tipo: {$tipo}

Títulos ya usados (NO repetir ni hacer muy similares):
{$noRepetir}

Devuelve ESTE esquema EXACTO:
{
  "seo_title": "...",
  "hero_h1": "...",
  "hero_p_html": "<p>...</p>",

  "kit_h1": "...",
  "kit_p_html": "<p>...</p>",

  "pack_h2": "...",
  "pack_p_html": "<p>...</p>",

  "features": [
    {"title":"...", "p_html":"<p>...</p>"},
    {"title":"...", "p_html":"<p>...</p>"},
    {"title":"...", "p_html":"<p>...</p>"},
    {"title":"...", "p_html":"<p>...</p>"}
  ],

  "faq_title": "...",
  "faq": [
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"},
    {"q":"...", "a_html":"<p>...</p>"}
  ],

  "final_cta_h3": "..."
}

REGLAS:
- Español, orientado a conversión.
- NO uses “Introducción”, “Conclusión”, “¿Qué es…?”
- NO uses testimonios reales ni casos de éxito.
- NO uses “guía práctica”.
- En p_html/a_html usa HTML simple: <p>, <strong>, <br>.
PROMPT;
    }

    /**
     * Parse JSON aunque venga con ``` o basura alrededor
     */
    private function parseJsonStrict(string $raw): array
    {
        $raw = trim($raw);

        $raw = preg_replace('~^```(?:json)?\s*~i', '', $raw);
        $raw = preg_replace('~\s*```$~', '', $raw);
        $raw = trim($raw);

        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('DeepSeek no devolvió JSON válido');
        }
        return $data;
    }

    private function validateCopySchema(array $copy): void
    {
        $must = ['seo_title','hero_h1','hero_p_html','kit_h1','kit_p_html','pack_h2','pack_p_html','features','faq_title','faq','final_cta_h3'];
        foreach ($must as $k) {
            if (!array_key_exists($k, $copy)) {
                throw new \RuntimeException("JSON copy incompleto, falta: {$k}");
            }
        }
        if (!is_array($copy['features']) || count($copy['features']) !== 4) {
            throw new \RuntimeException('features debe tener 4 items');
        }
        if (!is_array($copy['faq']) || count($copy['faq']) !== 9) {
            throw new \RuntimeException('faq debe tener 9 items');
        }
    }

    /**
     * Rellena el template Elementor con los IDs de tu plantilla v64
     */
    private function fillElementorTemplate(array $tpl, array $copy): array
    {
        $set = function(string $id, string $key, $value) use (&$tpl): void {
            $walk = function (&$nodes) use (&$walk, $id, $key, $value): bool {
                if (!is_array($nodes)) return false;
                foreach ($nodes as &$n) {
                    if (is_array($n) && ($n['id'] ?? null) === $id && ($n['elType'] ?? null) === 'widget') {
                        if (!isset($n['settings']) || !is_array($n['settings'])) $n['settings'] = [];
                        $n['settings'][$key] = $value;
                        return true;
                    }
                    if (!empty($n['elements']) && $walk($n['elements'])) return true;
                }
                return false;
            };
            $walk($tpl['content']);
        };

        $mutateWidget = function(string $widgetId, callable $fn) use (&$tpl): void {
            $walk = function (&$nodes) use (&$walk, $widgetId, $fn): bool {
                if (!is_array($nodes)) return false;
                foreach ($nodes as &$n) {
                    if (($n['id'] ?? null) === $widgetId && ($n['elType'] ?? null) === 'widget') {
                        $fn($n);
                        return true;
                    }
                    if (!empty($n['elements']) && $walk($n['elements'])) return true;
                }
                return false;
            };
            $walk($tpl['content']);
        };

        // HERO
        $set('1d822e12', 'title', $copy['hero_h1']);
        $set('6074ada3', 'editor', $copy['hero_p_html']);

        // KIT
        $set('14d64ba5', 'title', $copy['kit_h1']);
        $set('3742cd49', 'editor', $copy['kit_p_html']);

        // PACK
        $set('5a85cb05', 'title', $copy['pack_h2']);
        $set('6ad00c97', 'editor', $copy['pack_p_html']);

        // FEATURES
        $featureMap = [
            ['titleId' => '526367e6', 'pId' => '45af2625'],
            ['titleId' => '4666a6c0', 'pId' => '53b8710d'],
            ['titleId' => '556cf582', 'pId' => '1043978d'],
            ['titleId' => '671577a',  'pId' => '35dc5b0f'],
        ];
        foreach ($featureMap as $i => $m) {
            $set($m['titleId'], 'title',  (string)$copy['features'][$i]['title']);
            $set($m['pId'],     'editor', (string)$copy['features'][$i]['p_html']);
        }

        // FAQ
        $set('6af89728', 'title', $copy['faq_title']);

        $mutateWidget('19d18174', function (&$w) use ($copy) {
            if (!isset($w['settings']['items']) || !is_array($w['settings']['items'])) return;
            foreach ($w['settings']['items'] as $i => &$it) {
                if (!isset($copy['faq'][$i])) continue;
                $it['item_title'] = (string)$copy['faq'][$i]['q'];
            }
        });

        $faqAnswerIds = ['4187d584','289604f1','5f11dfaa','68e67f41','5ba521b7','3012a20a','267fd373','4091b80d','7d07103e'];
        foreach ($faqAnswerIds as $i => $ansId) {
            $set($ansId, 'editor', (string)$copy['faq'][$i]['a_html']);
        }

        // CTA final
        $set('15bd3353', 'title', $copy['final_cta_h3']);

        return $tpl;
    }
}
