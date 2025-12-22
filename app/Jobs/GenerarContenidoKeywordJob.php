<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;

use App\Models\DominiosModel;
use App\Models\Dominios_Contenido_DetallesModel;

class GenerarContenidoKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 2400;
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

            // =======================================
            // Historial para NO repetir
            // =======================================
            $prev = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int)$this->idDominioContenido)
                ->whereNotNull('draft_html')
                ->orderByDesc('id_dominio_contenido_detalle')
                ->limit(8)
                ->get(['title', 'draft_html']);

            $usedTitles = [];
            $usedCorpus = [];

            foreach ($prev as $row) {
                if (!empty($row->title)) $usedTitles[] = (string)$row->title;
                $usedCorpus[] = $this->copyTextFromDraftJson((string)$row->draft_html);
            }

            $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 12));
            $noRepetirCorpus = $this->compactHistory($usedCorpus, 2500);

            // =======================================
            // Generación con 3 intentos
            // =======================================
            $final = null;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $brief = $this->creativeBrief($this->keyword);

                // 1) REDACTOR
                $draftPrompt = $this->promptRedactorJson(
                    $this->tipo,
                    $this->keyword,
                    $noRepetirTitles,
                    $noRepetirCorpus,
                    $brief
                );

                $draftRaw = $this->deepseekText(
                    $apiKey, $model, $draftPrompt,
                    maxTokens: 2600,
                    temperature: 0.95,
                    topP: 0.92,
                    jsonMode: true
                );

                $draft = $this->safeParseOrRepair($apiKey, $model, $draftRaw, $brief);
                $draft = $this->sanitizeAndNormalizeCopy($draft);
                $draft = $this->validateAndFixCopy($draft);

                // 2) AUDITOR
                $auditPrompt = $this->promptAuditorJson(
                    $this->tipo,
                    $this->keyword,
                    $draft,
                    $noRepetirTitles,
                    $noRepetirCorpus,
                    $brief
                );

                $auditedRaw = $this->deepseekText(
                    $apiKey, $model, $auditPrompt,
                    maxTokens: 2800,
                    temperature: 0.92,
                    topP: 0.90,
                    jsonMode: true
                );

                $candidate = $this->safeParseOrRepair($apiKey, $model, $auditedRaw, $brief);
                $candidate = $this->sanitizeAndNormalizeCopy($candidate);
                $candidate = $this->validateAndFixCopy($candidate);

                // 3) REPAIR si viola reglas o es muy similar
                if ($this->violatesSeoHardRules($candidate) || $this->isTooSimilarToAnyPrevious($candidate, $usedTitles, $usedCorpus)) {
                    $repairPrompt = $this->promptRepairJson(
                        $this->keyword,
                        $candidate,
                        $noRepetirTitles,
                        $noRepetirCorpus,
                        $brief
                    );

                    $repairRaw = $this->deepseekText(
                        $apiKey, $model, $repairPrompt,
                        maxTokens: 2200,
                        temperature: 0.35,
                        topP: 0.90,
                        jsonMode: true
                    );

                    $candidate = $this->safeParseOrRepair($apiKey, $model, $repairRaw, $brief);
                    $candidate = $this->sanitizeAndNormalizeCopy($candidate);
                    $candidate = $this->validateAndFixCopy($candidate);
                }

                $final = $candidate;

                if (
                    !$this->isTooSimilarToAnyPrevious($candidate, $usedTitles, $usedCorpus) &&
                    !$this->violatesSeoHardRules($candidate)
                ) {
                    break;
                }

                // alimentar historial para el siguiente intento
                $usedTitles[] = $this->toStr($candidate['seo_title'] ?? $candidate['hero_h1'] ?? '');
                $usedCorpus[] = $this->copyTextFromArray($candidate);
                $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 12));
                $noRepetirCorpus = $this->compactHistory($usedCorpus, 2500);
            }

            if (!is_array($final)) {
                throw new \RuntimeException('No se pudo generar contenido final');
            }

            // =======================================
            // Cargar template por dominio
            // =======================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);

            // =======================================
            // Reemplazo por TOKENS (v4)
            // =======================================
            [$filled, $replacedCount, $remaining] = $this->fillElementorTemplate_byPrettyTokens_withStats($tpl, $final);

            if ($replacedCount < 8) {
                throw new \RuntimeException("Template no parece tokenizado (replacedCount={$replacedCount}). Template: {$tplPath}");
            }

            if (count($remaining) > 0) {
                throw new \RuntimeException("Quedaron tokens sin reemplazar: " . implode(' | ', array_slice($remaining, 0, 50)));
            }

            // (Opcional) Post-pass por si queda texto fijo fuera de tokens
            [$filled, $forcedCount] = $this->forceReplaceStaticTextsInTemplate($filled, $final);

            // =======================================
            // Title + slug
            // =======================================
            $title = trim(strip_tags($this->toStr($final['seo_title'] ?? $final['hero_h1'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // =======================================
            // Guardar en BD (primero BD, luego WP en proceso separado)
            // =======================================
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
    // TEMPLATE LOADER por dominio
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) {
            throw new \RuntimeException("Dominio no encontrado (id={$idDominio})");
        }

        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));
        if ($templateRel === '') {
            $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_PATH', ''));
        }

        if ($templateRel === '') {
            throw new \RuntimeException('No hay plantilla configurada (dominio ni env ELEMENTOR_TEMPLATE_PATH).');
        }

        $templateRel = str_replace(['https:', 'http:'], '', $templateRel);

        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) {
            throw new \RuntimeException('Template path inválido (no se permite "..")');
        }

        $templatePath = storage_path('app/' . $templateRel);

        if (!is_file($templatePath)) {
            throw new \RuntimeException("No existe el template en disco: {$templatePath} (path={$templateRel})");
        }

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('Template Elementor inválido: debe contener "content" (array).');
        }

        return [$tpl, $templatePath];
    }

    // ===========================================================
    // DeepSeek
    // ===========================================================
    private function deepseekText(
        string $apiKey,
        string $model,
        string $prompt,
        int $maxTokens = 1200,
        float $temperature = 0.90,
        float $topP = 0.92,
        bool $jsonMode = true
    ): string
    {
        $nonce = 'nonce:' . Str::uuid()->toString();

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Devuelves SOLO JSON válido. No markdown. No explicaciones.'],
                ['role' => 'user', 'content' => $prompt . "\n\n" . $nonce],
            ],
            'temperature' => $temperature,
            'top_p' => $topP,
            'presence_penalty' => 1.1,
            'frequency_penalty' => 0.6,
            'max_tokens' => $maxTokens,
        ];

        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout(15)
            ->timeout(180)
            ->retry(0, 0)
            ->post('https://api.deepseek.com/v1/chat/completions', $payload);

        if (!$resp->successful() && $jsonMode) {
            $body = (string)$resp->body();
            if (str_contains($body, 'response_format') || str_contains($body, 'unknown') || str_contains($body, 'invalid')) {
                unset($payload['response_format']);
                $resp = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->connectTimeout(15)
                    ->timeout(180)
                    ->retry(0, 0)
                    ->post('https://api.deepseek.com/v1/chat/completions', $payload);
            }
        }

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
    // Compactar historial
    // ===========================================================
    private function compactHistory(array $corpusArr, int $maxChars = 2500): string
    {
        $chunks = [];
        foreach ($corpusArr as $t) {
            $t = trim((string)$t);
            if ($t === '') continue;

            $t = mb_substr($t, 0, 380);
            $chunks[] = $t;

            $joined = implode("\n---\n", $chunks);
            if (mb_strlen($joined) >= $maxChars) break;
        }

        $out = trim(implode("\n---\n", $chunks));
        if (mb_strlen($out) > $maxChars) $out = mb_substr($out, 0, $maxChars);
        return $out;
    }

    // ===========================================================
    // PARSE ROBUSTO
    // ===========================================================
    private function safeParseOrRepair(string $apiKey, string $model, string $raw, array $brief): array
    {
        try {
            $a = $this->parseJsonStrict($raw);
            return $this->mergeWithSkeleton($a);
        } catch (\Throwable $e) {
            $loose = $this->parseJsonLoose($raw);
            $merged = $this->mergeWithSkeleton($loose);

            if (!empty(trim($this->toStr($merged['hero_h1'] ?? ''))) || !empty(trim($this->toStr($merged['pack_h2'] ?? '')))) {
                return $merged;
            }

            $fixed = $this->repairJsonViaDeepseek($apiKey, $model, $raw, $brief);

            try {
                $b = $this->parseJsonStrict($fixed);
                return $this->mergeWithSkeleton($b);
            } catch (\Throwable $e2) {
                $loose2 = $this->parseJsonLoose($fixed);
                return $this->mergeWithSkeleton($loose2);
            }
        }
    }

    private function mergeWithSkeleton(array $partial): array
    {
        $skeleton = [
            'seo_title' => '',
            'hero_h1' => '',
            'hero_p_html' => '<p></p>',
            'kit_h1' => '',
            'kit_p_html' => '<p></p>',
            'pack_h2' => '',
            'pack_p_html' => '<p></p>',
            'features' => [],
            'faq_title' => '',
            'faq' => [],
            'final_cta_h3' => '',
        ];

        $out = $skeleton;
        foreach ($partial as $k => $v) {
            $out[$k] = $v;
        }
        return $out;
    }

    private function repairJsonViaDeepseek(string $apiKey, string $model, string $broken, array $brief): string
    {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $broken = mb_substr(trim((string)$broken), 0, 8000);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. Sin texto fuera del JSON.
RESPUESTA MINIFICADA.

Tienes un JSON incompleto/cortado. Debes:
- Completarlo y dejarlo VÁLIDO.
- Mantener EXACTAMENTE este esquema y keys:
  seo_title, hero_h1, hero_p_html, kit_h1, kit_p_html, pack_h2, pack_p_html,
  features (4), faq_title, faq (9), final_cta_h3
- HTML permitido SOLO: <p>, <strong>, <br>
- SOLO 1 H1: hero_h1 (no uses <h1>)
- 4 features exactas y 9 FAQs exactas
- seo_title 60-65 chars
- NO puede haber campos vacíos ni "<p></p>"
- Responde sin saltos de línea para no cortar

Estilo:
- Ángulo: {$angle}
- Tono: {$tone}

JSON roto:
{$broken}
PROMPT;

        return $this->deepseekText(
            $apiKey,
            $model,
            $prompt,
            maxTokens: 2200,
            temperature: 0.20,
            topP: 0.90,
            jsonMode: true
        );
    }

    private function parseJsonStrict(string $raw): array
    {
        $raw = trim((string)$raw);
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
            $snip = mb_substr($raw, 0, 700);
            throw new \RuntimeException('DeepSeek no devolvió JSON válido. Snippet: ' . $snip);
        }
        return $data;
    }

    private function parseJsonLoose(string $raw): array
    {
        $raw = trim((string)$raw);
        $raw = preg_replace('~^```(?:json)?\s*~i', '', $raw);
        $raw = preg_replace('~\s*```$~', '', $raw);
        $raw = trim($raw);

        $pos = strpos($raw, '{');
        if ($pos !== false) $raw = substr($raw, $pos);

        $out = [];

        foreach ([
            'seo_title','hero_h1','hero_p_html','kit_h1','kit_p_html',
            'pack_h2','pack_p_html','faq_title','final_cta_h3'
        ] as $key) {
            $v = $this->extractJsonValueLoose($raw, $key);
            if ($v !== null) $out[$key] = $v;
        }

        $featuresBlock = $this->extractBetween($raw, '"features"', '"faq_title"');
        if ($featuresBlock !== null) {
            $objs = $this->extractObjects($featuresBlock);
            $features = [];
            foreach ($objs as $obj) {
                $t = $this->extractJsonValueLoose($obj, 'title') ?? '';
                $p = $this->extractJsonValueLoose($obj, 'p_html') ?? '';
                if (trim($this->toStr($t)) === '' && trim($this->toStr($p)) === '') continue;
                $features[] = ['title' => $t, 'p_html' => $p];
            }
            if (!empty($features)) $out['features'] = $features;
        }

        $faqBlock = $this->extractBetween($raw, '"faq"', '"final_cta_h3"');
        if ($faqBlock !== null) {
            $objs = $this->extractObjects($faqBlock);
            $faq = [];
            foreach ($objs as $obj) {
                $q = $this->extractJsonValueLoose($obj, 'q') ?? '';
                $a = $this->extractJsonValueLoose($obj, 'a_html') ?? '';
                if (trim($this->toStr($q)) === '' && trim($this->toStr($a)) === '') continue;
                $faq[] = ['q' => $q, 'a_html' => $a];
            }
            if (!empty($faq)) $out['faq'] = $faq;
        }

        return $out;
    }

    private function extractBetween(string $raw, string $fromToken, string $toToken): ?string
    {
        $p1 = strpos($raw, $fromToken);
        if ($p1 === false) return null;
        $p2 = strpos($raw, $toToken, $p1 + strlen($fromToken));
        if ($p2 === false) return mb_substr($raw, $p1, 4500);
        return substr($raw, $p1, $p2 - $p1);
    }

    private function extractObjects(string $block): array
    {
        $m = [];
        preg_match_all('~\{[^{}]*\}~s', $block, $m);
        return $m[0] ?? [];
    }

    private function extractJsonValueLoose(string $raw, string $key): mixed
    {
        $patternStr = '~"' . preg_quote($key, '~') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)~u';
        if (preg_match($patternStr, $raw, $m)) {
            $inner = (string)($m[1] ?? '');
            $decoded = json_decode('"' . $inner . '"', true);
            return is_string($decoded) ? $decoded : stripcslashes($inner);
        }

        $patternAny = '~"' . preg_quote($key, '~') . '"\s*:\s*(\[[^\]]*\]|\{[^}]*\})~us';
        if (preg_match($patternAny, $raw, $m2)) {
            $chunk = trim((string)($m2[1] ?? ''));
            $decoded = json_decode($chunk, true);
            return $decoded ?? $chunk;
        }

        return null;
    }

    // ===========================================================
    // BRIEF
    // ===========================================================
    private function creativeBrief(string $keyword): array
    {
        $angles = [
            "Rapidez y ejecución (plazos claros, entrega sin vueltas)",
            "Calidad premium (consistencia, tono de marca, precisión)",
            "Orientado a leads (CTA, objeciones, conversión)",
            "Personalización total (sector/ciudad/propuesta única)",
            "Proceso y metodología (pasos, validación, control)",
            "Diferenciación frente a competidores (propuesta y posicionamiento)",
            "Optimización SEO natural (semántica, intención, sin stuffing)",
            "Claridad del mensaje (menos ruido, más foco)",
            "Escalabilidad (reutilizable, fácil de publicar, ordenado)",
            "Confianza sin claims falsos (transparencia, límites, expectativas)",
            "Experiencia de usuario (escaneable, móvil, comprensión rápida)",
            "Estrategia + copy (no solo texto: decisión del enfoque)",
        ];
        $tones = ["Profesional directo","Cercano y humano","Premium sobrio","Enérgico y comercial","Técnico pero simple"];
        $ctas  = ["Acción inmediata (Reserva/Agenda)","Orientado a consulta (Hablemos / Te asesoramos)","Orientado a precio/plan (Pide presupuesto)","Orientado a diagnóstico (Solicita revisión rápida)"];
        $audiences = ["Pymes y autónomos","Negocios locales","Ecommerce y servicios","Marcas en crecimiento","Profesionales independientes"];

        return [
            'angle' => $angles[random_int(0, count($angles) - 1)],
            'tone' => $tones[random_int(0, count($tones) - 1)],
            'cta' => $ctas[random_int(0, count($ctas) - 1)],
            'audience' => $audiences[random_int(0, count($audiences) - 1)],
        ];
    }

    // ===========================================================
    // PROMPTS
    // ===========================================================
    private function promptRedactorJson(string $tipo, string $keyword, string $noRepetirTitles, string $noRepetirCorpus, array $brief): string
    {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $cta   = $this->toStr($brief['cta'] ?? '');
        $aud   = $this->toStr($brief['audience'] ?? '');

        return <<<PROMPT
Devuelve SOLO JSON válido (sin markdown, sin explicación). RESPUESTA MINIFICADA (sin saltos de línea).

Keyword: {$keyword}
Tipo: {$tipo}

BRIEF:
- Ángulo: {$angle}
- Tono: {$tone}
- Público: {$aud}
- CTA: {$cta}

NO REPETIR TÍTULOS:
{$noRepetirTitles}

NO REPETIR FRASES / SUBTEMAS:
{$noRepetirCorpus}

REGLAS:
- SOLO 1 H1: solo "hero_h1". No uses <h1>.
- No “Introducción”, “Conclusión”, “¿Qué es…?”.
- No testimonios reales.
- Evita keyword stuffing.
- HTML permitido: <p>, <strong>, <br>.
- Devuelve EXACTAMENTE 4 features y EXACTAMENTE 9 FAQs.
- NO puede haber campos vacíos ni "<p></p>".
- Longitudes cortas para evitar cortes:
  hero_p_html/kit_p_html/pack_p_html <= 320 chars
  feature p_html <= 240 chars
  faq a_html <= 260 chars
  seo_title 60-65 chars

ESQUEMA EXACTO:
{"seo_title":"...","hero_h1":"...","hero_p_html":"<p>...</p>","kit_h1":"...","kit_p_html":"<p>...</p>","pack_h2":"...","pack_p_html":"<p>...</p>","features":[{"title":"...","p_html":"<p>...</p>"},{"title":"...","p_html":"<p>...</p>"},{"title":"...","p_html":"<p>...</p>"},{"title":"...","p_html":"<p>...</p>"}],"faq_title":"...","faq":[{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"}],"final_cta_h3":"..."}
PROMPT;
    }

    private function promptAuditorJson(string $tipo, string $keyword, array $draft, string $noRepetirTitles, string $noRepetirCorpus, array $brief): string
    {
        $draftShort = mb_substr(json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 6500);
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $cta   = $this->toStr($brief['cta'] ?? '');

        return <<<PROMPT
Eres editor SEO/CRO. Reescribe TODO para que sea MUY diferente y sin repetir.
Devuelve SOLO JSON válido (mismo esquema/keys). RESPUESTA MINIFICADA.
NO puede haber campos vacíos ni "<p></p>".

Keyword: {$keyword}
Tipo: {$tipo}

BRIEF:
- Ángulo: {$angle}
- Tono: {$tone}
- CTA: {$cta}

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

BORRADOR:
{$draftShort}

Reglas:
- 1 H1: solo hero_h1.
- 4 features / 9 faq exactas.
- HTML: <p>, <strong>, <br>.
- Longitudes cortas (no te pases).
PROMPT;
    }

    private function promptRepairJson(string $keyword, array $json, string $noRepetirTitles, string $noRepetirCorpus, array $brief): string
    {
        $short = mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 6500);
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');

        return <<<PROMPT
Corrige este JSON para que cumpla reglas y sea distinto.
Devuelve SOLO JSON válido con el MISMO esquema. RESPUESTA MINIFICADA.
NO puede haber campos vacíos ni "<p></p>".

Keyword: {$keyword}
Ángulo: {$angle}
Tono: {$tone}

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

JSON a reparar:
{$short}

Checklist:
- Solo 1 H1: hero_h1 (no uses <h1>)
- seo_title <= 65
- 4 features / 9 faq exactas
- HTML: <p>, <strong>, <br>
- Nada vacío / nada "<p></p>"
PROMPT;
    }

    // ===========================================================
    // Coerción segura
    // ===========================================================
    private function toStr(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        if (is_bool($v)) return $v ? '1' : '0';

        if (is_array($v)) {
            foreach (['text','content','value','html'] as $k) {
                if (array_key_exists($k, $v)) return $this->toStr($v[$k]);
            }
            $scalars = [];
            foreach ($v as $item) {
                if (is_scalar($item)) $scalars[] = (string)$item;
            }
            if (!empty($scalars)) return implode(' ', $scalars);

            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($j) ? $j : '';
        }

        if (is_object($v)) {
            if ($v instanceof \Stringable) return (string)$v;
            $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return is_string($j) ? $j : '';
        }

        return '';
    }

    // ===========================================================
    // SANITIZE / VALIDATE / FIX
    // ===========================================================
    private function isBlankText(mixed $s): bool
    {
        $s = trim(strip_tags($this->toStr($s)));
        return $s === '';
    }

    private function isBlankHtml(mixed $html): bool
    {
        $html = $this->toStr($html);
        $txt = trim(preg_replace('~\s+~u', ' ', strip_tags($html)));
        return $txt === '';
    }

    private function ensureText(mixed $value, string $fallback): string
    {
        $v = trim(strip_tags($this->toStr($value)));
        return $v === '' ? $fallback : $v;
    }

    private function ensureHtml(mixed $html, string $fallbackText): string
    {
        $html = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($html)));
        if ($this->isBlankHtml($html)) {
            $fallbackText = trim($fallbackText);
            if ($fallbackText === '') $fallbackText = 'Contenido en preparación.';
            $safe = htmlspecialchars($fallbackText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<p>' . $safe . '</p>';
        }
        return $html;
    }

    private function sanitizeAndNormalizeCopy(array $copy): array
    {
        $copy['features'] = isset($copy['features']) && is_array($copy['features']) ? $copy['features'] : [];
        $copy['faq']      = isset($copy['faq']) && is_array($copy['faq']) ? $copy['faq'] : [];

        $kw = $this->shortKw();

        $copy['hero_h1'] = $this->ensureText($copy['hero_h1'] ?? '', $kw);

        $seo = $this->ensureText($copy['seo_title'] ?? '', $copy['hero_h1']);
        if (mb_strlen($seo) > 65) {
            $seo = mb_substr($seo, 0, 65);
            $seo = rtrim($seo, " \t\n\r\0\x0B-–—|:");
        }
        if ($seo === '') $seo = $copy['hero_h1'];
        $copy['seo_title'] = $seo;

        $copy['kit_h1']       = $this->ensureText($copy['kit_h1'] ?? '', "Kit para {$kw}");
        $copy['pack_h2']      = $this->ensureText($copy['pack_h2'] ?? '', "Pack de {$kw} listo para publicar");
        $copy['faq_title']    = $this->ensureText($copy['faq_title'] ?? '', "Preguntas frecuentes sobre {$kw}");
        $copy['final_cta_h3'] = $this->ensureText($copy['final_cta_h3'] ?? '', "¿Listo para avanzar con {$kw}?");

        $copy['hero_p_html'] = $this->ensureHtml($copy['hero_p_html'] ?? '', "Una solución clara para {$kw} enfocada en conversión.");
        $copy['kit_p_html']  = $this->ensureHtml($copy['kit_p_html'] ?? '',  "Contenido pensado para implementar rápido en {$kw}.");
        $copy['pack_p_html'] = $this->ensureHtml($copy['pack_p_html'] ?? '', "Estructura y copy listos, sin huecos ni secciones vacías.");

        $copy['features'] = $this->normalizeFeatures($copy['features'], 4);
        $copy['faq']      = $this->normalizeFaq($copy['faq'], 9);

        return $copy;
    }

    private function validateAndFixCopy(array $copy): array
    {
        $copy = $this->sanitizeAndNormalizeCopy($copy);

        // fix final
        $kw = $this->shortKw();

        foreach ($copy['features'] as $i => $f) {
            $t = trim(strip_tags($this->toStr($f['title'] ?? '')));
            $p = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($f['p_html'] ?? '')));
            if ($t === '') $t = "Mejora clave para {$kw}";
            if ($this->isBlankHtml($p)) $p = "<p><strong>Qué aporta:</strong> mejora concreta en claridad y conversión para {$kw}, sin relleno.</p>";
            $copy['features'][$i] = ['title' => $t, 'p_html' => $p];
        }

        foreach ($copy['faq'] as $i => $q) {
            $qq = trim(strip_tags($this->toStr($q['q'] ?? '')));
            $aa = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($q['a_html'] ?? '')));
            if ($qq === '') $qq = "¿Cómo funciona {$kw}?";
            if ($this->isBlankHtml($aa)) $aa = "<p>Lo adaptamos a tu caso y lo dejamos listo para publicar, sin huecos.</p>";
            $copy['faq'][$i] = ['q' => $qq, 'a_html' => $aa];
        }

        if ($this->violatesSeoHardRules($copy)) {
            throw new \RuntimeException("El JSON viola reglas SEO/H1");
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

    private function violatesSeoHardRules(array $copy): bool
    {
        $all = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($all) && preg_match('~<\s*/?\s*h1\b~i', $all)) return true;

        $seo = $this->toStr($copy['seo_title'] ?? '');
        if ($seo !== '' && mb_strlen($seo) > 70) return true;

        if (trim($this->toStr($copy['hero_h1'] ?? '')) === '') return true;

        return false;
    }

    private function normalizeFeatures(array $features, int $need): array
    {
        $out = [];

        foreach ($features as $f) {
            if (!is_array($f)) continue;

            $title = trim(strip_tags($this->toStr($f['title'] ?? '')));
            $p = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($f['p_html'] ?? '')));

            if ($title === '' && $this->isBlankHtml($p)) continue;

            if ($title === '') $title = "Mejora clave para " . $this->shortKw();
            if ($this->isBlankHtml($p)) {
                $p = "<p><strong>Qué aporta:</strong> mejora concreta en claridad y conversión para {$this->shortKw()}.</p>";
            }

            $out[] = ['title' => $title, 'p_html' => $p];
        }

        if (count($out) > $need) $out = array_slice($out, 0, $need);

        $fallbackTitles = [
            "Plan a medida","Ejecución sin fricción","Mensaje con intención","Diferenciación real",
            "Optimización orientada a leads","Entrega lista para implementar","Iteración y mejora",
            "Consistencia de marca","Claridad en la propuesta","Alineación con el usuario",
        ];

        while (count($out) < $need) {
            $pick = $fallbackTitles[random_int(0, count($fallbackTitles) - 1)];
            $out[] = [
                'title' => $pick . " para " . $this->shortKw(),
                'p_html' => "<p><strong>Qué aporta:</strong> mejora concreta en claridad y conversión, adaptado a {$this->shortKw()} sin repetir fórmulas.</p>",
            ];
        }

        return $out;
    }

    private function normalizeFaq(array $faq, int $need): array
    {
        $out = [];

        foreach ($faq as $q) {
            if (!is_array($q)) continue;

            $qq = trim(strip_tags($this->toStr($q['q'] ?? '')));
            $aa = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($q['a_html'] ?? '')));

            if ($qq === '' && $this->isBlankHtml($aa)) continue;

            if ($qq === '') $qq = "¿Cómo funciona " . $this->shortKw() . "?";
            if ($this->isBlankHtml($aa)) $aa = "<p>Lo adaptamos a tu caso y lo dejamos listo para publicar, sin huecos.</p>";

            $out[] = ['q' => $qq, 'a_html' => $aa];
        }

        if (count($out) > $need) $out = array_slice($out, 0, $need);

        $templatesQ = [
            "¿En cuánto tiempo se notan resultados con {kw}?",
            "¿Qué incluye exactamente el servicio de {kw}?",
            "¿Necesito tener web lista antes de contratar {kw}?",
            "¿Cómo se define el plan y los entregables de {kw}?",
            "¿Qué información tengo que aportar para empezar {kw}?",
            "¿Se puede adaptar {kw} a mi sector o ciudad?",
            "¿Cómo evitamos contenido duplicado con {kw}?",
            "¿Qué diferencia a {kw} de una solución genérica?",
            "¿Puedo publicar yo mismo o me lo dejáis listo?",
            "¿Qué pasa si quiero cambios después de la entrega?",
            "¿Qué no incluye {kw} para evitar falsas expectativas?",
        ];

        $templatesA = [
            "<p>Depende del punto de partida, pero lo normal es ver señales en <strong>2–4 semanas</strong> y mejoras claras en <strong>6–12 semanas</strong>.</p>",
            "<p>Incluye planificación y copy orientado a conversión, con estructura lista para publicar. Todo se redacta desde cero.</p>",
            "<p>No necesariamente. Podemos trabajar con una base mínima y dejarte contenido + estructura listos para implementar.</p>",
            "<p>Definimos objetivo, enfoque y entregables. No es “texto bonito”: es una pieza diseñada para convertir.</p>",
            "<p>Con tu oferta, público ideal y 2–3 referencias arrancamos. Si falta claridad, lo construimos contigo en un brief rápido.</p>",
            "<p>Sí. Ajustamos el mensaje a tu sector/tono/zona y usamos variaciones semánticas naturales (sin stuffing).</p>",
            "<p>Cada pieza lleva ángulo y estructura propia, y se compara contra lo generado antes para forzar variedad real.</p>",
            "<p>Una solución genérica repite frases. Aquí se trabaja con intención, objeciones y diferenciadores reales.</p>",
            "<p>Te lo dejamos listo para publicar. Si lo prefieres, lo enviamos directo a WordPress con formato limpio.</p>",
            "<p>Se contempla una ronda de ajustes razonables. Cambios grandes se planifican como mejora para mantener coherencia.</p>",
            "<p>Para evitar expectativas irreales, dejamos claro alcance, tiempos y entregables. Lo extra se planifica aparte.</p>",
        ];

        while (count($out) < $need) {
            $qTpl = $templatesQ[random_int(0, count($templatesQ) - 1)];
            $aTpl = $templatesA[random_int(0, count($templatesA) - 1)];
            $out[] = [
                'q' => str_replace('{kw}', $this->shortKw(), $qTpl),
                'a_html' => $aTpl,
            ];
        }

        return $out;
    }

    private function shortKw(): string
    {
        $kw = trim((string)$this->keyword);
        if ($kw === '') return 'tu proyecto';
        return mb_substr($kw, 0, 70);
    }

    // ===========================================================
    // ANTI-REPETICIÓN
    // ===========================================================
    private function isTooSimilarToAnyPrevious(array $copy, array $usedTitles, array $usedCorpus): bool
    {
        $title = mb_strtolower(trim($this->toStr($copy['seo_title'] ?? $copy['hero_h1'] ?? '')));
        if ($title !== '') {
            foreach ($usedTitles as $t) {
                $t2 = mb_strtolower(trim((string)$t));
                if ($t2 !== '' && $this->jaccardBigrams($title, $t2) >= 0.65) {
                    return true;
                }
            }
        }

        $text = $this->copyTextFromArray($copy);
        foreach ($usedCorpus as $corp) {
            $corp = trim((string)$corp);
            if ($corp === '') continue;
            if ($this->jaccardBigrams($text, $corp) >= 0.50) return true;
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
        return $u === 0 ? 0.0 : ($i / $u);
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
        for ($i = 0; $i < $n - 1; $i++) {
            $out[$chars[$i] . $chars[$i + 1]] = 1;
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
            $parts[] = strip_tags($this->toStr($copy[$k] ?? ''));
        }
        if (!empty($copy['features']) && is_array($copy['features'])) {
            foreach ($copy['features'] as $f) {
                if (!is_array($f)) continue;
                $parts[] = strip_tags($this->toStr($f['title'] ?? ''));
                $parts[] = strip_tags($this->toStr($f['p_html'] ?? ''));
            }
        }
        if (!empty($copy['faq']) && is_array($copy['faq'])) {
            foreach ($copy['faq'] as $q) {
                if (!is_array($q)) continue;
                $parts[] = strip_tags($this->toStr($q['q'] ?? ''));
                $parts[] = strip_tags($this->toStr($q['a_html'] ?? ''));
            }
        }
        $txt = implode(' ', array_filter($parts));
        $txt = preg_replace('~\s+~u', ' ', $txt);
        return trim((string)$txt);
    }

    // ===========================================================
    // TOKENS v4: reemplazo GLOBAL
    // ===========================================================
    private function fillElementorTemplate_byPrettyTokens_withStats(array $tpl, array $copy): array
    {
        $dict = $this->buildPrettyTokenDictionary($copy);

        $replacedCount = 0;
        $this->replaceTokensDeep($tpl, $dict, $replacedCount);

        $remaining = $this->collectRemainingTokensDeep($tpl);

        return [$tpl, $replacedCount, $remaining];
    }

    private function replaceTokensDeep(mixed &$node, array $dict, int &$count): void
    {
        if (is_array($node)) {
            foreach ($node as $k => &$v) {
                $this->replaceTokensDeep($v, $dict, $count);
            }
            return;
        }

        if (!is_string($node) || $node === '') return;
        if (!str_contains($node, '{{')) return;

        $orig = $node;
        $node = strtr($node, $dict);
        if ($node !== $orig) $count++;
    }

    private function collectRemainingTokensDeep(mixed $node): array
    {
        $found = [];

        $walk = function ($n) use (&$walk, &$found) {
            if (is_array($n)) {
                foreach ($n as $v) $walk($v);
                return;
            }
            if (!is_string($n) || $n === '') return;

            if (preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $n, $m)) {
                foreach ($m[0] as $tok) $found[] = $tok;
            }
        };

        $walk($node);
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    private function buildPrettyTokenDictionary(array $copy): array
    {
        $kw = $this->shortKw();

        $heroH1 = trim(strip_tags($this->toStr($copy['hero_h1'] ?? $kw)));
        $heroP  = $this->ensureHtml($copy['hero_p_html'] ?? '', "Una propuesta clara para {$kw} con foco en conversión.");
        $kitH1  = trim(strip_tags($this->toStr($copy['kit_h1'] ?? "Kit para {$kw}")));
        $kitP   = $this->ensureHtml($copy['kit_p_html'] ?? '', "Implementación rápida y mensaje consistente para {$kw}.");
        $packH2 = trim(strip_tags($this->toStr($copy['pack_h2'] ?? "Pack de {$kw} listo para publicar")));
        $packP  = $this->ensureHtml($copy['pack_p_html'] ?? '', "Estructura y copy listos para publicar, sin secciones vacías.");
        $faqT   = trim(strip_tags($this->toStr($copy['faq_title'] ?? "Preguntas frecuentes sobre {$kw}")));
        $finalCta = trim(strip_tags($this->toStr($copy['final_cta_h3'] ?? "¿Listo para avanzar con {$kw}?")));

        // Para plantilla 65: lista de features en bloque editor
        $featuresListHtml = $this->buildFeaturesListHtml($copy);

        // Precio / oferta (plantilla 65 usa PRICE_H2)
        $priceH2 = $this->pick([
            "Tu web profesional desde tan solo 500 €",
            "Web lista para convertir desde 500 €",
            "Tu web optimizada desde 500 €",
            "Lanza tu web profesional desde 500 €",
        ]);

        // Kit Digital (plantilla 65)
        $kitDigitalBold = "Kit Digital";
        $kitDigitalP = "<p>Consigue tu página web con el bono digital. Te ayudamos con la solicitud y dejamos tu web lista para captar clientes.</p>";
        $btnKitDigital = $this->pick(["Acceder al Kit Digital","Ver Kit Digital","Solicitar Kit Digital"]);

        $dict = [
            // HERO / intro
            '{{HERO_KICKER}}' => $this->pick(["Sitio web corporativo","Diseño web profesional","Web para captar clientes","Página corporativa optimizada"]),
            '{{HERO_H1}}'     => $heroH1,
            '{{HERO_P}}'      => $heroP,

            // PACK / precio
            '{{PACK_H2}}'   => $packH2,
            '{{PACK_P}}'    => $packP,
            '{{PRICE_H2}}'  => $priceH2,

            // KIT (plantilla 64)
            '{{KIT_H1}}' => $kitH1,
            '{{KIT_P}}'  => $kitP,

            // Features (plantillas 64/65)
            '{{FEATURE_1_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][0]['title'] ?? "Beneficio 1 para {$kw}"))),
            '{{FEATURE_1_P}}'     => $this->ensureHtml($copy['features'][0]['p_html'] ?? '', "Mejora concreta para {$kw} enfocada en conversión."),
            '{{FEATURE_2_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][1]['title'] ?? "Beneficio 2 para {$kw}"))),
            '{{FEATURE_2_P}}'     => $this->ensureHtml($copy['features'][1]['p_html'] ?? '', "Más claridad y mejores decisiones para {$kw}."),
            '{{FEATURE_3_P}}'     => $this->ensureHtml($copy['features'][2]['p_html'] ?? '', "Ejecución limpia y lista para publicar en {$kw}."),
            '{{FEATURE_4_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][3]['title'] ?? "Beneficio 4 para {$kw}"))),
            '{{FEATURE_4_P}}'     => $this->ensureHtml($copy['features'][3]['p_html'] ?? '', "Optimización para que {$kw} convierta mejor."),
            '{{FEATURES_LIST_HTML}}' => $featuresListHtml,

            // Clientes / reviews (plantilla 64)
            '{{CLIENTS_LABEL}}'    => $this->pick(["Clientes","Marcas","Empresas","Negocios"]),
            '{{CLIENTS_SUBTITLE}}' => $this->pick([
                "Empresas en toda España y el mundo ya confían en nosotros",
                "Negocios que buscan resultados ya trabajan con nosotros",
                "Equipos que apuestan por rendimiento y claridad",
            ]),
            '{{REVIEWS_LABEL}}'     => $this->pick(["Opiniones","Reseñas","Valoraciones"]),
            '{{TESTIMONIOS_TITLE}}' => $this->pick(["Testimonios","Lo que dicen nuestros clientes","Experiencias de clientes"]),
            '{{PROJECTS_TITLE}}'    => $this->pick([
                "Proyectos Web Realizados: Diseño, Desarrollo y Resultados",
                "Trabajos publicados: estructura, rendimiento y conversión",
                "Casos de web: enfoque y ejecución",
            ]),

            // FAQ
            '{{FAQ_TITLE}}' => $faqT,

            // CTA final
            '{{FINAL_CTA}}' => $finalCta,

            // Botones
            '{{BTN_PRESUPUESTO}}' => $this->pick(["Solicitar presupuesto","Pedir presupuesto","Solicitar propuesta"]),
            '{{BTN_REUNION}}'     => $this->pick(["Reservar llamada","Agendar reunión","Agendar llamada","Hablar con un experto"]),

            // Kit Digital (plantilla 65)
            '{{KITDIGITAL_BOLD}}' => $kitDigitalBold,
            '{{KITDIGITAL_P}}'    => $kitDigitalP,
            '{{BTN_KITDIGITAL}}'  => $btnKitDigital,
        ];

        // FAQ 1..9 (plantillas 64/65)
        for ($i = 0; $i < 9; $i++) {
            $q = trim(strip_tags($this->toStr($copy['faq'][$i]['q'] ?? "Pregunta " . ($i + 1) . " sobre {$kw}")));
            $a = $this->ensureHtml($copy['faq'][$i]['a_html'] ?? '', "Te guiamos paso a paso para que {$kw} quede listo y sin huecos.");
            $dict['{{FAQ_' . ($i + 1) . '_Q}}'] = $q;
            $dict['{{FAQ_' . ($i + 1) . '_A}}'] = $a;
        }

        return $dict;
    }

    private function buildFeaturesListHtml(array $copy): string
    {
        $kw = $this->shortKw();
        $features = isset($copy['features']) && is_array($copy['features']) ? $copy['features'] : [];

        $parts = [];
        for ($i = 0; $i < min(4, count($features)); $i++) {
            $t = trim(strip_tags($this->toStr($features[$i]['title'] ?? '')));
            $p = trim(strip_tags($this->toStr($features[$i]['p_html'] ?? '')));
            if ($t === '') $t = "Mejora clave para {$kw}";
            if ($p === '') $p = "Mejora concreta enfocada en claridad y conversión.";
            $tSafe = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $pSafe = htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = "<p><strong>{$tSafe}:</strong> {$pSafe}</p>";
        }

        if (empty($parts)) {
            return "<p><strong>Qué incluye:</strong> estructura y copy listos para convertir, adaptados a {$kw}.</p>";
        }

        return implode('', $parts);
    }

    // ===========================================================
    // Post-pass (fallback)
    // ===========================================================
    private function forceReplaceStaticTextsInTemplate(array $tpl, array $copy): array
    {
        $kw = $this->shortKw();

        $mapExact = [
            "Solicitar presupuesto" => $this->pick(["Solicitar presupuesto","Pedir presupuesto","Solicitar propuesta"]),
            "Reservar llamada" => $this->pick(["Reservar llamada","Agendar llamada","Hablar con un experto"]),
            "Agendar reunion" => $this->pick(["Agendar reunión","Agendar llamada","Reservar llamada"]),
            "Agendar reunión" => $this->pick(["Agendar reunión","Reservar llamada","Agendar llamada"]),
            "¿Listo para avanzar con agencias de publicidad?" => trim(strip_tags($this->toStr($copy['final_cta_h3'] ?? "¿Listo para avanzar con {$kw}?"))),
        ];

        $count = 0;
        $this->replaceStringsRecursive($tpl, $mapExact, $count);

        return [$tpl, $count];
    }

    private function replaceStringsRecursive(mixed &$node, array $mapExact, int &$count): void
    {
        if (is_array($node)) {
            foreach ($node as &$v) {
                $this->replaceStringsRecursive($v, $mapExact, $count);
            }
            return;
        }

        if (!is_string($node) || $node === '') return;

        $orig = $node;
        $trim = trim($node);

        if (isset($mapExact[$trim])) {
            $node = $mapExact[$trim];
        } else {
            foreach ($mapExact as $from => $to) {
                if ($from === '') continue;
                if (str_contains($node, $from)) {
                    $node = str_replace($from, $to, $node);
                }
            }
        }

        if ($node !== $orig) $count++;
    }

    // ===========================================================
    // Utils
    // ===========================================================
    private function pick(array $arr): string
    {
        return $arr[random_int(0, count($arr) - 1)];
    }
}
