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

    // ✅ En prod puede tardar. Sube timeout.
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

            // ====== historial para NO repetir ======
            $prev = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int)$this->idDominioContenido)
                ->whereNotNull('draft_html')
                ->orderByDesc('id_dominio_contenido_detalle')
                ->limit(12)
                ->get(['title','draft_html']);

            $usedTitles = [];
            $usedCorpus = [];
            foreach ($prev as $row) {
                if (!empty($row->title)) $usedTitles[] = (string)$row->title;
                $usedCorpus[] = $this->copyTextFromDraftJson((string)$row->draft_html);
            }

            $noRepetirTitles = implode(' | ', array_filter($usedTitles));
            $noRepetirCorpus = implode("\n\n----\n\n", array_filter($usedCorpus));

            // ===========================================================
            // 1) REDACTOR (JSON)
            // ===========================================================
            $draftPrompt = $this->promptRedactorJson(
                $this->tipo,
                $this->keyword,
                $noRepetirTitles,
                $noRepetirCorpus
            );

            $draftRaw = $this->deepseekText($apiKey, $model, $draftPrompt, maxTokens: 2200);
            $draft = $this->parseJsonStrict($draftRaw);
            $draft = $this->sanitizeCopyForSeo($draft);
            $this->validateCopySchema($draft);

            // ===========================================================
            // 2) AUDITOR anti-repetición (solo si hace falta)
            // ===========================================================
            $final = $draft;

            if ($this->isTooSimilarToAnyPrevious($draft, $usedTitles, $usedCorpus)) {
                $auditPrompt = $this->promptAuditorJson(
                    $this->tipo,
                    $this->keyword,
                    $draft,
                    $noRepetirTitles,
                    $noRepetirCorpus
                );

                $auditedRaw = $this->deepseekText($apiKey, $model, $auditPrompt, maxTokens: 2400);
                $final = $this->parseJsonStrict($auditedRaw);
                $final = $this->sanitizeCopyForSeo($final);
                $this->validateCopySchema($final);
            }

            // ===========================================================
            // 3) REPAIR (si viola SEO/H1 o sigue similar)
            // ===========================================================
            if ($this->violatesSeoHardRules($final) || $this->isTooSimilarToAnyPrevious($final, $usedTitles, $usedCorpus)) {
                $repairPrompt = $this->promptRepairJson(
                    $this->keyword,
                    $final,
                    $noRepetirTitles,
                    $noRepetirCorpus
                );

                $repairRaw = $this->deepseekText($apiKey, $model, $repairPrompt, maxTokens: 2400);
                $final = $this->parseJsonStrict($repairRaw);
                $final = $this->sanitizeCopyForSeo($final);
                $this->validateCopySchema($final);
            }

            // ===========================================================
            // 4) Cargar template Elementor base + rellenar por IDs
            // ===========================================================
            $tpl = $this->loadElementorTemplateFromStorage();
            $filled = $this->fillElementorTemplate($tpl, $final);

            // ===========================================================
            // 5) Title + slug
            // ===========================================================
            $title = trim(strip_tags((string)($final['seo_title'] ?? $final['hero_h1'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // 6) Guardar en BD (NO publica a WP; tú lo haces con publicar())
            // ===========================================================
            $registro->update([
                'title' => $title,
                'slug' => $slug,
                'draft_html' => json_encode($final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    // ===========================================================
    // TEMPLATE LOADER (storage/app/...)
    // ===========================================================
    private function loadElementorTemplateFromStorage(): array
    {
        $templateRel = (string) env('ELEMENTOR_TEMPLATE_PATH', '');
        if ($templateRel === '') {
            throw new \RuntimeException('ELEMENTOR_TEMPLATE_PATH no configurado. Ej: elementor/elementor-64.json');
        }

        $templateRel = trim($templateRel);
        $templateRel = str_replace(['https:', 'http:'], '', $templateRel);

        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) {
            throw new \RuntimeException('ELEMENTOR_TEMPLATE_PATH inválido (no se permite "..")');
        }

        $templatePath = storage_path('app/' . $templateRel);

        if (!is_file($templatePath)) {
            throw new \RuntimeException("No existe el template Elementor en disco: {$templatePath} (ELEMENTOR_TEMPLATE_PATH={$templateRel})");
        }

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('Template Elementor inválido: debe contener "content" (array).');
        }

        return $tpl;
    }

    // ===========================================================
    // DeepSeek (OpenAI compatible)
    // ===========================================================
    private function deepseekText(string $apiKey, string $model, string $prompt, int $maxTokens = 1200): string
    {
        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout(15)
            ->timeout(180)
            ->retry(0, 0)
            ->post('https://api.deepseek.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.85, // 👈 más diversidad
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

    // ===========================================================
    // PROMPTS
    // ===========================================================
    private function promptRedactorJson(string $tipo, string $keyword, string $noRepetirTitles, string $noRepetirCorpus): string
    {
        return <<<PROMPT
Devuelve SOLO JSON válido (sin markdown, sin explicación, sin texto fuera del JSON).

OBJETIVO:
Crear copy para una landing (Elementor) muy diferente a las anteriores aunque la keyword sea la misma.

Keyword: {$keyword}
Tipo: {$tipo}

Títulos ya usados (NO repetir ni muy similares):
{$noRepetirTitles}

Textos anteriores (NO repetir frases ni estructura, evita similitud):
{$noRepetirCorpus}

REGLAS SEO (obligatorias):
- SOLO 1 H1: ÚNICAMENTE el campo "hero_h1" es H1.
- NO escribir <h1> en ningún campo.
- Evita keyword stuffing. Usa sinónimos y variaciones naturales.
- Usa intención transaccional/servicio: beneficios, diferenciales, proceso, objeciones.
- No uses “Introducción”, “Conclusión”, “¿Qué es…?”.
- No testimonios reales ni casos de éxito.
- Copy escaneable y orientado a conversión.

Devuelve ESTE esquema EXACTO:
{
  "seo_title": "... (máx 60-65 chars, único)",
  "hero_h1": "... (único H1)",
  "hero_p_html": "<p>...</p>",

  "kit_h1": "... (título de sección, NO H1 real)",
  "kit_p_html": "<p>...</p>",

  "pack_h2": "... (título sección)",
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

Restricciones HTML:
- En p_html/a_html SOLO usa <p>, <strong>, <br>.
- NO uses listas <ul>/<li> aquí.
PROMPT;
    }

    private function promptAuditorJson(string $tipo, string $keyword, array $draft, string $noRepetirTitles, string $noRepetirCorpus): string
    {
        $draftShort = mb_substr(json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 6500);

        return <<<PROMPT
Eres un editor SEO senior y CRO. Reescribe el JSON para que sea MUY DIFERENTE a los contenidos anteriores y NO repita frases/ángulos.

Devuelve SOLO JSON válido (mismo esquema, mismas keys).
Keyword: {$keyword}
Tipo: {$tipo}

Títulos ya usados (NO repetir ni muy similares):
{$noRepetirTitles}

Textos anteriores (NO repetir):
{$noRepetirCorpus}

BORRADOR A REESCRIBIR:
{$draftShort}

REGLAS OBLIGATORIAS:
- SOLO 1 H1: solo "hero_h1" (no escribas <h1> en ningún campo).
- seo_title único (60-65 chars).
- Cambia enfoque, promesa, lenguaje y estructura.
- Features y FAQs totalmente distintas (sin reciclar frases).
- No “Introducción”, “Conclusión”, “¿Qué es…?”.
- No testimonios reales.
- No keyword stuffing.
- HTML permitido: <p>, <strong>, <br>.

Devuelve el JSON final.
PROMPT;
    }

    private function promptRepairJson(string $keyword, array $json, string $noRepetirTitles, string $noRepetirCorpus): string
    {
        $short = mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 6500);

        return <<<PROMPT
Corrige este JSON para que cumpla SEO, NO tenga <h1> en ningún campo y sea totalmente distinto a los anteriores.

Devuelve SOLO JSON válido con el MISMO esquema y keys.
Keyword: {$keyword}

Títulos ya usados (NO repetir ni muy similares):
{$noRepetirTitles}

Textos anteriores (NO repetir):
{$noRepetirCorpus}

JSON a reparar:
{$short}

CHECKLIST OBLIGATORIA:
- Solo 1 H1: hero_h1 (no <h1> en nada).
- seo_title <= 65 caracteres, único.
- Nada de keyword stuffing.
- Features y FAQs: enfoque distinto y frases distintas.
- Sin headings genéricos: Introducción/Conclusión/Qué es.
- HTML permitido: <p>, <strong>, <br>.

Devuelve el JSON final corregido.
PROMPT;
    }

    // ===========================================================
    // JSON PARSER
    // ===========================================================
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

    // ===========================================================
    // SEO/H1: sanitizar + validaciones
    // ===========================================================
    private function sanitizeCopyForSeo(array $copy): array
    {
        // hero_h1: solo texto
        $copy['hero_h1'] = trim(strip_tags((string)($copy['hero_h1'] ?? '')));
        if ($copy['hero_h1'] === '') {
            $copy['hero_h1'] = trim($this->keyword);
        }

        // seo_title: limpio + truncado
        $seo = trim(strip_tags((string)($copy['seo_title'] ?? '')));
        if ($seo === '') $seo = $copy['hero_h1'];

        if (mb_strlen($seo) > 65) {
            $seo = mb_substr($seo, 0, 65);
            $seo = rtrim($seo, " \t\n\r\0\x0B-–—|:");
        }
        $copy['seo_title'] = $seo;

        // limpia títulos
        $copy['kit_h1']       = trim(strip_tags((string)($copy['kit_h1'] ?? '')));
        $copy['pack_h2']      = trim(strip_tags((string)($copy['pack_h2'] ?? '')));
        $copy['faq_title']    = trim(strip_tags((string)($copy['faq_title'] ?? '')));
        $copy['final_cta_h3'] = trim(strip_tags((string)($copy['final_cta_h3'] ?? '')));

        // html keys: quitar h1 y dejar solo <p><strong><br>
        foreach (['hero_p_html','kit_p_html','pack_p_html'] as $k) {
            $v = (string)($copy[$k] ?? '');
            $v = $this->stripH1Tags($v);
            $v = $this->keepAllowedInlineHtml($v);
            $copy[$k] = $v;
        }

        if (isset($copy['features']) && is_array($copy['features'])) {
            foreach ($copy['features'] as $i => $f) {
                $copy['features'][$i]['title'] = trim(strip_tags((string)($f['title'] ?? '')));
                $p = (string)($f['p_html'] ?? '');
                $p = $this->stripH1Tags($p);
                $p = $this->keepAllowedInlineHtml($p);
                $copy['features'][$i]['p_html'] = $p;
            }
        }

        if (isset($copy['faq']) && is_array($copy['faq'])) {
            foreach ($copy['faq'] as $i => $q) {
                $copy['faq'][$i]['q'] = trim(strip_tags((string)($q['q'] ?? '')));
                $a = (string)($q['a_html'] ?? '');
                $a = $this->stripH1Tags($a);
                $a = $this->keepAllowedInlineHtml($a);
                $copy['faq'][$i]['a_html'] = $a;
            }
        }

        return $copy;
    }

    private function stripH1Tags(string $html): string
    {
        $html = preg_replace('~<\s*h1\b[^>]*>~i', '', $html);
        $html = preg_replace('~<\s*/\s*h1\s*>~i', '', $html);
        return (string)$html;
    }

    private function keepAllowedInlineHtml(string $html): string
    {
        $clean = strip_tags($html, '<p><strong><br>');
        $clean = preg_replace('~\s+~u', ' ', $clean);
        $clean = str_replace(['</p> <p>','</p><p>'], '</p><p>', $clean);
        return trim((string)$clean);
    }

    private function violatesSeoHardRulesReason(array $copy): ?string
    {
        $all = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($all) && preg_match('~<\s*/?\s*h1\b~i', $all)) {
            return 'detectado <h1> en algún campo';
        }

        $seo = (string)($copy['seo_title'] ?? '');
        if ($seo !== '' && mb_strlen($seo) > 70) {
            return 'seo_title demasiado largo';
        }

        if (trim((string)($copy['hero_h1'] ?? '')) === '') {
            return 'hero_h1 vacío';
        }

        return null;
    }

    private function violatesSeoHardRules(array $copy): bool
    {
        return $this->violatesSeoHardRulesReason($copy) !== null;
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

        $reason = $this->violatesSeoHardRulesReason($copy);
        if ($reason !== null) {
            throw new \RuntimeException("El JSON viola reglas SEO/H1: {$reason}");
        }
    }

    // ===========================================================
    // ANTI-REPETICIÓN (similitud)
    // ===========================================================
    private function isTooSimilarToAnyPrevious(array $copy, array $usedTitles, array $usedCorpus): bool
    {
        $title = mb_strtolower(trim((string)($copy['seo_title'] ?? $copy['hero_h1'] ?? '')));
        if ($title !== '') {
            foreach ($usedTitles as $t) {
                $t2 = mb_strtolower(trim((string)$t));
                if ($t2 !== '' && $this->jaccardBigrams($title, $t2) >= 0.72) {
                    return true;
                }
            }
        }

        $text = $this->copyTextFromArray($copy);
        foreach ($usedCorpus as $corp) {
            if ($corp === '') continue;
            $sim = $this->jaccardBigrams($text, $corp);
            if ($sim >= 0.55) return true;
        }

        return false;
    }

    private function jaccardBigrams(string $a, string $b): float
    {
        $A = $this->bigrams($a);
        $B = $this->bigrams($b);
        if (!$A || !$B) return 0.0;

        $inter = array_intersect_key($A, $B);
        $union = $A + $B;

        $i = count($inter);
        $u = count($union);
        if ($u === 0) return 0.0;
        return $i / $u;
    }

    private function bigrams(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('~\s+~u', ' ', $s);
        $s = trim($s);
        if ($s === '') return [];

        $chars = preg_split('~~u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $n = count($chars);
        for ($i=0; $i<$n-1; $i++) {
            $bg = $chars[$i] . $chars[$i+1];
            $out[$bg] = 1;
        }
        return $out;
    }

    private function copyTextFromDraftJson(string $draftJson): string
    {
        $draftJson = trim((string)$draftJson);
        if ($draftJson === '') return '';

        $arr = json_decode($draftJson, true);
        if (!is_array($arr)) return '';
        return $this->copyTextFromArray($arr);
    }

    private function copyTextFromArray(array $copy): string
    {
        $parts = [];
        foreach (['seo_title','hero_h1','hero_p_html','kit_h1','kit_p_html','pack_h2','pack_p_html','faq_title','final_cta_h3'] as $k) {
            $parts[] = strip_tags((string)($copy[$k] ?? ''));
        }
        if (!empty($copy['features']) && is_array($copy['features'])) {
            foreach ($copy['features'] as $f) {
                $parts[] = strip_tags((string)($f['title'] ?? ''));
                $parts[] = strip_tags((string)($f['p_html'] ?? ''));
            }
        }
        if (!empty($copy['faq']) && is_array($copy['faq'])) {
            foreach ($copy['faq'] as $q) {
                $parts[] = strip_tags((string)($q['q'] ?? ''));
                $parts[] = strip_tags((string)($q['a_html'] ?? ''));
            }
        }
        $txt = implode(' ', array_filter($parts));
        $txt = preg_replace('~\s+~u', ' ', $txt);
        return trim((string)$txt);
    }

    // ===========================================================
    // Elementor fill (IDs de tu plantilla v64)
    // ===========================================================
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

        // HERO (H1 único)
        $set('1d822e12', 'title', $copy['hero_h1']);
        $set('6074ada3', 'editor', $copy['hero_p_html']);

        // KIT (título sección)
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

        // FAQ title
        $set('6af89728', 'title', $copy['faq_title']);

        // FAQ questions
        $mutateWidget('19d18174', function (&$w) use ($copy) {
            if (!isset($w['settings']['items']) || !is_array($w['settings']['items'])) return;
            foreach ($w['settings']['items'] as $i => &$it) {
                if (!isset($copy['faq'][$i])) continue;
                $it['item_title'] = (string)$copy['faq'][$i]['q'];
            }
        });

        // FAQ answers
        $faqAnswerIds = ['4187d584','289604f1','5f11dfaa','68e67f41','5ba521b7','3012a20a','267fd373','4091b80d','7d07103e'];
        foreach ($faqAnswerIds as $i => $ansId) {
            $set($ansId, 'editor', (string)$copy['faq'][$i]['a_html']);
        }

        // CTA final
        $set('15bd3353', 'title', $copy['final_cta_h3']);

        return $tpl;
    }
}
