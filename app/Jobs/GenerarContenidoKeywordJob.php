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

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {
        // ✅ Nuevo por cada dispatch, NO por keyword (evita reescritura)
        $this->jobUuid = (string) Str::uuid(); // 36 chars
    }

    public function handle(): void
    {
        $registro = null;

        try {
            // 1) Crear/recuperar SOLO por job_uuid (para retries del mismo job)
            $registro = $this->getOrCreateRegistroByJobUuid();

            Log::info('GenerarContenidoKeywordJob START', [
                'job_uuid' => $this->jobUuid,
                'detalle_id' => $registro->id_dominio_contenido_detalle ?? null,
                'attempts' => $this->attempts(),
                'idDominio' => $this->idDominio,
                'idDominioContenido' => $this->idDominioContenido,
                'tipo' => $this->tipo,
                'keyword' => $this->keyword,
            ]);

            // 2) Config DeepSeek
            $apiKey = (string) env('DEEPSEEK_API_KEY', '');
            $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');

            if ($apiKey === '') {
                throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');
            }

            // 3) Historial para evitar repetición
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

            // 4) Template + map
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);
            $map = $this->loadTokenMapIfExists($tplPath); // puede ser null

            // 5) Tokens reales en template (normaliza {{ H_01 }} => {{H_01}})
            $tokensInTpl = $this->collectRemainingTokensDeep($tpl);
            if (count($tokensInTpl) === 0) {
                throw new \RuntimeException("NO_RETRY: Template sin tokens {{...}}: {$tplPath}");
            }

            // 6) Blueprint robusto (map + inferencia)
            $blueprint = $this->buildBlueprintFromTemplateAndMap($tpl, $tokensInTpl, $map);

            // 7) Generar copy (1 llamada + 1 “missing” si hace falta)
            $copy = $this->generateCopyForBlueprint(
                $apiKey,
                $model,
                $blueprint,
                $noRepetirTitles,
                $noRepetirCorpus
            );

            $missing = $this->findMissingTokensInCopy($tokensInTpl, $copy);
            if (!empty($missing)) {
                $copy2 = $this->generateMissingOnly(
                    $apiKey,
                    $model,
                    $blueprint,
                    $missing,
                    $noRepetirTitles,
                    $noRepetirCorpus
                );
                $copy = array_merge($copy, $copy2);

                $missing2 = $this->findMissingTokensInCopy($tokensInTpl, $copy);
                if (!empty($missing2)) {
                    throw new \RuntimeException("NO_RETRY: DeepSeek no devolvió tokens: " . implode(' | ', array_slice($missing2, 0, 80)));
                }
            }

            // 8) Rellenar template
            [$filled, $replacedCount, $remaining] = $this->fillTemplateByTokensWithStats($tpl, $copy);

            // ✅ Ya NO usamos el bug de replacedCount < 8 (eso rompía plantillas pequeñas)
            if (!empty($remaining)) {
                throw new \RuntimeException("NO_RETRY: Quedaron tokens sin reemplazar: " . implode(' | ', array_slice($remaining, 0, 80)));
            }

            // 9) Title + slug
            $title = trim(strip_tags((string)($copy['seo_title'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            $metaTitle = $this->sanitizeSeoTitle((string)($copy['seo_title'] ?? $title), $this->keyword);
            $metaDesc  = $this->sanitizeMetaDescription((string)($copy['meta_description'] ?? ''), $this->keyword);

            // 10) Guardar
            $registro->update([
                'title'            => $title,
                'slug'             => $slug,
                'meta_title'       => $metaTitle,
                'meta_description' => $metaDesc !== '' ? $metaDesc : null,
                'draft_html'       => json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'contenido_html'   => json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estatus'          => 'generado',
                'error'            => null,
            ]);

            Log::info('GenerarContenidoKeywordJob DONE', [
                'job_uuid' => $this->jobUuid,
                'detalle_id' => $registro->id_dominio_contenido_detalle ?? null,
                'replacedCount' => $replacedCount,
                'tokensCount' => count($tokensInTpl),
            ]);

        } catch (\Throwable $e) {
            if ($registro) {
                $isLast = ($this->attempts() >= (int)$this->tries);

                // Si es NO_RETRY, lo marcamos final de una vez y NO reintentamos.
                $noRetry = str_contains($e->getMessage(), 'NO_RETRY:');

                $registro->update([
                    'estatus' => ($noRetry || $isLast) ? 'error_final' : 'error',
                    'error'   => $e->getMessage() . ' | attempts=' . $this->attempts(),
                ]);

                if ($noRetry) {
                    // ✅ corta reintentos
                    $this->fail($e);
                    return;
                }
            }

            throw $e;
        }
    }

    // ===========================================================
    // Crea/recupera registro por job_uuid (solo para retries del mismo job)
    // ===========================================================
    private function getOrCreateRegistroByJobUuid(): Dominios_Contenido_DetallesModel
    {
        $existing = Dominios_Contenido_DetallesModel::where('job_uuid', $this->jobUuid)->first();
        if ($existing) {
            $existing->update([
                'estatus' => 'en_proceso',
                'modelo'  => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            ]);
            return $existing;
        }

        return Dominios_Contenido_DetallesModel::create([
            'job_uuid'             => $this->jobUuid,
            'id_dominio_contenido'  => (int)$this->idDominioContenido,
            'id_dominio'            => (int)$this->idDominio,
            'tipo'                  => $this->tipo,
            'keyword'               => $this->keyword,
            'estatus'               => 'en_proceso',
            'modelo'                => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ]);
    }

    // ===========================================================
    // Template loading
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) throw new \RuntimeException("NO_RETRY: Dominio no encontrado (id={$idDominio})");

        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));
        if ($templateRel === '') $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_PATH', ''));
        if ($templateRel === '') throw new \RuntimeException('NO_RETRY: No hay plantilla configurada (dominio ni env ELEMENTOR_TEMPLATE_PATH).');

        $templateRel = str_replace(['https:', 'http:'], '', $templateRel);

        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) throw new \RuntimeException('NO_RETRY: Template path inválido (no se permite "..")');

        $templatePath = storage_path('app/' . $templateRel);
        if (!is_file($templatePath)) throw new \RuntimeException("NO_RETRY: No existe el template en disco: {$templatePath} (path={$templateRel})");

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('NO_RETRY: Template Elementor inválido: debe contener "content" (array).');
        }

        return [$tpl, $templatePath];
    }

    private function loadTokenMapIfExists(string $templatePath): ?array
    {
        $mapPath = preg_replace('~\.json$~i', '.map.json', $templatePath);
        if (!$mapPath || !is_file($mapPath)) return null;

        $raw = (string) file_get_contents($mapPath);
        $arr = json_decode($raw, true);

        return is_array($arr) ? $arr : null;
    }

    // ===========================================================
    // Blueprint: map + inferencia desde JSON (widgetType + original)
    // ===========================================================
    private function buildBlueprintFromTemplateAndMap(array $tpl, array $tokensInTpl, ?array $map): array
    {
        $bp = [];

        if (is_array($map)) {
            foreach ($map as $item) {
                if (!is_array($item)) continue;
                $tok = (string)($item['token'] ?? '');
                if ($tok === '') continue;
                if (!in_array($tok, $tokensInTpl, true)) continue;

                $bp[$tok] = [
                    'token' => $tok,
                    'widgetType' => (string)($item['widgetType'] ?? 'unknown'),
                    'original' => (string)($item['original'] ?? ''),
                ];
            }
        }

        $inferred = $this->inferTokenContextsFromTemplate($tpl);

        foreach ($tokensInTpl as $tok) {
            if (isset($bp[$tok])) continue;

            if (isset($inferred[$tok])) {
                $bp[$tok] = [
                    'token' => $tok,
                    'widgetType' => (string)($inferred[$tok]['widgetType'] ?? 'unknown'),
                    'original' => (string)($inferred[$tok]['original'] ?? ''),
                ];
            } else {
                $bp[$tok] = ['token' => $tok, 'widgetType' => 'unknown', 'original' => ''];
            }
        }

        return array_values($bp);
    }

    private function inferTokenContextsFromTemplate(array $tpl): array
    {
        $found = [];

        $walk = function ($node, $currentWidgetType = null) use (&$walk, &$found) {
            if (!is_array($node)) return;

            $widgetType = $currentWidgetType;
            if (isset($node['widgetType']) && is_string($node['widgetType'])) {
                $widgetType = $node['widgetType'];
            }

            foreach ($node as $v) {
                if (is_string($v) && str_contains($v, '{{')) {
                    $norm = $this->normalizeTokenSpacing($v);
                    if (preg_match_all('/\{\{[A-Za-z0-9_]+\}\}/', $norm, $m)) {
                        foreach ($m[0] as $tok) {
                            if (!isset($found[$tok])) {
                                $found[$tok] = [
                                    'widgetType' => $widgetType ?? 'unknown',
                                    'original' => $v,
                                ];
                            }
                        }
                    }
                } elseif (is_array($v)) {
                    $walk($v, $widgetType);
                }
            }
        };

        $walk($tpl, null);

        return $found;
    }

    // ===========================================================
    // Copy generation
    // ===========================================================
    private function generateCopyForBlueprint(
        string $apiKey,
        string $model,
        array $blueprint,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): array {
        $prompt = $this->promptUniversalTokenCopy(
            $this->keyword,
            $this->tipo,
            $blueprint,
            $noRepetirTitles,
            $noRepetirCorpus
        );

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3600, temperature: 0.70, topP: 0.90, jsonMode: true);
        $copy = $this->safeParseJsonObject($apiKey, $model, $raw);

        $out = [];
        $out['seo_title'] = $this->sanitizeSeoTitle((string)($copy['seo_title'] ?? $this->keyword), $this->keyword);
        $out['meta_description'] = $this->sanitizeMetaDescription((string)($copy['meta_description'] ?? ''), $this->keyword);

        foreach ($blueprint as $b) {
            $tok = (string)($b['token'] ?? '');
            if ($tok === '') continue;
            $type = (string)($b['widgetType'] ?? 'unknown');
            $val = isset($copy[$tok]) ? (string)$copy[$tok] : '';
            $out[$tok] = $this->normalizeByWidgetTypeAndToken($tok, $type, $val);
        }

        return $out;
    }

    private function generateMissingOnly(
        string $apiKey,
        string $model,
        array $blueprint,
        array $missingTokens,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): array {
        $bpByToken = [];
        foreach ($blueprint as $b) {
            if (!empty($b['token'])) $bpByToken[$b['token']] = $b;
        }

        $missingBp = [];
        foreach ($missingTokens as $tok) {
            $missingBp[] = $bpByToken[$tok] ?? ['token' => $tok, 'widgetType' => 'unknown', 'original' => ''];
        }

        $bpShort = mb_substr(json_encode($missingBp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 9000);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido (sin markdown) MINIFICADO.
Genera SOLO estos tokens faltantes (no incluyas otros keys).
Idioma: Español.
Keyword: {$this->keyword}
Tipo: {$this->tipo}

NO repetir títulos:
{$noRepetirTitles}

NO repetir frases/subtemas:
{$noRepetirCorpus}

Blueprint (token, widgetType, original):
{$bpShort}

Formato:
{"{{TOKEN_1}}":"...","{{TOKEN_2}}":"..."}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 2000, temperature: 0.45, topP: 0.90, jsonMode: true);
        $obj = $this->safeParseJsonObject($apiKey, $model, $raw);

        $out = [];
        foreach ($missingBp as $b) {
            $tok = (string)($b['token'] ?? '');
            if ($tok === '') continue;
            $type = (string)($b['widgetType'] ?? 'unknown');
            $val  = isset($obj[$tok]) ? (string)$obj[$tok] : '';
            $out[$tok] = $this->normalizeByWidgetTypeAndToken($tok, $type, $val);
        }

        return $out;
    }

    private function promptUniversalTokenCopy(
        string $keyword,
        string $tipo,
        array $blueprint,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): string {
        $bpShort = mb_substr(json_encode($blueprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 9500);

        return <<<PROMPT
Devuelve SOLO JSON válido (sin markdown, sin explicación). RESPUESTA MINIFICADA.

Idioma: Español.
Objetivo: Reemplazar TODOS los tokens del template por textos nuevos, claros, útiles y coherentes con el "original".

Keyword: {$keyword}
Tipo: {$tipo}

NO repetir títulos:
{$noRepetirTitles}

NO repetir frases/subtemas:
{$noRepetirCorpus}

REGLAS:
- Devuelve "seo_title" (60–65 chars) y "meta_description" (130–155 chars).
- Para cada token del blueprint, devuelve una key EXACTA igual al token (ej "{{H_01}}") con su valor.
- heading: frase corta; puede usar <strong>; NO uses <h1>/<h2>.
- text-editor: HTML SOLO <p>, <strong>, <br>. 2–4 párrafos cortos, concretos (no humo).
- button: 2–4 palabras, sin HTML.
- icon-list: muy corto, sin HTML (2–6 palabras máximo).
- Nada vacío, nada "<p></p>", nada incoherente.

BLUEPRINT (token, widgetType, original):
{$bpShort}

Formato:
{"seo_title":"...","meta_description":"...","{{H_01}}":"...","{{P_01}}":"<p>...</p>","{{BTN_01}}":"..."}
PROMPT;
    }

    private function normalizeByWidgetTypeAndToken(string $tok, string $type, string $val): string
    {
        $val = (string)$val;

        if ($type === '' || $type === 'unknown') {
            if (str_starts_with($tok, '{{H_')) $type = 'heading';
            elseif (str_starts_with($tok, '{{P_')) $type = 'text-editor';
            elseif (str_starts_with($tok, '{{BTN_')) $type = 'button';
        }

        if ($type === 'text-editor') {
            $val = $this->keepAllowedInlineHtml($val);
            if ($this->isBlankHtml($val)) {
                $kw = htmlspecialchars($this->keyword, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $val = "<p>Información clara y práctica sobre {$kw}.</p><p>Contenido listo para publicar, enfocado en resolver dudas reales.</p>";
            }
        } elseif ($type === 'button') {
            $val = trim(strip_tags($val));
            if ($val === '') $val = $this->pick(["Ver más","Saber más","Empezar","Contactar"]);
            if (mb_strlen($val) > 24) $val = mb_substr($val, 0, 24);
        } elseif ($type === 'icon-list') {
            $val = trim(strip_tags($val));
            if ($val === '') $val = $this->pick(["Rápido","Claro","Optimizado","Listo"]);
            if (mb_strlen($val) > 55) $val = mb_substr($val, 0, 55);
        } elseif ($type === 'heading') {
            $val = $this->keepAllowedInlineStrong($val);
            $val = trim($val);
            if ($val === '') $val = "Guía sobre " . mb_substr($this->keyword, 0, 55);
            if (mb_strlen(strip_tags($val)) > 75) $val = mb_substr(strip_tags($val), 0, 75);
        } else {
            $val = $this->keepAllowedInlineStrong($val);
            if (trim(strip_tags($val)) === '') $val = "Contenido para " . mb_substr($this->keyword, 0, 55);
        }

        return $val;
    }

    private function findMissingTokensInCopy(array $tokensInTpl, array $copy): array
    {
        $missing = [];
        foreach ($tokensInTpl as $tok) {
            if (!array_key_exists($tok, $copy)) $missing[] = $tok;
            else {
                $v = (string)$copy[$tok];
                if (trim(strip_tags($v)) === '') $missing[] = $tok;
            }
        }
        return $missing;
    }

    // ===========================================================
    // Replace + token collection (con normalización de espacios)
    // ===========================================================
    private function fillTemplateByTokensWithStats(array $tpl, array $copy): array
    {
        $dict = $this->buildTokenDictionary($copy);

        $replacedCount = 0;
        $this->replaceTokensDeep($tpl, $dict, $replacedCount);

        $remaining = $this->collectRemainingTokensDeep($tpl);

        return [$tpl, $replacedCount, $remaining];
    }

    private function buildTokenDictionary(array $copy): array
    {
        $dict = [];

        foreach ($copy as $k => $v) {
            if ($k === 'seo_title' || $k === 'meta_description') continue;
            if (is_string($k) && str_starts_with($k, '{{') && str_ends_with($k, '}}')) {
                $dict[$k] = (string)$v;
            }
        }

        $dict['{{SEO_TITLE}}'] = (string)($copy['seo_title'] ?? $this->keyword);

        return $dict;
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

        $node = $this->normalizeTokenSpacing($node);
        $node = strtr($node, $dict);

        if ($node !== $orig) $count++;
    }

    private function normalizeTokenSpacing(string $s): string
    {
        return (string) preg_replace('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', '{{$1}}', $s);
    }

    private function collectRemainingTokensDeep(mixed $node): array
    {
        $found = [];
        $walk = function ($n) use (&$walk, &$found) {
            if (is_array($n)) { foreach ($n as $v) $walk($v); return; }
            if (!is_string($n) || $n === '') return;
            if (!str_contains($n, '{{')) return;

            $n = $this->normalizeTokenSpacing($n);

            if (preg_match_all('/\{\{[A-Za-z0-9_]+\}\}/', $n, $m)) {
                foreach ($m[0] as $tok) $found[] = $tok;
            }
        };
        $walk($node);
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    // ===========================================================
    // DeepSeek (timeout controlado)
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
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Devuelves SOLO JSON válido. No markdown. No explicaciones.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $temperature,
            'top_p' => $topP,
            'presence_penalty' => 1.0,
            'frequency_penalty' => 0.5,
            'max_tokens' => $maxTokens,
        ];

        if ($jsonMode) $payload['response_format'] = ['type' => 'json_object'];

        $resp = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->connectTimeout(15)
            ->timeout(140) // ⬅️ controlado, no se queda “eterno”
            ->retry(0, 0)
            ->post('https://api.deepseek.com/v1/chat/completions', $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException("DeepSeek error {$resp->status()}: {$resp->body()}");
        }

        $data = $resp->json();
        $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') throw new \RuntimeException("NO_RETRY: DeepSeek returned empty text.");

        return $text;
    }

    private function safeParseJsonObject(string $apiKey, string $model, string $raw): array
    {
        try {
            return $this->parseJsonStrict($raw);
        } catch (\Throwable $e) {
            // 1 repair
            $repair = "Devuelve SOLO JSON válido minificado. Repara este output:\n{$raw}";
            $fixed = $this->deepseekText($apiKey, $model, $repair, maxTokens: 1800, temperature: 0.10, topP: 0.90, jsonMode: true);
            return $this->parseJsonStrict($fixed);
        }
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
            throw new \RuntimeException('NO_RETRY: DeepSeek no devolvió JSON válido. Snippet: ' . $snip);
        }
        return $data;
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
            if ($k === 'seo_title' || $k === 'meta_description') $parts[] = strip_tags((string)$v);
            if (is_string($k) && str_starts_with($k, '{{')) {
                $parts[] = strip_tags((string)$v);
            }
        }
        $txt = implode(' ', array_filter($parts));
        $txt = preg_replace('~\s+~u', ' ', $txt);
        return trim((string)$txt);
    }

    private function pick(array $arr): string
    {
        return $arr[random_int(0, count($arr) - 1)];
    }

    private function isBlankHtml(string $html): bool
    {
        $txt = trim(preg_replace('~\s+~u', ' ', strip_tags($html)));
        return $txt === '';
    }

    private function keepAllowedInlineHtml(string $html): string
    {
        $clean = strip_tags((string)$html, '<p><strong><br>');
        $clean = preg_replace('~\s+~u', ' ', $clean);
        $clean = str_replace(['</p> <p>','</p><p>'], '</p><p>', $clean);
        $clean = trim((string)$clean);

        if ($clean !== '' && !preg_match('~^\s*<p>~i', $clean)) {
            $clean = '<p>' . $clean . '</p>';
        }
        return $clean;
    }

    private function keepAllowedInlineStrong(string $html): string
    {
        $clean = strip_tags((string)$html, '<strong><br>');
        $clean = preg_replace('~\s+~u', ' ', $clean);
        return trim($clean);
    }

    private function sanitizeSeoTitle(string $seo, string $fallbackKw): string
    {
        $seo = trim(strip_tags((string)$seo));
        if ($seo === '') $seo = trim($fallbackKw);
        if (mb_strlen($seo) > 65) $seo = rtrim(mb_substr($seo, 0, 65), " \t\n\r\0\x0B-–—|:");
        return $seo;
    }

    private function sanitizeMetaDescription(string $desc, string $fallbackKw): string
    {
        $desc = trim(strip_tags((string)$desc));
        $desc = preg_replace('~\s+~u', ' ', $desc);
        if ($desc === '') return '';

        if (mb_strlen($desc) > 155) $desc = rtrim(mb_substr($desc, 0, 155), " \t\n\r\0\x0B-–—|:");
        if (mb_strlen($desc) < 90) {
            $desc = trim($desc . ' Guía práctica sobre ' . mb_substr($fallbackKw, 0, 40) . '.');
            if (mb_strlen($desc) > 155) $desc = mb_substr($desc, 0, 155);
        }

        return $desc;
    }
}
