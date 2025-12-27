<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public $timeout = 4200;
    public $tries   = 5;
    public $backoff = [60, 120, 300, 600, 900];

    public string $jobUuid;
    public ?int $registroId = null;

    /** cache interno */
    private array $briefContext = [];

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {
        // ✅ IMPORTANTÍSIMO: UUID REAL por ejecución para que NO “re-use” registros viejos
        // (Laravel reintenta el mismo Job, así que dentro de retries el UUID se mantiene)
        $this->jobUuid = (string) Str::uuid();
    }

    public function handle(): void
    {
        $registro = null;

        try {
            // ===========================================================
            // 1) Crear registro SIEMPRE (nuevo) y marcar en proceso
            // ===========================================================
            $registro = Dominios_Contenido_DetallesModel::create([
                'job_uuid'              => $this->jobUuid,
                'id_dominio_contenido'  => (int)$this->idDominioContenido,
                'id_dominio'            => (int)$this->idDominio,
                'tipo'                  => $this->tipo,
                'keyword'               => $this->keyword,
                'estatus'               => 'en_proceso',
                'modelo'                => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                'error'                 => null,
            ]);

            $this->registroId = (int) $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // 2) Config IA
            // ===========================================================
            $apiKey = (string) env('DEEPSEEK_API_KEY', '');
            $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');

            if ($apiKey === '') {
                throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');
            }

            // ===========================================================
            // 3) Cargar template y detectar tokens reales
            // ===========================================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);

            // tokens: array tokenName => ['wrap_p' => bool, 'expects_html' => bool]
            $tokenMeta = $this->extractTokenMetaFromTemplate($tpl);
            $tokenNames = array_keys($tokenMeta);

            if (empty($tokenNames)) {
                throw new \RuntimeException("NO_RETRY: La plantilla no contiene tokens {{TOKEN}}. Template: {$tplPath}");
            }

            // ===========================================================
            // 4) Historial anti-repetición (opcional, pero ayuda)
            // ===========================================================
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

            // ===========================================================
            // 5) Generar valores IA para TODOS los tokens detectados
            // ===========================================================
            $brief = $this->creativeBrief($this->keyword);
            $this->briefContext = $brief;

            $values = $this->generateValuesForTemplateTokens(
                apiKey: $apiKey,
                model: $model,
                tokenMeta: $tokenMeta,
                noRepetirTitles: $noRepetirTitles,
                noRepetirCorpus: $noRepetirCorpus,
                brief: $brief
            );

            // ===========================================================
            // 6) Reemplazar tokens en la plantilla
            // ===========================================================
            [$filled, $replacedCount, $remaining] = $this->fillElementorTemplate_byDetectedTokens_withStats($tpl, $values);

            if ($replacedCount < 1) {
                // Esto indica mismatch entre dict y plantilla
                $some = array_slice($tokenNames, 0, 40);
                throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}. Tokens detectados (muestra): " . implode(', ', $some));
            }
            if (!empty($remaining)) {
                throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remaining, 0, 80)));
            }

            // ===========================================================
            // 7) Title + slug
            // ===========================================================
            $titleCandidate = $values['SEO_TITLE'] ?? $values['HERO_H1'] ?? $this->keyword;
            $title = trim(strip_tags((string)$titleCandidate));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // 8) Guardar
            // ===========================================================
            $registro->update([
                'title'          => $title,
                'slug'           => $slug,
                'draft_html'     => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'contenido_html' => json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estatus'        => 'generado',
                'error'          => null,
            ]);

        } catch (\Throwable $e) {
            if ($registro) {
                $isLast = ($this->attempts() >= (int)$this->tries);
                $noRetry = str_contains($e->getMessage(), 'NO_RETRY:');

                $registro->update([
                    'estatus' => ($noRetry || $isLast) ? 'error_final' : 'error',
                    'error'   => $e->getMessage() . ' | attempts=' . $this->attempts(),
                ]);

                if ($noRetry) {
                    $this->fail($e);
                    return;
                }
            }
            throw $e;
        }
    }

    // ===========================================================
    // TEMPLATE LOADER
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) throw new \RuntimeException("NO_RETRY: Dominio no encontrado (id={$idDominio})");

        // 1) dominio->elementor_template_path
        // 2) env('ELEMENTOR_TEMPLATE_PATH')
        // 3) env('ELEMENTOR_TEMPLATE_DEFAULT') (para estandarizar)
        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));
        if ($templateRel === '') $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_PATH', ''));
        if ($templateRel === '') $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_DEFAULT', ''));

        if ($templateRel === '') {
            throw new \RuntimeException('NO_RETRY: No hay plantilla configurada (dominio ni env ELEMENTOR_TEMPLATE_PATH/ELEMENTOR_TEMPLATE_DEFAULT).');
        }

        // limpiar URL si viene completa
        $templateRel = str_replace(['https:', 'http:'], '', $templateRel);
        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) {
            throw new \RuntimeException('NO_RETRY: Template path inválido (no se permite "..")');
        }

        $templatePath = storage_path('app/' . $templateRel);
        if (!is_file($templatePath)) {
            throw new \RuntimeException("NO_RETRY: No existe el template en disco: {$templatePath}");
        }

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('NO_RETRY: Template Elementor inválido: debe contener "content" (array).');
        }

        return [$tpl, $templatePath];
    }

    // ===========================================================
    // Detectar tokens reales {{TOKEN}} dentro del JSON del template
    // Devuelve: tokenName => ['wrap_p'=>bool, 'expects_html'=>bool]
    // ===========================================================
    private function extractTokenMetaFromTemplate(array $tpl): array
    {
        $meta = [];

        $walk = function ($n) use (&$walk, &$meta) {
            if (is_array($n)) {
                foreach ($n as $v) $walk($v);
                return;
            }
            if (!is_string($n) || $n === '') return;

            if (!str_contains($n, '{{')) return;

            if (preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $n, $m)) {
                foreach ($m[1] as $tokenName) {
                    $tokenName = (string)$tokenName;

                    $wrapP = (bool) preg_match('~<p>\s*\{\{' . preg_quote($tokenName, '~') . '\}\}\s*</p>~i', $n);

                    // Heurística:
                    // - si el token acaba en _P o _HTML o FINAL_CTA => suele ser HTML
                    // - PERO si ya está envuelto en <p>{{TOKEN}}</p>, entonces ese token debe ser texto (sin <p>)
                    $expectsHtml = false;
                    if (preg_match('/(_P|_HTML)$/', $tokenName) || in_array($tokenName, ['FINAL_CTA'], true)) {
                        $expectsHtml = true;
                    }
                    if ($wrapP) $expectsHtml = false;

                    if (!isset($meta[$tokenName])) {
                        $meta[$tokenName] = ['wrap_p' => $wrapP, 'expects_html' => $expectsHtml];
                    } else {
                        // si aparece en otro sitio, mantener wrap_p si alguna vez fue true
                        $meta[$tokenName]['wrap_p'] = $meta[$tokenName]['wrap_p'] || $wrapP;
                        // expects_html si alguna vez es true, pero wrap_p manda
                        $meta[$tokenName]['expects_html'] = ($meta[$tokenName]['expects_html'] || $expectsHtml) && !$meta[$tokenName]['wrap_p'];
                    }
                }
            }
        };

        $walk($tpl);

        ksort($meta);
        return $meta;
    }

    // ===========================================================
    // Generar valores IA para todos los tokens detectados
    // ===========================================================
    private function generateValuesForTemplateTokens(
        string $apiKey,
        string $model,
        array $tokenMeta,
        string $noRepetirTitles,
        string $noRepetirCorpus,
        array $brief
    ): array {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $cta   = $this->toStr($brief['cta'] ?? '');
        $aud   = $this->toStr($brief['audience'] ?? '');

        $tokens = array_keys($tokenMeta);

        // construir “especificación” compacta por token
        $specLines = [];
        foreach ($tokenMeta as $name => $m) {
            $type = $m['expects_html'] ? 'HTML(<p><strong><br>)' : 'TEXTO';
            if (!empty($m['wrap_p'])) $type = 'TEXTO (ya envuelto en <p> en plantilla)';
            $specLines[] = "- {$name}: {$type}";
        }
        $spec = implode("\n", $specLines);

        $keysCsv = implode(', ', $tokens);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. Sin explicaciones.
Idioma: ES.

Contexto:
- Keyword: {$this->keyword}
- Tipo: {$this->tipo}

BRIEF:
- Ángulo: {$angle}
- Tono: {$tone}
- Público: {$aud}
- CTA: {$cta}

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

REGLAS:
- Devuelve un objeto JSON con EXACTAMENTE estas keys (sin llaves {{}}): {$keysCsv}
- PROHIBIDO dejar valores vacíos: nada de "" ni null.
- NO uses <h1> ni etiquetas fuera de <p>, <strong>, <br> cuando el tipo sea HTML.
- Si el tipo del token es TEXTO: NO uses HTML, solo texto plano.
- Evita repetir la keyword en cada línea; usa variaciones y sinónimos.
- Que SECTION_* sea contenido real (títulos y párrafos útiles), no “Sección X” ni repetir keyword.

ESPECIFICACIÓN DE TIPOS:
{$spec}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3600, temperature: 0.65, topP: 0.90, jsonMode: true);

        // parse + repair si viene roto
        $values = $this->safeParseTokenValuesOrRepair($apiKey, $model, $raw, $tokens, $brief);

        // normalizar / sanitizar / asegurar que no queden vacíos
        $values = $this->normalizeTokenValues($values, $tokenMeta);

        // Asegurar que existan todas las keys
        foreach ($tokens as $k) {
            if (!array_key_exists($k, $values)) {
                $values[$k] = $this->fallbackToken($k, $tokenMeta[$k] ?? ['expects_html'=>false,'wrap_p'=>false]);
            }
        }

        // Validación final: no vacíos
        foreach ($tokens as $k) {
            $v = $values[$k] ?? '';
            if ($this->isEmptyTokenValue($v)) {
                $values[$k] = $this->fallbackToken($k, $tokenMeta[$k] ?? ['expects_html'=>false,'wrap_p'=>false]);
            }
        }

        return $values;
    }

    private function normalizeTokenValues(array $values, array $tokenMeta): array
    {
        foreach ($values as $k => $v) {
            $vStr = $this->toStr($v);

            $m = $tokenMeta[$k] ?? ['expects_html' => false, 'wrap_p' => false];

            if (!empty($m['expects_html'])) {
                // limpiar + forzar <p> si viene texto plano
                $vStr = $this->keepAllowedInlineHtml($this->stripH1Tags($vStr));
                if ($this->isBlankHtml($vStr)) {
                    $vStr = $this->fallbackToken($k, $m);
                }
                // asegurar que tenga <p> al menos una vez
                if (!preg_match('~<p\b~i', $vStr)) {
                    $vStr = '<p>' . htmlspecialchars(trim(strip_tags($vStr)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
                }
            } else {
                // texto plano
                $vStr = trim(strip_tags($vStr));
                $vStr = preg_replace('~\s+~u', ' ', $vStr);
                $vStr = trim((string)$vStr);

                if ($vStr === '') {
                    $vStr = $this->fallbackToken($k, $m);
                    $vStr = trim(strip_tags($vStr));
                }
            }

            $values[$k] = $vStr;
        }

        return $values;
    }

    private function isEmptyTokenValue(mixed $v): bool
    {
        $s = trim(strip_tags($this->toStr($v)));
        return $s === '';
    }

    private function fallbackToken(string $tokenName, array $meta): string
    {
        $kw = $this->shortKw();

        $isHtml = !empty($meta['expects_html']);

        // Fallbacks “inteligentes” por patrón
        if (preg_match('/^BTN_/', $tokenName)) {
            return match ($tokenName) {
                'BTN_PRESUPUESTO' => 'Solicitar presupuesto',
                'BTN_REUNION'     => 'Agendar llamada',
                'BTN_KITDIGITAL'  => 'Ver información',
                default           => 'Ver opciones',
            };
        }

        if (preg_match('/^HERO_H1$/', $tokenName)) {
            return "{$kw} con estructura y copy que convierten";
        }

        if (preg_match('/^HERO_KICKER$/', $tokenName)) {
            return $this->pick(["Web que convierte", "Estructura sólida", "Mensaje claro", "Optimizado para leads"]);
        }

        if (preg_match('/^PACK_H2$/', $tokenName)) {
            return "Estructura y copy para {$kw}";
        }

        if (preg_match('/^FAQ_TITLE$/', $tokenName)) {
            return "Preguntas frecuentes";
        }

        if (preg_match('/^FINAL_CTA$/', $tokenName)) {
            return "<p>¿Quieres publicarlo y avanzar? <strong>Te ayudamos con el siguiente paso.</strong></p>";
        }

        if (preg_match('/^CONT_/', $tokenName)) {
            return $isHtml ? "<p>Bloque de contenido para {$kw}, claro y accionable.</p>" : "Bloque de contenido para {$kw}";
        }

        if (preg_match('/^SECTION_\d+_TITLE$/', $tokenName)) {
            return $this->pick([
                "Cómo trabajamos en {$kw}",
                "Entregables incluidos",
                "Proceso de implementación",
                "Qué mejora en la página",
                "Errores comunes que evitamos",
                "Para quién es ideal",
                "Plan y tiempos",
                "Enfoque y estrategia",
            ]);
        }

        if (preg_match('/^SECTION_\d+_P$/', $tokenName)) {
            $txt = "Texto claro y útil para {$kw}: beneficios, proceso y siguiente paso sin relleno.";
            if (!empty($meta['wrap_p'])) return $txt;
            return "<p>{$txt}</p>";
        }

        // genérico
        if ($isHtml) return "<p>Contenido para {$kw} listo para adaptar y publicar.</p>";
        return "Contenido para {$kw} listo para publicar";
    }

    // ===========================================================
    // Reemplazo por tokens detectados
    // ===========================================================
    private function fillElementorTemplate_byDetectedTokens_withStats(array $tpl, array $values): array
    {
        $dict = [];
        foreach ($values as $tokenName => $val) {
            $dict['{{' . $tokenName . '}}'] = $val;
        }

        $replacedCount = 0;
        $this->replaceTokensDeep($tpl, $dict, $replacedCount);
        $remaining = $this->collectRemainingTokensDeep($tpl);

        return [$tpl, $replacedCount, $remaining];
    }

    private function replaceTokensDeep(mixed &$node, array $dict, int &$count): void
    {
        if (is_array($node)) {
            foreach ($node as &$v) $this->replaceTokensDeep($v, $dict, $count);
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
            if (is_array($n)) { foreach ($n as $v) $walk($v); return; }
            if (!is_string($n) || $n === '') return;
            if (preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $n, $m)) foreach ($m[0] as $tok) $found[] = $tok;
        };
        $walk($node);
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
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
    ): string {
        $nonce = 'nonce:' . Str::uuid()->toString();

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Devuelves SOLO JSON válido. No markdown. No explicaciones.'],
                ['role' => 'user', 'content' => $prompt . "\n\n" . $nonce],
            ],
            'temperature' => $temperature,
            'top_p' => $topP,
            'presence_penalty' => 1.0,
            'frequency_penalty' => 0.4,
            'max_tokens' => $maxTokens,
        ];

        if ($jsonMode) $payload['response_format'] = ['type' => 'json_object'];

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout(15)
            ->timeout(180)
            ->retry(0, 0)
            ->post('https://api.deepseek.com/v1/chat/completions', $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("DeepSeek error {$resp->status()}: {$resp->body()}");
        }

        $data = $resp->json();
        $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') throw new \RuntimeException("DeepSeek returned empty text.");

        return $text;
    }

    // ===========================================================
    // Parse robusto para JSON grande (tokens)
    // ===========================================================
    private function safeParseTokenValuesOrRepair(
        string $apiKey,
        string $model,
        string $raw,
        array $expectedKeys,
        array $brief
    ): array {
        try {
            $a = $this->parseJsonStrict($raw);
            return $this->keepOnlyExpectedKeys($a, $expectedKeys);
        } catch (\Throwable $e) {
            // 1) intentar “loose” por regex key/value
            $loose = $this->parseJsonLooseByKeys($raw, $expectedKeys);
            if (count($loose) >= max(3, (int)floor(count($expectedKeys) * 0.25))) {
                return $loose;
            }

            // 2) pedir reparación a DeepSeek
            $fixed = $this->repairTokenJsonViaDeepseek($apiKey, $model, $raw, $expectedKeys, $brief);
            try {
                $b = $this->parseJsonStrict($fixed);
                return $this->keepOnlyExpectedKeys($b, $expectedKeys);
            } catch (\Throwable $e2) {
                $loose2 = $this->parseJsonLooseByKeys($fixed, $expectedKeys);
                if (!empty($loose2)) return $loose2;

                $snip = mb_substr(trim((string)$raw), 0, 700);
                throw new \RuntimeException('DeepSeek no devolvió JSON válido. Snippet: ' . $snip);
            }
        }
    }

    private function keepOnlyExpectedKeys(array $arr, array $expectedKeys): array
    {
        $out = [];
        $set = array_fill_keys($expectedKeys, true);

        foreach ($arr as $k => $v) {
            $k2 = (string)$k;
            if (isset($set[$k2])) $out[$k2] = $v;
        }

        return $out;
    }

    private function repairTokenJsonViaDeepseek(string $apiKey, string $model, string $broken, array $expectedKeys, array $brief): string
    {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');

        $broken = mb_substr(trim((string)$broken), 0, 12000);
        $keysCsv = implode(', ', $expectedKeys);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. RESPUESTA MINIFICADA.
Corrige el JSON roto y devuélvelo como objeto con EXACTAMENTE estas keys:
{$keysCsv}

Reglas:
- Prohibido dejar valores vacíos.
- Si una key falta o viene vacía, complétala con contenido útil según la keyword.
- No uses <h1>.
- Para valores HTML solo <p>, <strong>, <br>.

Keyword: {$this->keyword}
Ángulo: {$angle}
Tono: {$tone}

JSON roto:
{$broken}
PROMPT;

        return $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3600, temperature: 0.15, topP: 0.90, jsonMode: true);
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

    private function parseJsonLooseByKeys(string $raw, array $keys): array
    {
        $raw = trim((string)$raw);
        $raw = preg_replace('~^```(?:json)?\s*~i', '', $raw);
        $raw = preg_replace('~\s*```$~', '', $raw);
        $raw = trim($raw);

        $pos = strpos($raw, '{');
        if ($pos !== false) $raw = substr($raw, $pos);

        $out = [];
        foreach ($keys as $key) {
            $val = $this->extractJsonValueLoose($raw, (string)$key);
            if ($val !== null) $out[(string)$key] = $val;
        }
        return $out;
    }

    private function extractJsonValueLoose(string $raw, string $key): mixed
    {
        // string
        $patternStr = '~"' . preg_quote($key, '~') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)~u';
        if (preg_match($patternStr, $raw, $m)) {
            $inner = (string)($m[1] ?? '');
            $decoded = json_decode('"' . $inner . '"', true);
            return is_string($decoded) ? $decoded : stripcslashes($inner);
        }

        // html-ish or other (non quoted) - keep chunk
        $patternAny = '~"' . preg_quote($key, '~') . '"\s*:\s*(\[[^\]]*\]|\{[^}]*\}|[^,\}\n]+)~us';
        if (preg_match($patternAny, $raw, $m2)) {
            $chunk = trim((string)($m2[1] ?? ''));
            $decoded = json_decode($chunk, true);
            return $decoded ?? trim($chunk, "\" \t\r\n");
        }

        return null;
    }

    // ===========================================================
    // Brief creativo
    // ===========================================================
    private function creativeBrief(string $keyword): array
    {
        $angles = [
            "Rapidez y ejecución (plazos claros, entrega sin vueltas)",
            "Orientado a leads (CTA, objeciones, conversión)",
            "Personalización (sector/ciudad/propuesta)",
            "Optimización SEO natural (semántica, intención, sin stuffing)",
            "Claridad del mensaje (menos ruido, más foco)",
        ];
        $tones = ["Profesional directo","Cercano y humano","Sobrio","Enérgico","Técnico simple"];
        $ctas  = ["Reserva/Agenda","Consulta","Presupuesto","Diagnóstico"];
        $audiences = ["Pymes","Negocio local","Servicios","Marcas en crecimiento","Profesionales"];

        return [
            'angle'    => $angles[random_int(0, count($angles) - 1)],
            'tone'     => $tones[random_int(0, count($tones) - 1)],
            'cta'      => $ctas[random_int(0, count($ctas) - 1)],
            'audience' => $audiences[random_int(0, count($audiences) - 1)],
        ];
    }

    // ===========================================================
    // Utils
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

    private function copyTextFromDraftJson(string $draftJson): string
    {
        $draftJson = trim((string)$draftJson);
        if ($draftJson === '') return '';
        $arr = json_decode($draftJson, true);
        if (!is_array($arr)) return '';
        $parts = [];
        foreach ($arr as $k => $v) {
            $parts[] = strip_tags($this->toStr($v));
        }
        $txt = implode(' ', array_filter($parts));
        $txt = preg_replace('~\s+~u', ' ', $txt);
        return trim((string)$txt);
    }

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

    private function pick(array $arr): string
    {
        return $arr[random_int(0, count($arr) - 1)];
    }

    private function shortKw(): string
    {
        $kw = trim((string)$this->keyword);
        if ($kw === '') return 'tu proyecto';
        return mb_substr($kw, 0, 70);
    }

    private function isBlankHtml(string $html): bool
    {
        $txt = trim(preg_replace('~\s+~u', ' ', strip_tags($html)));
        return $txt === '';
    }

    private function stripH1Tags(string $html): string
    {
        $html = preg_replace('~<\s*h1\b[^>]*>~i', '', $html);
        $html = preg_replace('~<\s*/\s*h1\s*>~i', '', $html);
        return (string)$html;
    }

    private function keepAllowedInlineHtml(string $html): string
    {
        $clean = strip_tags((string)$html, '<p><strong><br>');
        $clean = preg_replace('~\s+~u', ' ', $clean);
        $clean = str_replace(['</p> <p>','</p><p>'], '</p><p>', $clean);
        $clean = trim((string)$clean);
        return $clean;
    }
}
