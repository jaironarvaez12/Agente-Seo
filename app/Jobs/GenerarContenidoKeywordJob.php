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

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {
        // ✅ UUID real por ejecución => siempre genera registro NUEVO al despachar otro job
        // (y se mantiene estable en retries)
        $this->jobUuid = (string) Str::uuid();
    }

    public function handle(): void
    {
        $registro = null;

        try {
            // ===========================================================
            // 1) Registro por job_uuid (para retries del mismo job)
            // ===========================================================
            $registro = $this->getOrCreateRegistro();
            $this->registroId = (int) $registro->id_dominio_contenido_detalle;

            if ($registro->estatus === 'generado' && !empty($registro->contenido_html)) {
                return;
            }

            $registro->update([
                'estatus' => 'en_proceso',
                'modelo'  => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                'error'   => null,
            ]);

            // ===========================================================
            // 2) Config IA
            // ===========================================================
            $apiKey = (string) env('DEEPSEEK_API_KEY', '');
            $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');
            if ($apiKey === '') throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');

            // ===========================================================
            // 3) Cargar plantilla (dominio/env/default=179)
            // ===========================================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);

            // ===========================================================
            // 4) Detectar tokens reales {{TOKEN}} con contexto
            // ===========================================================
            $tokensMeta = $this->collectTokensMeta($tpl);
            if (empty($tokensMeta)) {
                throw new \RuntimeException("NO_RETRY: La plantilla no contiene tokens {{TOKEN}}. Template: {$tplPath}");
            }

            // ===========================================================
            // 5) Historial anti-repetición (títulos + corpus)
            // ===========================================================
            $prev = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int)$this->idDominioContenido)
                ->whereNotNull('draft_html')
                ->orderByDesc('id_dominio_contenido_detalle')
                ->limit(6)
                ->get(['title', 'draft_html']);

            $usedTitles = [];
            $usedCorpus = [];
            foreach ($prev as $row) {
                if (!empty($row->title)) $usedTitles[] = (string)$row->title;
                $usedCorpus[] = $this->copyTextFromDraftJson((string)$row->draft_html);
            }
            $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 12));
            $noRepetirCorpus = $this->compactHistory($usedCorpus, 2500);
            $lastCorpus = trim((string)($usedCorpus[0] ?? ''));

            // ===========================================================
            // 6) Generación (2 ciclos máx si está demasiado parecido)
            // ===========================================================
            $finalValues = null;

            for ($cycle = 1; $cycle <= 2; $cycle++) {
                $brief = $this->creativeBrief();
                $seed  = $this->stableSeedInt($this->jobUuid . '|' . $this->registroId . "|cycle={$cycle}");

                // PLAN de temas (cambia en cada job/cycle)
                $themePlan = $this->buildThemePlan($seed, 40, 26);

                $values = $this->generateValuesForTemplateTokensBatched(
                    apiKey: $apiKey,
                    model: $model,
                    tokensMeta: $tokensMeta,
                    brief: $brief,
                    seed: $seed,
                    themePlan: $themePlan,
                    noRepetirTitles: $noRepetirTitles,
                    noRepetirCorpus: $noRepetirCorpus
                );

                // Similaridad vs último
                $currentText = $this->valuesToPlainText($values);
                $sim = ($lastCorpus !== '') ? $this->jaccardBigrams($currentText, $lastCorpus) : 0.0;

                $finalValues = $values;

                // umbral: si se parece mucho, reintenta 1 vez con otro cycle/seed
                if ($sim < 0.45) break;
            }

            if (!is_array($finalValues)) {
                throw new \RuntimeException('No se pudo generar valores finales');
            }

            // ===========================================================
            // 7) Reemplazar tokens
            // ===========================================================
            [$filled, $replacedCount, $remainingTokens] = $this->fillTemplateTokensWithStats($tpl, $finalValues);

            if ($replacedCount < 1) {
                throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}");
            }
            if (!empty($remainingTokens)) {
                throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remainingTokens, 0, 80)));
            }

            // ===========================================================
            // 8) Title + slug
            // ===========================================================
            $title = trim(strip_tags($this->toStr($finalValues['HERO_H1'] ?? $finalValues['SEO_TITLE'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // 9) Guardar
            // ===========================================================
            $registro->update([
                'title'          => $title,
                'slug'           => $slug,
                'draft_html'     => json_encode($finalValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'contenido_html' => json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estatus'        => 'generado',
                'error'          => null,
            ]);

        } catch (\Throwable $e) {
            if ($registro) {
                $isLast  = ($this->attempts() >= (int)$this->tries);
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
    // Registro idempotente SOLO para retries del mismo job_uuid
    // ===========================================================
    private function getOrCreateRegistro(): Dominios_Contenido_DetallesModel
    {
        $existing = Dominios_Contenido_DetallesModel::where('job_uuid', $this->jobUuid)->first();
        if ($existing) return $existing;

        return Dominios_Contenido_DetallesModel::create([
            'job_uuid'              => $this->jobUuid,
            'id_dominio_contenido'  => (int)$this->idDominioContenido,
            'id_dominio'            => (int)$this->idDominio,
            'tipo'                  => $this->tipo,
            'keyword'               => $this->keyword,
            'estatus'               => 'en_proceso',
            'modelo'                => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ]);
    }

    // ===========================================================
    // TEMPLATE LOADER (default = 179 estándar)
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) throw new \RuntimeException("NO_RETRY: Dominio no encontrado (id={$idDominio})");

        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));

        if ($templateRel === '') $templateRel = trim((string)env('ELEMENTOR_TEMPLATE_PATH', ''));

        // ✅ estándar
        if ($templateRel === '') $templateRel = 'elementor/elementor-179-2025-12-27.json';

        $templateRel = str_replace(['https:', 'http:'], '', $templateRel);

        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) throw new \RuntimeException('NO_RETRY: Template path inválido (no se permite "..")');

        $templatePath = storage_path('app/' . $templateRel);
        if (!is_file($templatePath)) throw new \RuntimeException("NO_RETRY: No existe el template en disco: {$templatePath}");

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('NO_RETRY: Template Elementor inválido: debe contener "content" (array).');
        }

        return [$tpl, $templatePath];
    }

    // ===========================================================
    // Detectar tokens + tipo (plain vs editor)
    // ===========================================================
    private function collectTokensMeta(array $tpl): array
    {
        $meta = []; // TOKEN => ['type' => 'plain'|'editor', 'wrap_p' => bool]

        $walk = function ($node) use (&$walk, &$meta) {
            if (!is_array($node)) return;

            foreach ($node as $k => $v) {
                // tokens en strings
                if (is_string($k) && in_array($k, ['editor','title','text'], true) && is_string($v) && str_contains($v, '{{')) {
                    if (preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $v, $m)) {
                        foreach (($m[1] ?? []) as $tok) {
                            $tok = (string)$tok;
                            if ($tok === '') continue;

                            $type = ($k === 'editor') ? 'editor' : 'plain';

                            $wrapP = (bool) preg_match('~<p>\s*\{\{' . preg_quote($tok, '~') . '\}\}\s*</p>~i', $v);

                            if (!isset($meta[$tok])) {
                                $meta[$tok] = ['type' => $type, 'wrap_p' => $wrapP];
                            } else {
                                if ($type === 'editor') $meta[$tok]['type'] = 'editor';
                                $meta[$tok]['wrap_p'] = $meta[$tok]['wrap_p'] || $wrapP;
                            }
                        }
                    }
                }

                if (is_array($v)) $walk($v);
            }
        };

        $walk($tpl);

        ksort($meta);
        return $meta;
    }

    // ===========================================================
    // Generación por lotes + plan de temas => siempre diferente
    // ===========================================================
    private function generateValuesForTemplateTokensBatched(
        string $apiKey,
        string $model,
        array $tokensMeta,
        array $brief,
        int $seed,
        array $themePlan,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): array {
        $allKeys = array_keys($tokensMeta);

        // Orden: primero no-section, luego SECTION titles, luego SECTION p (ayuda a coherencia)
        usort($allKeys, function ($a, $b) {
            $ra = $this->tokenRank((string)$a);
            $rb = $this->tokenRank((string)$b);
            if ($ra === $rb) return strcmp((string)$a, (string)$b);
            return $ra <=> $rb;
        });

        $chunks = array_chunk($allKeys, 12);
        $values = [];

        $variation = "seed={$seed}|job=" . substr($this->jobUuid, 0, 8) . "|rid=" . (int)$this->registroId;

        foreach ($chunks as $idx => $chunkKeys) {
            $skeleton = [];
            foreach ($chunkKeys as $k) $skeleton[$k] = "";

            $schemaJson = json_encode($skeleton, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $plainKeys = [];
            $editorKeys = [];
            foreach ($chunkKeys as $k) {
                $t = $tokensMeta[$k]['type'] ?? 'plain';
                if ($t === 'editor') $editorKeys[] = $k;
                else $plainKeys[] = $k;
            }

            $briefAngle = $this->toStr($brief['angle'] ?? '');
            $briefTone  = $this->toStr($brief['tone'] ?? '');
            $briefCTA   = $this->toStr($brief['cta'] ?? '');
            $briefAud   = $this->toStr($brief['audience'] ?? '');

            // resumen de lo ya generado (para NO repetir)
            $alreadySectionTitles = [];
            foreach ($values as $k => $v) {
                if (preg_match('~^SECTION_\d+_TITLE$~', (string)$k)) {
                    $alreadySectionTitles[] = trim(strip_tags((string)$v));
                }
            }
            $alreadySectionTitles = array_slice(array_filter($alreadySectionTitles), 0, 20);
            $alreadyStr = implode(' | ', $alreadySectionTitles);

            // plan de temas (26 líneas)
            $planLines = [];
            for ($i=1; $i<=26; $i++) {
                $planLines[] = "SECTION_{$i}: " . ($themePlan[$i] ?? 'Tema');
            }
            $planText = implode("\n", $planLines);

            $prompt = <<<PROMPT
Devuelve SOLO JSON válido (sin markdown). RESPUESTA MINIFICADA.
Idioma: ES.

VARIATION (NO imprimir): {$variation}

Contexto:
- Keyword: {$this->keyword}
- Tipo: {$this->tipo}

BRIEF:
- Ángulo: {$briefAngle}
- Tono: {$briefTone}
- Público: {$briefAud}
- CTA: {$briefCTA}

PLAN DE TEMAS (OBLIGATORIO, debe variar entre secciones):
{$planText}

NO REPETIR TÍTULOS:
{$noRepetirTitles}

NO REPETIR TEXTOS:
{$noRepetirCorpus}

YA USADOS (evita repetir estos títulos de sección):
{$alreadyStr}

REGLAS:
- Devuelve EXACTAMENTE las keys del ESQUEMA (no agregues ni quites).
- PROHIBIDO valores vacíos: nada de "" ni null.
- Keys editor: texto 1–3 frases. Permite SOLO <strong> y <br>. NO uses <p>.
- Keys plain: solo texto plano (sin HTML).
- SECTION_X_TITLE y SECTION_X_P deben seguir su tema del plan y ser DIFERENTES entre sí.
- No repitas la keyword en todas las líneas.

LISTA editor:
{implode(', ', $editorKeys)}

LISTA plain:
{implode(', ', $plainKeys)}

ESQUEMA:
{$schemaJson}
PROMPT;

            $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 1800, temperature: 0.85, topP: 0.9, jsonMode: true);

            $arr = $this->safeParseOrRepairForKeys($apiKey, $model, $raw, $chunkKeys, $brief, $variation);

            // normalizar/limpiar + fallback variable
            foreach ($chunkKeys as $k) {
                $k = (string)$k;
                $meta = $tokensMeta[$k] ?? ['type' => 'plain', 'wrap_p' => false];

                $val = $arr[$k] ?? '';
                $val = $this->normalizeValueByTokenMeta($k, $val, $meta, $seed, $themePlan);

                // si sigue vacío => fallback
                if ($this->isEmptyValue($val)) {
                    $val = $this->fallbackForToken($k, $meta, $seed, $themePlan);
                }

                $values[$k] = $val;
            }
        }

        // Anti “todo igual”: si SECTION_X_P se repite, fuerza fallback único
        $seen = [];
        foreach ($values as $k => $v) {
            if (!preg_match('~^SECTION_(\d+)_P$~', (string)$k, $m)) continue;

            $plain = mb_strtolower(trim(strip_tags((string)$v)));
            if ($plain === '') continue;

            if (isset($seen[$plain])) {
                $values[$k] = $this->fallbackForToken((string)$k, $tokensMeta[$k] ?? ['type'=>'editor','wrap_p'=>false], $seed + 77, $themePlan, forceUnique: true);
            } else {
                $seen[$plain] = true;
            }
        }

        return $values;
    }

    private function tokenRank(string $k): int
    {
        if (preg_match('~^SECTION_\d+_TITLE$~', $k)) return 20;
        if (preg_match('~^SECTION_\d+_P$~', $k)) return 30;
        return 10;
    }

    // ===========================================================
    // PLAN DE TEMAS (baraja con seed => siempre cambia)
    // ===========================================================
    private function buildThemePlan(int $seed, int $poolSize = 40, int $sections = 26): array
    {
        $pool = [
            "Propuesta de valor y diferenciación",
            "Proceso de trabajo y pasos",
            "Entregables incluidos",
            "Plazos y organización",
            "Brief: qué necesitas aportar",
            "Errores comunes que evitamos",
            "Qué mejora en la web/página",
            "Estructura recomendada",
            "Copy orientado a conversión",
            "Objeciones típicas y respuesta",
            "CTA y siguientes pasos",
            "SEO natural sin stuffing",
            "Tono de marca y coherencia",
            "Contenido para escanear (UX)",
            "Casos/ejemplos de implementación",
            "Para quién es ideal",
            "Para quién NO es ideal",
            "Métricas a observar",
            "Checklist de publicación",
            "Mantenimiento y iteración",
            "Integración con WordPress/Elementor",
            "Comunicación y feedback",
            "Personalización por sector",
            "Estrategia de mensajes",
            "Bloques adicionales útiles",
            "Preguntas frecuentes clave",
            "Garantías realistas (sin prometer)",
            "Riesgos y cómo mitigarlos",
            "Plan de lanzamiento",
            "Optimización post-publicación",
            "Alineación con oferta/servicio",
            "Lenguaje y claridad",
            "Jerarquía visual del contenido",
            "Estructura de testimonios",
            "Estructura de proyectos/portfolio",
            "Sección de precios (sin humo)",
            "Kit Digital / ayudas",
            "Llamada/diagnóstico",
            "Dudas típicas de clientes",
            "Cierre y compromiso",
        ];

        $pool = $this->shuffleDeterministic($pool, $seed);
        $pool = array_slice($pool, 0, max($sections, min($poolSize, count($pool))));

        $plan = [];
        for ($i=1; $i<=$sections; $i++) {
            $plan[$i] = $pool[($i-1) % count($pool)] ?? "Tema {$i}";
        }
        return $plan;
    }

    private function shuffleDeterministic(array $arr, int $seed): array
    {
        // Fisher–Yates con RNG simple estable
        $n = count($arr);
        $x = $seed;
        for ($i = $n - 1; $i > 0; $i--) {
            $x = ($x * 1103515245 + 12345) & 0x7fffffff;
            $j = $x % ($i + 1);
            [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
        }
        return $arr;
    }

    private function stableSeedInt(string $s): int
    {
        return (int) (hexdec(substr(md5($s), 0, 8)) & 0x7fffffff);
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
            'presence_penalty' => 1.1,
            'frequency_penalty' => 0.6,
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
    // Parse/Repair: solo keys esperadas
    // ===========================================================
    private function safeParseOrRepairForKeys(string $apiKey, string $model, string $raw, array $keys, array $brief, string $variation): array
    {
        try {
            $a = $this->parseJsonStrict($raw);
            return $this->filterKeys($a, $keys);
        } catch (\Throwable $e) {
            $loose = $this->parseJsonLoosePairs($raw);
            $loose = $this->filterKeys($loose, $keys);
            if (count($loose) >= max(2, (int)floor(count($keys) * 0.4))) {
                return $loose;
            }

            $fixed = $this->repairJsonForKeys($apiKey, $model, $raw, $keys, $brief, $variation);
            try {
                $b = $this->parseJsonStrict($fixed);
                return $this->filterKeys($b, $keys);
            } catch (\Throwable $e2) {
                $loose2 = $this->parseJsonLoosePairs($fixed);
                return $this->filterKeys($loose2, $keys);
            }
        }
    }

    private function repairJsonForKeys(string $apiKey, string $model, string $broken, array $keys, array $brief, string $variation): string
    {
        $broken = mb_substr(trim((string)$broken), 0, 9000);
        $briefAngle = $this->toStr($brief['angle'] ?? '');
        $briefTone  = $this->toStr($brief['tone'] ?? '');

        $skel = [];
        foreach ($keys as $k) $skel[$k] = "";
        $schema = json_encode($skel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. RESPUESTA MINIFICADA.
VARIATION (NO imprimir): {$variation}

Corrige el JSON roto y devuelve EXACTAMENTE las keys del ESQUEMA (sin agregar ni quitar).
PROHIBIDO valores vacíos y PROHIBIDO keys vacías "".

Reglas:
- Textos largos: solo texto + <strong> y <br>. NO uses <p>.
- Textos cortos: sin HTML.

Ángulo: {$briefAngle}
Tono: {$briefTone}

ESQUEMA:
{$schema}

JSON roto:
{$broken}
PROMPT;

        return $this->deepseekText($apiKey, $model, $prompt, maxTokens: 1600, temperature: 0.2, topP: 0.9, jsonMode: true);
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
        if (!is_array($data)) throw new \RuntimeException('JSON inválido');

        if (array_key_exists('', $data)) unset($data['']); // por si DeepSeek mete key vacía

        return $data;
    }

    private function parseJsonLoosePairs(string $raw): array
    {
        $raw = trim((string)$raw);
        $out = [];

        if (preg_match_all('~"([A-Z0-9_]+)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"~u', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = (string)($row[1] ?? '');
                if ($k === '') continue;
                $inner = (string)($row[2] ?? '');
                $decoded = json_decode('"' . $inner . '"', true);
                $out[$k] = is_string($decoded) ? $decoded : stripcslashes($inner);
            }
        }

        if (array_key_exists('', $out)) unset($out['']);

        return $out;
    }

    private function filterKeys(array $arr, array $keys): array
    {
        $set = array_fill_keys($keys, true);
        $out = [];
        foreach ($arr as $k => $v) {
            $k = (string)$k;
            if (isset($set[$k])) $out[$k] = $v;
        }
        return $out;
    }

    // ===========================================================
    // Normalización y fallbacks variables (no repetitivos)
    // ===========================================================
    private function normalizeValueByTokenMeta(string $k, mixed $v, array $meta, int $seed, array $themePlan): string
    {
        $type = $meta['type'] ?? 'plain';
        $wrapP = (bool)($meta['wrap_p'] ?? false);

        if ($type === 'editor') {
            $s = $this->normalizeEditorFragment($this->toStr($v));
            // si plantilla ya envuelve <p>{{TOKEN}}</p>, entonces debe ser texto (sin HTML)
            if ($wrapP) {
                $s = trim(strip_tags($s));
                $s = preg_replace('~\s+~u', ' ', (string)$s);
                return trim((string)$s);
            }
            return $s;
        }

        // plain
        $s = trim(strip_tags($this->toStr($v)));
        $s = preg_replace('~\s+~u', ' ', (string)$s);
        return trim((string)$s);
    }

    private function normalizeEditorFragment(string $html): string
    {
        // permitimos solo <strong> y <br>, y si llega <p> lo quitamos
        $clean = strip_tags((string)$html, '<strong><br><p>');
        $clean = trim((string)$clean);

        if (preg_match('~^\s*<p>\s*(.*?)\s*</p>\s*$~is', $clean, $m)) {
            $clean = trim((string)($m[1] ?? ''));
        }

        $clean = preg_replace('~\s+~u', ' ', (string)$clean);
        $clean = str_replace(["<br />", "<br/>"], "<br>", (string)$clean);
        return trim((string)$clean);
    }

    private function isEmptyValue(string $v): bool
    {
        return trim(strip_tags((string)$v)) === '';
    }

    private function fallbackForToken(string $tok, array $meta, int $seed, array $themePlan, bool $forceUnique = false): string
    {
        $type  = $meta['type'] ?? 'plain';
        $wrapP = (bool)($meta['wrap_p'] ?? false);

        $kw = $this->shortKw();

        // helper para seleccionar variante distinta siempre (por seed+tok)
        $pick = function(array $arr) use ($seed, $tok) {
            $i = $this->stableSeedInt($seed . '|' . $tok) % max(1, count($arr));
            return $arr[$i] ?? $arr[0];
        };

        // SECTION titles/ps siguen plan de temas
        if (preg_match('~^SECTION_(\d+)_TITLE$~', $tok, $m)) {
            $i = (int)($m[1] ?? 1);
            $tema = $themePlan[$i] ?? "Tema {$i}";
            $variants = [
                "Enfoque: {$tema}",
                "{$tema} en la práctica",
                "Cómo aplicamos: {$tema}",
                "Puntos clave de: {$tema}",
            ];
            $t = $pick($variants);
            return $forceUnique ? ($t . " ({$i})") : $t;
        }

        if (preg_match('~^SECTION_(\d+)_P$~', $tok, $m)) {
            $i = (int)($m[1] ?? 1);
            $tema = $themePlan[$i] ?? "Tema {$i}";

            $variants = [
                "Aquí aterrizamos {$tema} para {$kw}: pasos claros, decisiones simples y texto listo para publicar sin relleno.",
                "Desarrollamos {$tema} con enfoque práctico: qué hacer, qué evitar y cómo dejarlo consistente con tu oferta.",
                "Aplicamos {$tema} pensando en intención y claridad: estructura, mensajes y siguiente paso sin prometer de más.",
                "Convertimos {$tema} en bloques accionables: frases concretas, jerarquía y CTA coherente para avanzar.",
                "Tratamos {$tema} con criterio: contenido escaneable, ordenado y fácil de adaptar a tu sitio en Elementor.",
            ];

            $p = $pick($variants);
            if ($forceUnique) $p .= " (bloque {$i})";

            // editor => puede incluir <strong>/<br>, pero NO <p>
            if ($wrapP) return trim(strip_tags($p)); // si plantilla envuelve <p>
            return ($type === 'editor') ? $p : trim(strip_tags($p));
        }

        // Botones / hero / etc
        if (str_starts_with($tok, 'BTN_')) {
            $btn = match ($tok) {
                'BTN_PRESUPUESTO' => $pick(["Solicitar presupuesto","Pedir propuesta","Ver opciones"]),
                'BTN_REUNION'     => $pick(["Agendar llamada","Reservar llamada","Hablar ahora"]),
                'BTN_KITDIGITAL'  => $pick(["Ver información","Consultar","Empezar"]),
                default           => $pick(["Ver opciones","Continuar"]),
            };
            return $btn;
        }

        if ($tok === 'HERO_H1') return $pick([
            "{$kw} con estrategia y claridad",
            "Estructura y copy para {$kw}",
            "{$kw}: mensaje, secciones y CTA",
        ]);

        if ($tok === 'HERO_KICKER') return $pick([
            "Para equipos que buscan claridad",
            "Estructura lista para publicar",
            "Mensaje directo, sin ruido",
            "Pensado para leads y acción",
        ]);

        if ($tok === 'PACK_H2') return $pick([
            "Nuestro proceso y entregables",
            "Cómo lo implementamos paso a paso",
            "Estructura y mensajes por intención",
        ]);

        if ($tok === 'FAQ_TITLE') return "Preguntas frecuentes";
        if ($tok === 'FINAL_CTA') {
            $txt = $pick([
                "¿Quieres publicarlo y avanzar? <strong>Te guiamos con el siguiente paso.</strong>",
                "Listo para mejorar el mensaje? <strong>Hagamos una propuesta clara.</strong>",
                "¿Buscas una entrega sin vueltas? <strong>Agenda y lo estructuramos.</strong>",
            ]);
            return ($type === 'editor') ? $txt : trim(strip_tags($txt));
        }

        // genérico
        $generic = $pick([
            "Contenido útil para {$kw}, listo para adaptar.",
            "Bloque preparado para {$kw} con enfoque claro.",
            "Texto ordenado para {$kw} sin relleno.",
        ]);
        return ($type === 'editor') ? $generic : trim(strip_tags($generic));
    }

    // ===========================================================
    // Reemplazo tokens
    // ===========================================================
    private function fillTemplateTokensWithStats(array $tpl, array $values): array
    {
        $dict = [];
        foreach ($values as $k => $v) $dict['{{' . $k . '}}'] = (string)$v;

        $count = 0;
        $this->replaceTokensDeep($tpl, $dict, $count);
        $remaining = $this->collectRemainingTokensDeep($tpl);

        return [$tpl, $count, $remaining];
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
    // Brief (se mezcla con seed/plan, así cambia temas)
    // ===========================================================
    private function creativeBrief(): array
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
    // Similaridad (para evitar “mismo contenido”)
    // ===========================================================
    private function valuesToPlainText(array $values): string
    {
        $parts = [];
        foreach ($values as $k => $v) {
            $parts[] = strip_tags((string)$v);
        }
        $txt = implode(' ', $parts);
        $txt = preg_replace('~\s+~u', ' ', (string)$txt);
        return trim((string)$txt);
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
        $s = trim((string)$s);
        if ($s === '') return [];
        $chars = preg_split('~~u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $n = count($chars);
        for ($i = 0; $i < $n - 1; $i++) $out[$chars[$i] . $chars[$i + 1]] = 1;
        return $out;
    }

    // ===========================================================
    // Historial
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
        foreach ($arr as $k => $v) $parts[] = strip_tags($this->toStr($v));
        $txt = implode(' ', array_filter($parts));
        $txt = preg_replace('~\s+~u', ' ', (string)$txt);
        return trim((string)$txt);
    }

    // ===========================================================
    // Utils
    // ===========================================================
    private function toStr(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        if (is_bool($v)) return $v ? '1' : '0';
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($j) ? $j : '';
    }

    private function shortKw(): string
    {
        $kw = trim((string)$this->keyword);
        if ($kw === '') return 'tu proyecto';
        return mb_substr($kw, 0, 70);
    }
}
