<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldBeUnique;

use App\Models\DominiosModel;
use App\Models\Dominios_Contenido_DetallesModel;

class GenerarContenidoKeywordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 4200;
    public $tries   = 5;
    public $backoff = [60, 120, 300, 600, 900];

    public ?int $registroId = null;

    public function __construct(
        public int $idDominio,
        public int $idDominioContenido,
        public string $tipo,
        public string $keyword,
        public int $detalleId,
        public string $jobUuid
    ) {
        $this->tipo = strtolower(trim($this->tipo));
        $this->keyword = trim($this->keyword);
        $this->jobUuid = trim($this->jobUuid);
    }


    

   public function handle(): void
{
    $registro = null;

    try {
        // ✅ 1) El registro DEBE existir (fue reservado antes de encolar)
        $registro = Dominios_Contenido_DetallesModel::where(
            'id_dominio_contenido_detalle',
            (int) $this->detalleId
        )->first();

        if (!$registro) {
            throw new \RuntimeException(
                "NO_RETRY: No existe el detalle reservado (detalleId={$this->detalleId})."
            );
        }

        $this->registroId = (int) $registro->id_dominio_contenido_detalle;

        // ✅ 2) Blindaje: el registro reservado debe corresponder a ESTE job_uuid
        // Si no coincide, este job NO debe tocar ese registro.
        $regUuid = trim((string) ($registro->job_uuid ?? ''));
        $jobUuid = trim((string) ($this->jobUuid ?? ''));

        if ($regUuid === '' || $jobUuid === '') {
            throw new \RuntimeException("NO_RETRY: job_uuid vacío (registro={$regUuid} job={$jobUuid}).");
        }

        if ($regUuid !== $jobUuid) {
            throw new \RuntimeException(
                "NO_RETRY: job_uuid no coincide. esperado={$regUuid} recibido={$jobUuid}."
            );
        }

        // ✅ 3) Si ya terminó, no hacer nada
        if ($registro->estatus === 'generado' && !empty($registro->contenido_html)) {
            return;
        }

        // ✅ 4) Marcar en_proceso
        $registro->update([
            'estatus' => 'en_proceso',
            'modelo'  => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'error'   => null,
        ]);

        // ✅ 5) Config IA
        $apiKey = (string) env('DEEPSEEK_API_KEY', '');
        $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');

        if ($apiKey === '') {
            throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');
        }

        // ✅ 6) Plantilla
        [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int) $this->idDominio);

        // ✅ 7) Tokens meta
        $tokensMeta = $this->collectTokensMeta($tpl);
        if (empty($tokensMeta)) {
            throw new \RuntimeException("NO_RETRY: La plantilla no contiene tokens {{TOKEN}}. Template: {$tplPath}");
        }

        // ✅ 8) Historial anti-repetición (excluye este mismo registro)
        $prev = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int) $this->idDominioContenido)
            ->where('id_dominio_contenido_detalle', '!=', (int) $this->registroId)
            ->whereNotNull('draft_html')
            ->orderByDesc('id_dominio_contenido_detalle')
            ->limit(10)
            ->get(['title', 'draft_html']);

        $usedTitles = [];
        $usedCorpus = [];

        foreach ($prev as $row) {
            if (!empty($row->title)) $usedTitles[] = (string) $row->title;
            $usedCorpus[] = $this->copyTextFromDraftJson((string) $row->draft_html);
        }

        $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 20));
        $noRepetirCorpus = $this->compactHistory($usedCorpus, 3000);
        $lastCorpus = trim((string) ($usedCorpus[0] ?? ''));

        // ✅ 9) Generación (2 ciclos si se parece demasiado)
        $finalValues = null;

        for ($cycle = 1; $cycle <= 2; $cycle++) {
            $brief = $this->creativeBrief();

            // seed estable (depende del jobUuid + registroId)
            $seed = $this->stableSeedInt((string) $this->jobUuid . '|' . (int) $this->registroId . "|cycle={$cycle}");

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

            $values = $this->stringifyValues($values);
            $values = $this->fixJoinedWordsInValues($values, $tokensMeta);

            $values = $this->ensureCriticalTokensNotGeneric(
                apiKey: $apiKey,
                model: $model,
                tokensMeta: $tokensMeta,
                values: $values,
                brief: $brief,
                seed: $seed,
                themePlan: $themePlan,
                usedTitles: $usedTitles,
                noRepetirTitles: $noRepetirTitles,
                noRepetirCorpus: $noRepetirCorpus
            );

            $values = $this->ensureUniqueTitles(
                apiKey: $apiKey,
                model: $model,
                tokensMeta: $tokensMeta,
                values: $values,
                usedTitles: $usedTitles,
                brief: $brief,
                seed: $seed,
                themePlan: $themePlan,
                noRepetirTitles: $noRepetirTitles,
                noRepetirCorpus: $noRepetirCorpus
            );

            $currentText = $this->valuesToPlainText($values);
            $sim = ($lastCorpus !== '') ? $this->jaccardBigrams($currentText, $lastCorpus) : 0.0;

            $finalValues = $values;

            if ($sim < 0.45) break;
        }

        if (!is_array($finalValues)) {
            throw new \RuntimeException('No se pudo generar valores finales');
        }

        // ✅ 10) Reemplazar tokens
        [$filled, $replacedCount, $remainingTokens] = $this->fillTemplateTokensWithStats($tpl, $finalValues);

        if ($replacedCount < 1) {
            throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}");
        }

        if (!empty($remainingTokens)) {
            throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remainingTokens, 0, 120)));
        }

        // ✅ 11) Title + slug
        $title = trim(strip_tags($this->toStr($finalValues['SEO_TITLE'] ?? $finalValues['HERO_H1'] ?? $this->keyword)));
        if ($title === '') $title = (string) $this->keyword;

        $slugBase = Str::slug($title ?: $this->keyword);
        $slug = $slugBase . '-' . (int) $registro->id_dominio_contenido_detalle;

        // ✅ 12) Guardar resultado final
        $registro->update([
            'title'          => $title,
            'slug'           => $slug,
            'draft_html'     => json_encode($finalValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'contenido_html' => json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'estatus'        => 'generado',
            'error'          => null,
        ]);
        \App\Jobs\TrabajoEnviarContenidoWordPress::dispatch(
            (int)  $registro->id_dominio,
            (int) $registro->id_dominio_contenido_detalle
        )->onConnection('database')->onQueue('default');

    } catch (\Throwable $e) {
        if ($registro) {
            $isLast  = ($this->attempts() >= (int) $this->tries);
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

    // ========================= TEMPLATE LOADER =========================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
{
    $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
    if (!$dominio) throw new \RuntimeException("NO_RETRY: Dominio no encontrado (id={$idDominio})");

    $templateRel = trim((string)($dominio->elementor_template_path ?? ''));

    // ✅ SI NO HAY PLANTILLA SELECCIONADA => HTML PLANO (sin cambiar tu lógica)
    if ($templateRel === '') {
        // OJO: esto NO es un json real de Elementor,
        // pero cumple tu contrato interno: array con 'content' y strings con tokens {{TOKEN}}
        // para que: collectTokensMeta -> generateValues... -> fillTemplateTokens... sigan igual.
        $tpl = [
            'content' => [
                [
                    'title' => '{{SEO_TITLE}}',
                ],
                [
                    'editor' => <<<HTML
<!-- Título SEO (≤ 60 caracteres): {{SEO_TITLE}} -->

<div class="container">
  <div class="row">
    <div class="col-md-12">
      <h1>{{HERO_H1}}</h1>
      <p>{{INTRO_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-12">
      <h2>{{SECTION_1_TITLE}}</h2>
      <p>{{SECTION_1_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-6">
      <h3>{{SECTION_2_TITLE}}</h3>
      <p>{{SECTION_2_P}}</p>
    </div>
    <div class="col-md-6">
      <h3>{{SECTION_3_TITLE}}</h3>
      <p>{{SECTION_3_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-12">
      <h2>{{SECTION_4_TITLE}}</h2>
      <p>{{SECTION_4_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-4">
      <h3>{{SECTION_5_TITLE}}</h3>
      <p>{{SECTION_5_P}}</p>
    </div>
    <div class="col-md-4">
      <h3>{{SECTION_6_TITLE}}</h3>
      <p>{{SECTION_6_P}}</p>
    </div>
    <div class="col-md-4">
      <h3>{{SECTION_7_TITLE}}</h3>
      <p>{{SECTION_7_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-12">
      <h2>{{SECTION_8_TITLE}}</h2>
      <p>{{SECTION_8_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-4">
      <h3>{{SECTION_9_TITLE}}</h3>
      <p>{{SECTION_9_P}}</p>
    </div>
    <div class="col-md-4">
      <h3>{{SECTION_10_TITLE}}</h3>
      <p>{{SECTION_10_P}}</p>
    </div>
    <div class="col-md-4">
      <h3>{{SECTION_11_TITLE}}</h3>
      <p>{{SECTION_11_P}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-12">
      <h2>{{FAQ_TITLE}}</h2>
      <p>{{FAQ_INTRO}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-4">
      <h3>{{FAQ_1_Q}}</h3>
      <p>{{FAQ_1_A}}</p>
    </div>
    <div class="col-md-4">
      <h3>{{FAQ_2_Q}}</h3>
      <p>{{FAQ_2_A}}</p>
    </div>
    <div class="col-md-4">
      <h3>{{FAQ_3_Q}}</h3>
      <p>{{FAQ_3_A}}</p>
    </div>
  </div>
</div>

<div class="container">
  <div class="row">
    <div class="col-md-12">
      <h2>{{SECTION_12_TITLE}}</h2>
      <p>{{SECTION_12_P}}</p>
      <p>{{FINAL_CTA}}</p>
    </div>
  </div>
</div>
HTML
                ],
            ],
        ];

        return [$tpl, 'PLAIN_HTML_INLINE'];
    }

    // ✅ Lo demás queda EXACTAMENTE igual a tu lógica actual
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

    // ========================= TOKENS META =========================
    private function collectTokensMeta(array $tpl): array
    {
        $meta = []; // TOKEN => ['type' => 'plain'|'editor', 'wrap_p' => bool]

        $walk = function ($node) use (&$walk, &$meta) {
            if (!is_array($node)) return;

            foreach ($node as $k => $v) {
                if (is_string($k) && in_array($k, ['editor','title','text'], true) && is_string($v) && str_contains($v, '{{')) {
                    if (preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $v, $m)) {
                        foreach (($m[1] ?? []) as $tok) {
                            $tok = (string)$tok;
                            if ($tok === '') continue;

                            $type  = ($k === 'editor') ? 'editor' : 'plain';
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

    // ========================= GENERACIÓN BATCH =========================
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

        usort($allKeys, function ($a, $b) {
            $ra = $this->tokenRank((string)$a);
            $rb = $this->tokenRank((string)$b);
            if ($ra === $rb) return strcmp((string)$a, (string)$b);
            return $ra <=> $rb;
        });

        $chunks = array_chunk($allKeys, 12);
        $values = [];

        $variation = "seed={$seed}|job=" . substr($this->jobUuid, 0, 8) . "|rid=" . (int)$this->registroId;

        foreach ($chunks as $chunkKeys) {
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

            $alreadySectionTitles = [];
            foreach ($values as $k => $v) {
                if (preg_match('~^SECTION_\d+_TITLE$~', (string)$k)) {
                    $alreadySectionTitles[] = trim(strip_tags($this->toStr($v)));
                }
            }
            $alreadySectionTitles = array_slice(array_filter($alreadySectionTitles), 0, 20);
            $alreadyStr = implode(' | ', $alreadySectionTitles);

            $planLines = [];
            for ($i=1; $i<=26; $i++) {
                $planLines[] = "SECTION_{$i}: " . ($themePlan[$i] ?? 'Tema');
            }
            $planText = implode("\n", $planLines);

            $editorList = implode(', ', $editorKeys);
            $plainList  = implode(', ', $plainKeys);

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

PLAN DE TEMAS (OBLIGATORIO):
{$planText}

NO REPETIR TÍTULOS:
{$noRepetirTitles}

NO REPETIR TEXTOS:
{$noRepetirCorpus}

YA USADOS (evita repetir estos títulos):
{$alreadyStr}

REGLAS:
- Devuelve EXACTAMENTE las keys del ESQUEMA (no agregues ni quites).
- PROHIBIDO valores vacíos: nada de "" ni null.
- TODOS los valores deben ser STRING (nunca arrays ni objetos).
- Keys editor: 1–3 frases. Permite SOLO <strong> y <br>. NO uses <p>.
- Keys plain: solo texto plano (sin HTML).
- SECTION_X_TITLE y SECTION_X_P deben seguir su tema del plan y ser DIFERENTES entre sí.
- No repitas la keyword en todas las líneas.
- Evita texto genérico tipo "Contenido útil", "Bloque preparado", "Texto ordenado".

LISTA editor:
{$editorList}

LISTA plain:
{$plainList}

ESQUEMA:
{$schemaJson}
PROMPT;

            $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 1800, temperature: 0.90, topP: 0.9, jsonMode: true);

            $arr = $this->safeParseOrRepairForKeys($apiKey, $model, $raw, $chunkKeys, $brief, $variation);

            foreach ($chunkKeys as $k) {
                $k = (string)$k;
                $meta = $tokensMeta[$k] ?? ['type' => 'plain', 'wrap_p' => false];

                // ✅ SIEMPRE string
                $val = $this->toStr($arr[$k] ?? '');
                $val = $this->normalizeValueByTokenMeta($val, $meta);

                if ($this->isEmptyValue($val)) {
                    $val = $this->fallbackForToken($k, $meta, $seed, $themePlan);
                }

                $values[$k] = $val;
            }
        }

        // Anti repetición exacta en SECTION_P
        $seen = [];
        foreach ($values as $k => $v) {
            if (!preg_match('~^SECTION_(\d+)_P$~', (string)$k, $m)) continue;
            $plain = mb_strtolower(trim(strip_tags($this->toStr($v))));
            if ($plain === '') continue;

            if (isset($seen[$plain])) {
                $values[$k] = $this->fallbackForToken((string)$k, $tokensMeta[$k] ?? ['type'=>'editor','wrap_p'=>false], $seed + 77, $themePlan, true);
            } else {
                $seen[$plain] = true;
            }
        }

        return $values;
    }

    private function tokenRank(string $k): int
    {
        // ✅ tokens críticos primero
        if (in_array($k, ['SEO_TITLE','HERO_H1','KIT_H1','PRICE_H2','CLIENTS_LABEL','TESTIMONIOS_TITLE'], true)) return 0;

        if (preg_match('~^SECTION_\d+_TITLE$~', $k)) return 20;
        if (preg_match('~^SECTION_\d+_P$~', $k)) return 30;
        return 10;
    }

    // ========================= THEME PLAN =========================
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

    // ========================= DEEPSEEK =========================
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

    // ========================= PARSE / REPAIR =========================
    private function safeParseOrRepairForKeys(string $apiKey, string $model, string $raw, array $keys, array $brief, string $variation): array
    {
        try {
            $a = $this->parseJsonStrict($raw);
            return $this->filterKeys($a, $keys);
        } catch (\Throwable $e) {
            $loose = $this->parseJsonLoosePairs($raw);
            $loose = $this->filterKeys($loose, $keys);
            if (count($loose) >= max(2, (int)floor(count($keys) * 0.4))) return $loose;

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

        $skel = [];
        foreach ($keys as $k) $skel[$k] = "";
        $schema = json_encode($skel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $briefAngle = $this->toStr($brief['angle'] ?? '');
        $briefTone  = $this->toStr($brief['tone'] ?? '');

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. RESPUESTA MINIFICADA.
VARIATION (NO imprimir): {$variation}

Corrige el JSON roto y devuelve EXACTAMENTE las keys del ESQUEMA (sin agregar ni quitar).
PROHIBIDO valores vacíos, PROHIBIDO keys vacías "" y PROHIBIDO arrays/objetos.
Todos los valores deben ser STRING.

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
        $start = strpos($raw, '{');
        $end   = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, $end - $start + 1);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('JSON inválido');

        if (array_key_exists('', $data)) unset($data['']);
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

    // ========================= NORMALIZE / FALLBACK =========================
    private function normalizeValueByTokenMeta(string $v, array $meta): string
    {
        $type  = $meta['type'] ?? 'plain';
        $wrapP = (bool)($meta['wrap_p'] ?? false);

        if ($type === 'editor') {
            $s = $this->normalizeEditorFragment($v);

            // Si el token estaba en <p>{{TOKEN}}</p>, entonces Elementor lo trata como texto plano
            if ($wrapP) {
                $s = trim(strip_tags($s));
                $s = preg_replace('~\s+~u', ' ', (string)$s);
                $s = trim((string)$s);
                $s = $this->fixJoinedWordsPreservingTags($s);
                return $s;
            }

            $s = $this->fixJoinedWordsPreservingTags($s);
            return $s;
        }

        $s = trim(strip_tags($v));
        $s = preg_replace('~\s+~u', ' ', (string)$s);
        $s = trim((string)$s);
        $s = $this->fixJoinedWordsPreservingTags($s);
        return $s;
    }

    private function normalizeEditorFragment(string $html): string
    {
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
        $pick = function(array $arr) use ($seed, $tok) {
            $i = $this->stableSeedInt($seed . '|' . $tok) % max(1, count($arr));
            return $arr[$i] ?? $arr[0];
        };

        // ✅ específicos para evitar genéricos
        if ($tok === 'CLIENTS_LABEL') {
            return $pick(["Marcas", "Equipos", "Negocios", "Proyectos", "Agencias", "Empresas"]);
        }
        if ($tok === 'KIT_H1') {
            return $pick([
                "Bloques listos para publicar",
                "Secciones con intención",
                "Estructura lista para tu web",
                "Copy y bloques bien ordenados",
                "Contenido preparado para convertir",
            ]);
        }
        if ($tok === 'PRICE_H2') {
            return $pick([
                "Entrega clara y sin sorpresas",
                "Alcance definido y ordenado",
                "Plan de trabajo con entregables",
                "Implementación simple y medible",
                "Listo para publicar en Elementor",
            ]);
        }
        if ($tok === 'TESTIMONIOS_TITLE') {
            return $pick([
                "Lo que más valoran",
                "Resultados que se suelen notar",
                "Comentarios habituales",
                "Por qué funciona el enfoque",
                "Lo que cambia al aplicarlo",
            ]);
        }

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

            if ($wrapP) return trim(strip_tags($p));
            return ($type === 'editor') ? $p : trim(strip_tags($p));
        }

        if (str_starts_with($tok, 'BTN_')) {
            return match ($tok) {
                'BTN_PRESUPUESTO' => $pick(["Solicitar presupuesto","Pedir propuesta","Ver opciones"]),
                'BTN_REUNION'     => $pick(["Agendar llamada","Reservar llamada","Hablar ahora"]),
                'BTN_KITDIGITAL'  => $pick(["Ver información","Consultar","Empezar"]),
                default           => $pick(["Ver opciones","Continuar"]),
            };
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

        if ($tok === 'FAQ_TITLE') return "Preguntas frecuentes";

        if ($tok === 'FINAL_CTA') {
            $txt = $pick([
                "¿Quieres publicarlo y avanzar? <strong>Te guiamos con el siguiente paso.</strong>",
                "¿Listo para mejorar el mensaje? <strong>Hagamos una propuesta clara.</strong>",
                "¿Buscas una entrega sin vueltas? <strong>Agenda y lo estructuramos.</strong>",
            ]);
            return ($type === 'editor' && !$wrapP) ? $txt : trim(strip_tags($txt));
        }

        // ✅ genérico pero no repetitivo ni “Contenido útil…”
        $generic = $pick([
            "Bloque redactado con foco en claridad y estructura.",
            "Texto preparado para publicar y ajustar sin relleno.",
            "Sección lista para adaptar con mensaje directo.",
        ]);
        return ($type === 'editor' && !$wrapP) ? $generic : trim(strip_tags($generic));
    }

    // ========================= FIX: palabras pegadas (AgenciasAgencias) =========================
    private function fixJoinedWordsInValues(array $values, array $tokensMeta): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $meta = $tokensMeta[(string)$k] ?? ['type'=>'plain','wrap_p'=>false];
            $out[(string)$k] = $this->normalizeValueByTokenMeta($this->toStr($v), $meta);
        }
        return $out;
    }

    private function fixJoinedWordsPreservingTags(string $s): string
    {
        $s = (string)$s;
        if ($s === '') return $s;

        // Si hay tags, separa segmentos y aplica fix solo al texto
        if (str_contains($s, '<')) {
            $parts = preg_split('~(<[^>]+>)~u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) return $this->fixJoinedWordsPlain($s);

            foreach ($parts as &$p) {
                if ($p === '' || str_starts_with($p, '<')) continue;
                $p = $this->fixJoinedWordsPlain($p);
            }
            unset($p);
            return trim(implode('', $parts));
        }

        return $this->fixJoinedWordsPlain($s);
    }

    private function fixJoinedWordsPlain(string $s): string
    {
        $s = preg_replace('~\s+~u', ' ', (string)$s);
        $s = trim((string)$s);

        // Inserta espacio entre letra minúscula y MAYÚSCULA pegadas: "AgenciasAgencias"
        // (incluye acentos)
        $s = preg_replace('~([a-záéíóúñü])([A-ZÁÉÍÓÚÑÜ])~u', '$1 $2', (string)$s);

        // Inserta espacio después de puntuación si está pegada a letra
        $s = preg_replace('~([.!?])([A-Za-zÁÉÍÓÚÑÜáéíóúñü])~u', '$1 $2', (string)$s);

        // Doble espacio -> uno
        $s = preg_replace('~\s+~u', ' ', (string)$s);
        return trim((string)$s);
    }

    // ========================= IA: reforzar tokens críticos (KIT_H1/PRICE_H2/CLIENTS_LABEL/TESTIMONIOS_TITLE/CONT_*) =========================
    private function ensureCriticalTokensNotGeneric(
        string $apiKey,
        string $model,
        array $tokensMeta,
        array $values,
        array $brief,
        int $seed,
        array $themePlan,
        array $usedTitles,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): array {
        $critical = ['KIT_H1','PRICE_H2','CLIENTS_LABEL','TESTIMONIOS_TITLE','SEO_TITLE','HERO_H1'];

        // CONT_1..CONT_12 si existen
        for ($i=1; $i<=12; $i++) $critical[] = "CONT_{$i}";

        // Solo los que existan en la plantilla
        $want = [];
        foreach ($critical as $k) {
            if (isset($tokensMeta[$k])) $want[] = $k;
        }
        if (empty($want)) return $values;

        $kwLower = mb_strtolower($this->shortKw());
        $needs = [];
        foreach ($want as $k) {
            $v = trim($this->toStr($values[$k] ?? ''));
            $plain = mb_strtolower(trim(strip_tags($v)));

            // reglas: no genérico, no vacío, no “perfil no recomendado...”
            if ($plain === '') { $needs[] = $k; continue; }

            if (str_contains($plain, 'contenido útil') ||
                str_contains($plain, 'bloque preparado') ||
                str_contains($plain, 'texto ordenado') ||
                str_contains($plain, 'perfil no recomendado')) {
                $needs[] = $k; continue;
            }

            // muy corto para H1/H2
            if (in_array($k, ['KIT_H1','PRICE_H2','TESTIMONIOS_TITLE'], true) && mb_strlen($plain) < 10) {
                $needs[] = $k; continue;
            }

            // CLIENTS_LABEL debe ser corto y sin punto
            if ($k === 'CLIENTS_LABEL') {
                if (str_contains($plain, '.') || count(preg_split('~\s+~u', $plain, -1, PREG_SPLIT_NO_EMPTY)) > 3) {
                    $needs[] = $k; continue;
                }
            }

            // evitar repetir la keyword idéntica en tokens de heading
            if (in_array($k, ['KIT_H1','PRICE_H2','TESTIMONIOS_TITLE'], true) && $plain === $kwLower) {
                $needs[] = $k; continue;
            }
        }

        $needs = array_values(array_unique($needs));
        if (empty($needs)) return $values;

        // Regenerar por IA (solo esos keys)
        $regenerated = $this->regenerateSpecificTokensViaIA(
            apiKey: $apiKey,
            model: $model,
            keys: $needs,
            tokensMeta: $tokensMeta,
            brief: $brief,
            seed: $seed,
            themePlan: $themePlan,
            usedTitles: $usedTitles,
            noRepetirTitles: $noRepetirTitles,
            noRepetirCorpus: $noRepetirCorpus,
            currentValues: $values
        );

        // Mezclar + normalizar + fix pegados
        foreach ($regenerated as $k => $v) {
            $k = (string)$k;
            $meta = $tokensMeta[$k] ?? ['type'=>'plain','wrap_p'=>false];
            $val = $this->normalizeValueByTokenMeta($this->toStr($v), $meta);
            if ($this->isEmptyValue($val)) {
                $val = $this->fallbackForToken($k, $meta, $seed + 101, $themePlan, true);
            }
            $values[$k] = $val;
        }

        return $values;
    }

    private function regenerateSpecificTokensViaIA(
        string $apiKey,
        string $model,
        array $keys,
        array $tokensMeta,
        array $brief,
        int $seed,
        array $themePlan,
        array $usedTitles,
        string $noRepetirTitles,
        string $noRepetirCorpus,
        array $currentValues
    ): array {
        $skel = [];
        foreach ($keys as $k) $skel[(string)$k] = "";

        $schema = json_encode($skel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $briefAngle = $this->toStr($brief['angle'] ?? '');
        $briefTone  = $this->toStr($brief['tone'] ?? '');
        $briefCTA   = $this->toStr($brief['cta'] ?? '');
        $briefAud   = $this->toStr($brief['audience'] ?? '');

        $variation = "seed={$seed}|critical_regen=1|job=" . substr($this->jobUuid, 0, 8) . "|rid=" . (int)$this->registroId;

        $banTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 20));

        $planLines = [];
        for ($i=1; $i<=26; $i++) $planLines[] = "SECTION_{$i}: " . ($themePlan[$i] ?? 'Tema');
        $planText = implode("\n", $planLines);

        $currentMini = [];
        foreach ($keys as $k) $currentMini[(string)$k] = $this->toStr($currentValues[(string)$k] ?? '');
        $currentJson = json_encode($currentMini, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Reglas específicas por token
        $rules = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            if ($k === 'CLIENTS_LABEL') $rules[] = "- CLIENTS_LABEL: 1–2 palabras, sin punto, tipo etiqueta (ej: Marcas/Equipos/Agencias).";
            if (in_array($k, ['KIT_H1','PRICE_H2','TESTIMONIOS_TITLE'], true)) $rules[] = "- {$k}: título corto 4–9 palabras, específico, NO genérico.";
            if (preg_match('~^CONT_\d+$~', $k)) $rules[] = "- {$k}: 1–2 frases con separación clara (usa punto o ' — '), NO pegado tipo 'AgenciasAgencias'.";
            if (in_array($k, ['SEO_TITLE'], true)) $rules[] = "- SEO_TITLE: 55–65 caracteres aprox, sin genéricos.";
            if (in_array($k, ['HERO_H1'], true)) $rules[] = "- HERO_H1: 6–12 palabras, humano, distinto a SEO_TITLE.";
        }
        $rulesText = implode("\n", array_values(array_unique($rules)));

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

PLAN (referencia):
{$planText}

PROHIBIDO:
- Valores vacíos.
- Texto genérico tipo "Contenido útil", "Bloque preparado", "Texto ordenado".
- Frase "Perfil no recomendado".
- Repetir títulos prohibidos.

Títulos prohibidos (no repetir):
{$banTitles}

NO REPETIR TÍTULOS:
{$noRepetirTitles}

NO REPETIR TEXTOS:
{$noRepetirCorpus}

Reglas por token:
{$rulesText}

Valores actuales (para NO copiar):
{$currentJson}

ESQUEMA (devuelve EXACTAMENTE estas keys):
{$schema}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 650, temperature: 0.95, topP: 0.9, jsonMode: true);

        $arr = [];
        try {
            $arr = $this->parseJsonStrict($raw);
        } catch (\Throwable $e) {
            $arr = $this->parseJsonLoosePairs($raw);
        }

        // solo keys esperadas
        $out = [];
        foreach ($keys as $k) {
            $k = (string)$k;
            if (isset($arr[$k])) $out[$k] = $this->toStr($arr[$k]);
        }

        return $out;
    }

    // ========================= TITULOS SIEMPRE DISTINTOS (SEO_TITLE/HERO_H1) =========================
    private function ensureUniqueTitles(
        string $apiKey,
        string $model,
        array $tokensMeta,
        array $values,
        array $usedTitles,
        array $brief,
        int $seed,
        array $themePlan,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): array {
        $hasSeo = isset($tokensMeta['SEO_TITLE']);
        $hasH1  = isset($tokensMeta['HERO_H1']);

        if (!$hasSeo && !$hasH1) return $values;

        $seo = trim(strip_tags($this->toStr($values['SEO_TITLE'] ?? '')));
        $h1  = trim(strip_tags($this->toStr($values['HERO_H1'] ?? '')));

        if ($seo === '' && $hasSeo) $seo = $this->fallbackSpecificTitle($seed, $themePlan, true);
        if ($h1  === '' && $hasH1)  $h1  = $this->fallbackSpecificTitle($seed + 13, $themePlan, false);

        if ($hasSeo && $this->isTitleTooSimilar($seo, $usedTitles)) {
            $seo = $this->rewriteTitleViaIA($apiKey, $model, $seo, $usedTitles, $brief, $seed, $themePlan, $noRepetirTitles, $noRepetirCorpus, 'SEO_TITLE');
        }

        if ($hasH1 && $this->isTitleTooSimilar($h1, $usedTitles)) {
            $h1 = $this->rewriteTitleViaIA($apiKey, $model, $h1, $usedTitles, $brief, $seed + 9, $themePlan, $noRepetirTitles, $noRepetirCorpus, 'HERO_H1');
        }

        if ($hasSeo && $hasH1) {
            $a = mb_strtolower($seo);
            $b = mb_strtolower($h1);
            if ($a === $b || $this->jaccardBigrams($a, $b) >= 0.70) {
                $h1 = $this->rewriteTitleViaIA($apiKey, $model, $h1, array_merge($usedTitles, [$seo]), $brief, $seed + 21, $themePlan, $noRepetirTitles, $noRepetirCorpus, 'HERO_H1');
            }
        }

        if ($hasSeo) $values['SEO_TITLE'] = trim($seo);
        if ($hasH1)  $values['HERO_H1']   = trim($h1);

        return $values;
    }

    private function isTitleTooSimilar(string $title, array $usedTitles): bool
    {
        $t = mb_strtolower(trim($title));
        if ($t === '') return false;

        foreach ($usedTitles as $u) {
            $u = mb_strtolower(trim((string)$u));
            if ($u === '') continue;

            if ($t === $u) return true;
            if ($this->jaccardBigrams($t, $u) >= 0.62) return true;
        }
        return false;
    }

    private function rewriteTitleViaIA(
        string $apiKey,
        string $model,
        string $current,
        array $usedTitles,
        array $brief,
        int $seed,
        array $themePlan,
        string $noRepetirTitles,
        string $noRepetirCorpus,
        string $field
    ): string {
        $variation = "seed={$seed}|title_rewrite=1|field={$field}|job=" . substr($this->jobUuid, 0, 8) . "|rid=" . (int)$this->registroId;

        $briefAngle = $this->toStr($brief['angle'] ?? '');
        $briefTone  = $this->toStr($brief['tone'] ?? '');
        $tema = $themePlan[1] ?? 'Propuesta de valor';

        $ban = implode(' | ', array_slice(array_filter($usedTitles), 0, 20));
        $schema = '{"title":""}';

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. RESPUESTA MINIFICADA.
VARIATION (NO imprimir): {$variation}

Keyword: {$this->keyword}
Tipo: {$this->tipo}
Campo: {$field}

BRIEF:
- Ángulo: {$briefAngle}
- Tono: {$briefTone}

Tema guía: {$tema}

PROHIBIDO:
- Repetir títulos de la lista.
- Frases genéricas tipo "Contenido útil", "Bloque preparado", "Texto ordenado".
- Títulos vacíos.

Debe ser:
- Distinto, específico y natural.
- SEO_TITLE: 55–65 caracteres aprox (sin forzar).
- HERO_H1: 6–12 palabras aprox.

Títulos prohibidos (no repetir):
{$ban}

NO REPETIR TÍTULOS:
{$noRepetirTitles}

NO REPETIR TEXTOS:
{$noRepetirCorpus}

Actual (para mejorar, no copiar):
{$current}

ESQUEMA:
{$schema}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 260, temperature: 0.95, topP: 0.9, jsonMode: true);

        $arr = [];
        try {
            $arr = $this->parseJsonStrict($raw);
        } catch (\Throwable $e) {
            $arr = $this->parseJsonLoosePairs($raw);
        }

        $new = trim(strip_tags($this->toStr($arr['title'] ?? '')));
        if ($new === '') return $this->fallbackSpecificTitle($seed, $themePlan, $field === 'SEO_TITLE');

        if ($this->isTitleTooSimilar($new, $usedTitles)) {
            return $this->fallbackSpecificTitle($seed + 31, $themePlan, $field === 'SEO_TITLE');
        }

        return $new;
    }

    private function fallbackSpecificTitle(int $seed, array $themePlan, bool $seoStyle): string
    {
        $kw = $this->shortKw();
        $tema = $themePlan[1] ?? 'estructura';

        $optsSeo = [
            "{$kw}: estructura y mensaje para convertir",
            "Contenido para {$kw} con enfoque en {$tema}",
            "Estrategia y copy para {$kw} sin relleno",
            "{$kw}: secciones claras, CTA y SEO natural",
        ];

        $optsH1 = [
            "Estructura y copy para {$kw}",
            "{$kw} con mensaje claro y secciones útiles",
            "Texto y bloques listos para {$kw}",
            "Página de {$kw} enfocada en leads",
        ];

        $list = $seoStyle ? $optsSeo : $optsH1;
        $i = $this->stableSeedInt("{$seed}|fallback_title") % max(1, count($list));
        return $list[$i] ?? $list[0];
    }

    // ========================= REEMPLAZO TOKENS =========================
    private function fillTemplateTokensWithStats(array $tpl, array $values): array
    {
        $dict = [];
        foreach ($values as $k => $v) {
            $dict['{{' . (string)$k . '}}'] = $this->toStr($v);
        }

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

    // ========================= BRIEF =========================
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

    // ========================= SIMILARIDAD =========================
    private function valuesToPlainText(array $values): string
    {
        $parts = [];
        foreach ($values as $v) $parts[] = strip_tags($this->toStr($v));
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
        $s = preg_replace('~\s+~u', ' ', (string)$s);
        $s = trim((string)$s);
        if ($s === '') return [];
        $chars = preg_split('~~u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        $n = count($chars);
        for ($i = 0; $i < $n - 1; $i++) $out[$chars[$i] . $chars[$i + 1]] = 1;
        return $out;
    }

    // ========================= HISTORIAL =========================
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
        foreach ($arr as $v) $parts[] = strip_tags($this->toStr($v));
        $txt = implode(' ', array_filter($parts));
        $txt = preg_replace('~\s+~u', ' ', (string)$txt);
        return trim((string)$txt);
    }

    // ========================= BLINDAJE STRINGS =========================
    private function stringifyValues(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) $out[(string)$k] = $this->toStr($v);
        return $out;
    }

    // ========================= UTILS (ANTI Array->string) =========================
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

    private function shortKw(): string
    {
        $kw = trim((string)$this->keyword);
        if ($kw === '') return 'tu proyecto';
        return mb_substr($kw, 0, 70);
    }
    public function failed(\Throwable $e): void
{
    try {
        Dominios_Contenido_DetallesModel::where('id_dominio_contenido_detalle', (int)$this->detalleId)
            ->update([
                'estatus' => 'error_final',
                'error'   => $e->getMessage(),
            ]);
    } catch (\Throwable $ignore) {}
}
}
