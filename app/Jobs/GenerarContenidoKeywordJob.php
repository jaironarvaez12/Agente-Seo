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
use App\Models\Ajuste_Ia;
use Illuminate\Support\Facades\Cache;
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

    //obtener prompt
    private function obtenerAjuste(string $clave, string $porDefecto = ''): string
    {
        return Cache::remember("ajuste:$clave", 300, function () use ($clave, $porDefecto) {
            $valor = (string) optional(Ajuste_Ia::where('clave', $clave)->first())->valor;
            $valor = trim($valor);
            return $valor !== '' ? $valor : $porDefecto;
        });
    }
    private function renderizarPrompt(string $plantilla, array $variables): string
    {
        $reemplazos = [];
        foreach ($variables as $k => $v) {
            $reemplazos['{{' . strtoupper($k) . '}}'] = (string) $v;
        }
        return strtr($plantilla, $reemplazos);
    }
    
    public function handle(): void
    {
        $registro = null;

        try {
            $registro = Dominios_Contenido_DetallesModel::where(
                'id_dominio_contenido_detalle',
                (int) $this->detalleId
            )->first();

            if (!$registro) {
                throw new \RuntimeException("NO_RETRY: No existe el detalle reservado (detalleId={$this->detalleId}).");
            }

            $this->registroId = (int) $registro->id_dominio_contenido_detalle;

            $regUuid = trim((string) ($registro->job_uuid ?? ''));
            $jobUuid = trim((string) ($this->jobUuid ?? ''));

            if ($regUuid === '' || $jobUuid === '') {
                throw new \RuntimeException("NO_RETRY: job_uuid vacío (registro={$regUuid} job={$jobUuid}).");
            }
            if ($regUuid !== $jobUuid) {
                throw new \RuntimeException("NO_RETRY: job_uuid no coincide. esperado={$regUuid} recibido={$jobUuid}.");
            }

            if ($registro->estatus === 'generado' && !empty($registro->contenido_html)) return;

            $registro->update([
                'estatus' => 'en_proceso',
                'modelo'  => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                'error'   => null,
            ]);

            $apiKey = (string) env('DEEPSEEK_API_KEY', '');
            $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');
            if ($apiKey === '') throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');

            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int) $this->idDominio);

            $tokensMeta = $this->collectTokensMeta($tpl);
            if (empty($tokensMeta)) {
                throw new \RuntimeException("NO_RETRY: La plantilla no contiene tokens {{TOKEN}}. Template: {$tplPath}");
            }

            $prev = Dominios_Contenido_DetallesModel::where('id_dominio_contenido', (int) $this->idDominioContenido)
                ->where('id_dominio_contenido_detalle', '!=', (int) $this->registroId)
                ->whereNotNull('draft_html')
                ->orderByDesc('id_dominio_contenido_detalle')
                ->limit(10)
                ->get(['title', 'draft_html']);

            $usedTitles   = [];
            $usedCorpus   = [];
            $usedHeadings = [];

            foreach ($prev as $row) {
                if (!empty($row->title)) $usedTitles[] = (string) $row->title;
                $usedCorpus[] = $this->copyTextFromDraftJson((string) $row->draft_html);
                $usedHeadings = array_merge($usedHeadings, $this->extractHeadingsFromDraftJson((string)$row->draft_html));
            }

            $noRepetirTitles   = implode(' | ', array_slice(array_filter($usedTitles), 0, 20));
            $noRepetirCorpus   = $this->compactHistory($usedCorpus, 3000);
            $lastCorpus        = trim((string) ($usedCorpus[0] ?? ''));

            $usedHeadings      = array_values(array_unique(array_filter($usedHeadings)));
            $noRepetirHeadings = implode(' | ', array_slice($usedHeadings, 0, 140));

            $finalValues = null;

            for ($cycle = 1; $cycle <= 2; $cycle++) {
                $brief = $this->creativeBrief();
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
                    noRepetirCorpus: $noRepetirCorpus,
                    noRepetirHeadings: $noRepetirHeadings,
                    usedTitles: $usedTitles // ✅ NUEVO
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
                    noRepetirCorpus: $noRepetirCorpus,
                    noRepetirHeadings: $noRepetirHeadings
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
                    noRepetirCorpus: $noRepetirCorpus,
                    noRepetirHeadings: $noRepetirHeadings
                );

                $currentText = $this->valuesToPlainText($values);
                $sim = ($lastCorpus !== '') ? $this->jaccardBigrams($currentText, $lastCorpus) : 0.0;

                $finalValues = $values;
                if ($sim < 0.45) break;
            }

            if (!is_array($finalValues)) throw new \RuntimeException('No se pudo generar valores finales');

            [$filled, $replacedCount, $remainingTokens] = $this->fillTemplateTokensWithStats($tpl, $finalValues);

            if ($replacedCount < 1) throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}");
            if (!empty($remainingTokens)) throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remainingTokens, 0, 120)));
            
              // 1) Candidatos (en orden). Si no hay SEO_TITLE/H1, usa el primer H2 (SECTION_1_TITLE)
               $seo  = trim(strip_tags($this->toStr($finalValues['SEO_TITLE'] ?? '')));
                $h1   = trim(strip_tags($this->toStr($finalValues['HERO_H1'] ?? '')));
                $h2_1 = trim(strip_tags($this->toStr($finalValues['SECTION_1_TITLE'] ?? '')));

                $kwPlain = trim((string)$this->keyword);

                // Elegimos candidato y además guardamos "de dónde vino"
                $source = 'KW';
                $titleCandidate = $kwPlain;

                if ($seo !== '') { $titleCandidate = $seo; $source = 'SEO'; }
                elseif ($h1 !== '') { $titleCandidate = $h1; $source = 'H1'; }
                elseif ($h2_1 !== '') { $titleCandidate = $h2_1; $source = 'H2'; }

                // Si quedó igual a keyword y hay H2, usa H2
                if ($kwPlain !== '' && mb_strtolower(trim($titleCandidate)) === mb_strtolower($kwPlain) && $h2_1 !== '') {
                    $titleCandidate = $h2_1;
                    $source = 'H2';
                }

                // Limpieza SUAVE (sin fallback genérico)
                $title = trim(preg_replace('~\s+~u', ' ', strip_tags($titleCandidate)));

                // ✅ SOLO recorta si viene de SEO_TITLE
                if ($source === 'SEO') {
                    $title = $this->smartTruncateTitle($title, 60);
                }

                // Si por algo queda vacío, usa H2 completo (sin recorte)
                if ($title === '' && $h2_1 !== '') $title = trim(preg_replace('~\s+~u', ' ', $h2_1));
                if ($title === '') $title = $kwPlain;



            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . (int) $registro->id_dominio_contenido_detalle;

            $registro->update([
                'title'          => $title,
                'slug'           => $slug,
                'draft_html'     => json_encode($finalValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'contenido_html' => json_encode($filled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estatus'        => 'generado',
                'error'          => null,
            ]);

            \App\Jobs\TrabajoEnviarContenidoWordPress::dispatch(
                (int) $registro->id_dominio,
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

                if ($noRetry) { $this->fail($e); return; }
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
<h2>{{SECTION_1_TITLE}}</h2>
{{SECTION_1_P}}

<h2>{{SECTION_4_TITLE}}</h2>
{{SECTION_4_P}}

<h3>{{SECTION_2_TITLE}}</h3>
{{SECTION_2_P}}

<h3>{{SECTION_3_TITLE}}</h3>
{{SECTION_3_P}}

<h3>{{SECTION_5_TITLE}}</h3>
{{SECTION_5_P}}

<h3>{{SECTION_6_TITLE}}</h3>
{{SECTION_6_P}}

<h3>{{SECTION_7_TITLE}}</h3>
{{SECTION_7_P}}

<h2>{{SECTION_8_TITLE}}</h2>
{{SECTION_8_P}}

<h3>{{SECTION_9_TITLE}}</h3>
{{SECTION_9_P}}

<h3>{{SECTION_10_TITLE}}</h3>
{{SECTION_10_P}}

<h3>{{SECTION_11_TITLE}}</h3>
{{SECTION_11_P}}

<h2>{{FAQ_TITLE}}</h2>
{{FAQ_INTRO}}

<h3>{{FAQ_1_Q}}</h3>
{{FAQ_1_A}}

<h3>{{FAQ_2_Q}}</h3>
{{FAQ_2_A}}

<h3>{{FAQ_3_Q}}</h3>
{{FAQ_3_A}}

<h2>{{SECTION_12_TITLE}}</h2>
{{SECTION_12_P}}
{{FINAL_CTA}}
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
    string $noRepetirCorpus,
    string $noRepetirHeadings,
    array $usedTitles = []
): array {
    $allKeys = array_keys($tokensMeta);

    usort($allKeys, function ($a, $b) {
        $ra = $this->tokenRank((string)$a);
        $rb = $this->tokenRank((string)$b);
        if ($ra === $rb) return strcmp((string)$a, (string)$b);
        return $ra <=> $rb;
    });

    $chunks = array_chunk($allKeys, 24);
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
            if ($t === 'editor') $editorKeys[] = $k; else $plainKeys[] = $k;
        }

        $briefAngle = $this->toStr($brief['angle'] ?? '');
        $briefTone  = $this->toStr($brief['tone'] ?? '');
        $briefCTA   = $this->toStr($brief['cta'] ?? '');
        $briefAud   = $this->toStr($brief['audience'] ?? '');

        $alreadySectionTitles = [];
        foreach ($values as $kk => $vv) {
            if (preg_match('~^SECTION_\d+_TITLE$~', (string)$kk)) {
                $alreadySectionTitles[] = trim(strip_tags($this->toStr($vv)));
            }
        }
        $alreadySectionTitles = array_slice(array_filter($alreadySectionTitles), 0, 20);
        $alreadyStr = implode(' | ', $alreadySectionTitles);

        // Plan guía interna
        $planLines = [];
        for ($i=1; $i<=26; $i++) $planLines[] = "SECTION_{$i}: " . ($themePlan[$i] ?? 'Tema');
        $planText = implode("\n", $planLines);

        $editorList = implode(', ', $editorKeys);
        $plainList  = implode(', ', $plainKeys);

        $plantillaPrompt = $this->obtenerAjuste('deepseek_prompt_global', '');

        if ($plantillaPrompt === '') {
            throw new \RuntimeException('NO_RETRY: No hay prompt global configurado en ajustes (deepseek_prompt_global).');
        }

        $variables = [
            'variation' => $variation,
            'keyword' => $this->keyword,
            'tipo' => $this->tipo,

            'brief_angle' => $briefAngle,
            'brief_tone' => $briefTone,
            'brief_audience' => $briefAud,
            'brief_cta' => $briefCTA,

            'plan_text' => $planText,

            'no_repetir_headings' => $noRepetirHeadings,
            'no_repetir_titles' => $noRepetirTitles,
            'no_repetir_corpus' => $noRepetirCorpus,
            'already_str' => $alreadyStr,

            'editor_list' => $editorList,
            'plain_list' => $plainList,

            'schema_json' => $schemaJson,
        ];

$prompt = $this->renderizarPrompt($plantillaPrompt, $variables);

        $raw = $this->deepseekText(
            $apiKey,
            $model,
            $prompt,
            maxTokens: 1800,
            temperature: 0.92,
            topP: 0.9,
            jsonMode: true
        );

        $arr = $this->safeParseOrRepairForKeys($apiKey, $model, $raw, $chunkKeys, $brief, $variation);

        // Primer pase: guarda lo bueno, detecta faltantes REALES
        $missing = [];

        foreach ($chunkKeys as $k) {
            $k = (string)$k;
            $meta = $tokensMeta[$k] ?? ['type' => 'plain', 'wrap_p' => false];

            $rawVal = $this->toStr($arr[$k] ?? '');

            // Si vino vacío desde origen => regen batch
            if (trim(strip_tags($rawVal)) === '') {
                $missing[] = $k;
                continue;
            }

            $val = $this->normalizeValueByTokenMeta($rawVal, $meta);

            // Si se vació por limpieza/ruido => fallback directo (NO regen)
            if ($this->isEmptyValue($val)) {
                $values[$k] = $this->fallbackForToken($k, $meta, $seed + 777, $themePlan, true);
                continue;
            }

            $values[$k] = $val;
        }

        // Segundo pase: regen batch 1 sola llamada
        if (!empty($missing)) {
            $regen = $this->regenerateSpecificTokensViaIA(
                apiKey: $apiKey,
                model: $model,
                keys: $missing,
                tokensMeta: $tokensMeta,
                brief: $brief,
                seed: $seed + 555,
                themePlan: $themePlan,
                usedTitles: $usedTitles,
                noRepetirTitles: $noRepetirTitles,
                noRepetirCorpus: $noRepetirCorpus,
                noRepetirHeadings: $noRepetirHeadings,
                currentValues: $values
            );

            foreach ($missing as $k) {
                $k = (string)$k;
                $meta = $tokensMeta[$k] ?? ['type' => 'plain', 'wrap_p' => false];

                $tmp = $this->toStr($regen[$k] ?? '');
                $tmp = $this->normalizeValueByTokenMeta($tmp, $meta);

                if ($this->isEmptyValue($tmp)) {
                    $tmp = $this->fallbackForToken($k, $meta, $seed + 777, $themePlan, true);
                }

                $values[$k] = $tmp;
            }
        }
    }

    // Anti-duplicados simples en párrafos
    $seen = [];
    foreach ($values as $k => $v) {
        if (!preg_match('~^SECTION_(\d+)_P$~', (string)$k)) continue;

        $plain = mb_strtolower(trim(strip_tags($this->toStr($v))));
        if ($plain === '') continue;

        if (isset($seen[$plain])) {
            $values[$k] = $this->fallbackForToken(
                (string)$k,
                $tokensMeta[$k] ?? ['type'=>'editor','wrap_p'=>false],
                $seed + 77,
                $themePlan,
                true
            );
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
        "Propuesta de valor (qué ganas y por qué ahora)",
        "Beneficio principal (resultado tangible)",
        "Beneficios secundarios (comodidad, ahorro, seguridad, rapidez)",
        "Cómo funciona (pasos simples)",
        "Qué incluye el servicio (alcance/entregables)",
        "Para quién es ideal (casos de uso)",
        "Cuándo conviene elegirlo (situaciones típicas)",
        "Diferenciadores (por qué nosotros vs alternativas)",
        "Calidad y estándares (cómo aseguramos el resultado)",
        "Tiempos / disponibilidad / planificación",
        "Opciones o modalidades (según necesidad)",
        "Personalización (adaptado al cliente)",
        "Errores comunes al elegir (y cómo evitarlos)",
        "Objeción: precio (valor vs coste)",
        "Objeción: confianza (garantías realistas)",
        "Objeción: rapidez (qué esperar)",
        "Soporte y comunicación (cómo te atendemos)",
        "Checklist antes de empezar (qué necesitas)",
        "Proceso detallado (lo que ocurre en cada etapa)",
        "Casos típicos / ejemplos (sin inventar datos)",
        "Preguntas frecuentes clave",
        "CTA principal (siguiente paso claro)",
        "CTA alternativa (si no está listo)",
        "Recomendaciones para obtener mejor resultado",
        "Cierre comercial (refuerzo de confianza)",
        "Resumen de ventajas (bullet mental)",
        "Comparativa suave (sin atacar competencia)",
        "Seguridad / políticas / cumplimiento (si aplica)",
        "Experiencia del cliente (lo que suele valorar)",
        "Optimización continua (mejora / seguimiento)",
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
    // Permite SOLO <strong> y <br> (toleramos <p> para poder quitarlo)
    $clean = strip_tags((string)$html, '<strong><br><p>');
    $clean = trim((string)$clean);

    // Quita envoltura <p>...</p> si llega
    if (preg_match('~^\s*<p>\s*(.*?)\s*</p>\s*$~is', $clean, $m)) {
        $clean = trim((string)($m[1] ?? ''));
    }

    // Normaliza <br />
    $clean = str_replace(["<br />", "<br/>"], "<br>", (string)$clean);

    // Quita corchetes tipo [CTA]
    $clean = str_ireplace(['[CTA]', '[cta]', '[Cta]', '[CTA', 'CTA]'], '', (string)$clean);

    // Quita líneas meta tipo "SECTION_1: ..."
    $clean = preg_replace('~(?mi)^\s*SECTION_\d+\s*:\s*.*$~u', '', (string)$clean);

    // Quita "Enfoque:" al inicio de línea
    $clean = preg_replace('~(?mi)^\s*Enfoque\s*:\s*~u', '', (string)$clean);

    // Quita "Tema:" al inicio de línea
    $clean = preg_replace('~(?mi)^\s*Tema\s*:\s*~u', '', (string)$clean);

    // Quita SOLO la plantilla exacta “Tratamos ... Elementor”
    $clean = preg_replace(
        '~Tratamos\s+[^.]{0,180}\s+con\s+criterio:\s+contenido\s+escaneable,\s+ordenado\s+y\s+fácil\s+de\s+adaptar\s+a\s+tu\s+sitio\s+en\s+Elementor\.?~iu',
        '',
        (string)$clean
    );

    // Quita bullets "- ..." (sin tocar guiones internos)
    $clean = preg_replace('~(?m)^\s*-\s+~u', '', (string)$clean);
    $clean = preg_replace('~<br>\s*-\s+~u', '<br>', (string)$clean);

    // Suaviza claims “demasiado marketing”
    $clean = preg_replace(
        '~\b(medible|garantizado|garantizada|desde el primer día|retorno a corto plazo)\b~iu',
        '',
        (string)$clean
    );

    // Arregla unión de tags: </strong>Texto => </strong> Texto
    $clean = preg_replace('~</strong>\s*([A-Za-zÁÉÍÓÚÑÜáéíóúñü¿¡])~u', '</strong> $1', (string)$clean);

    // Limita exceso de <br> (máximo 2)
    if (substr_count($clean, '<br>') > 2) {
        $parts = explode('<br>', $clean);
        $keep  = array_slice($parts, 0, 3);   // deja 2 <br> (3 partes)
        $rest  = array_slice($parts, 3);
        $clean = implode('<br>', $keep) . (count($rest) ? ' ' . implode('. ', array_map('trim', $rest)) : '');
    }

    // Normaliza espacios (sin romper <br>)
    $clean = preg_replace('~[ \t]+~u', ' ', (string)$clean);
    $clean = preg_replace('~\s*<br>\s*~u', '<br>', (string)$clean);
    $clean = preg_replace("~\n{3,}~u", "\n\n", (string)$clean);

    // Quita residuos tipo "(10)" o "(bloque 10)" al final
    $clean = preg_replace('~\s*\((?:bloque\s*)?\d+\)\s*$~iu', '', (string)$clean);

    // Quita esta frase si llegara (por si acaso)
    $clean = preg_replace('~Texto\s+preparado\s+para\s+publicar.*$~iu', '', (string)$clean);

    return trim((string)$clean); // puede ser ''
}




    private function isEmptyValue(string $v): bool
    {
        return trim(strip_tags((string)$v)) === '';
    }

    /**
 * Fallback NO-PLANTILLA.
 * - NO usa themePlan textual como heading
 * - NO agrega "(10)" ni "(bloque 10)"
 * - NO devuelve "Texto preparado para publicar..."
 * - Devuelve SIEMPRE algo (si el modelo falló o quedó vacío tras normalizar)
 */
private function fallbackForToken(
    string $tok,
    array $meta,
    int $seed,
    array $themePlan,
    bool $forceUnique = false
): string {
    $type  = $meta['type'] ?? 'plain';
    $wrapP = (bool)($meta['wrap_p'] ?? false);

    $kw = $this->shortKw();

    // helper determinista
    $pick = function(array $arr) use ($seed, $tok) {
        $i = $this->stableSeedInt($seed . '|' . $tok) % max(1, count($arr));
        return $arr[$i] ?? $arr[0];
    };

    // Etiquetas cortas
    if ($tok === 'CLIENTS_LABEL') {
        return $pick(["Marcas", "Negocios", "Equipos", "Proyectos", "Empresas"]);
    }

    if ($tok === 'FAQ_TITLE') return "Preguntas frecuentes";

    if ($tok === 'FAQ_INTRO') {
        return "Resolvemos dudas habituales sobre {$kw} para que puedas decidir con claridad.";
    }

    // ✅ TITULOS DE SECCION: sin themePlan, sin sufijos
    if (preg_match('~^SECTION_(\d+)_TITLE$~', $tok, $m)) {
        $i = (int)($m[1] ?? 1);

        $opts = [
            "Cómo aplicar {$kw} paso a paso",
            "Qué debes considerar antes de contratar {$kw}",
            "Lo que incluye {$kw} y cómo se entrega",
            "Opciones y modalidades para {$kw} según tu caso",
            "Errores comunes al elegir {$kw} y cómo evitarlos",
            "Cómo reservar {$kw} sin complicaciones",
            "Por qué elegir {$kw} con un enfoque profesional",
            "Precio y opciones: qué influye y cómo decidir",
            "Calidad y seguridad: qué revisar antes de empezar",
            "Soporte y ajustes: cómo se acompaña el proceso",
            "Casos habituales donde {$kw} funciona mejor",
            "Cómo mantener resultados con {$kw} a largo plazo",
        ];

        $t = $this->pickVariant($opts, "sec_title|rid=".(int)$this->registroId."|seed=".$seed."|i=".$i."|kw=".$kw);
        return $t;
    }

    // ✅ PARRAFOS DE SECCION: naturales, sin plantillas, sin "(bloque i)"
    if (preg_match('~^SECTION_(\d+)_P$~', $tok, $m)) {
        $i = (int)($m[1] ?? 1);

        $opts = [
            "Para que {$kw} funcione bien, lo primero es entender el objetivo: qué quieres que sienta el cliente y qué comportamiento buscas (más tiempo, mejor percepción o una experiencia más coherente). Con eso claro, se elige una opción que encaje con tu espacio y se ajusta la intensidad para que sea agradable, no invasiva.",
            "La clave de {$kw} está en el ajuste: no es solo elegir algo bonito, sino definir dónde se percibe, con qué intensidad y en qué momentos. Un buen trabajo evita saturación, mantiene consistencia y deja una sensación natural que acompaña la experiencia sin distraer.",
            "Si estás comparando opciones de {$kw}, céntrate en tres puntos: calidad, control del sistema y soporte para ajustes. Con eso evitas decisiones por impulso y consigues un resultado estable, cómodo y acorde a tu negocio.",
            "En {$kw} la diferencia suele estar en los detalles: una buena elección considera el tipo de público, la actividad del local y la ventilación. Así se consigue una experiencia agradable, coherente con tu marca y fácil de mantener en el tiempo.",
            "Un enfoque profesional en {$kw} prioriza comodidad y control: instalación discreta, configuración rápida y posibilidad de ajustes cuando cambian temporadas o el flujo de clientes. El objetivo es que se note para bien, sin complicarte la operación diaria.",
        ];

        $p = $this->pickVariant($opts, "sec_p|rid=".(int)$this->registroId."|seed=".$seed."|i=".$i."|kw=".$kw);

        if ($wrapP) return trim(strip_tags($p));
        return ($type === 'editor') ? $p : trim(strip_tags($p));
    }

    // ✅ FAQ Q/A
    if (preg_match('~^FAQ_\d+_Q$~', $tok)) {
        $opts = [
            "¿Cuánto tarda en notarse el efecto?",
            "¿Se puede ajustar la intensidad después?",
            "¿Cómo se elige la opción ideal para mi espacio?",
            "¿Qué incluye el servicio y el soporte?",
            "¿Cómo se instala sin interrumpir la actividad?",
        ];
        return $this->pickVariant($opts, "faq_q|rid=".(int)$this->registroId."|seed=".$seed."|tok=".$tok."|kw=".$kw);
    }

    if (preg_match('~^FAQ_\d+_A$~', $tok)) {
        $opts = [
            "Depende del espacio y del objetivo, pero normalmente se nota desde los primeros días con el ajuste correcto. Lo importante es probar intensidad y zonas para que resulte agradable y coherente con la experiencia.",
            "Sí. Ajustar intensidad o distribución es parte del proceso. Si cambia la temporada o el flujo de clientes, se puede retocar para mantener una sensación estable y cómoda.",
            "Se evalúa el tipo de espacio, ventilación, público y el efecto buscado. Con eso se define una propuesta que encaje sin saturar y que sea fácil de mantener.",
            "Incluye definición de objetivos, selección de opción, configuración del sistema y soporte para ajustes. La idea es que quede implementado sin fricción y con seguimiento.",
            "Se coordina una instalación discreta y rápida, idealmente fuera de horas pico. Se deja configurado y se revisa que se perciba donde corresponde.",
        ];
        $a = $this->pickVariant($opts, "faq_a|rid=".(int)$this->registroId."|seed=".$seed."|tok=".$tok."|kw=".$kw);
        return ($type === 'editor' && !$wrapP) ? $a : trim(strip_tags($a));
    }

    // Botones / CTA
    if (str_starts_with($tok, 'BTN_')) {
        return match ($tok) {
            'BTN_PRESUPUESTO' => $pick(["Solicitar presupuesto","Pedir propuesta","Ver opciones"]),
            'BTN_REUNION'     => $pick(["Agendar llamada","Reservar llamada","Hablar ahora"]),
            default           => $pick(["Ver opciones","Continuar"]),
        };
    }

    if ($tok === 'HERO_H1') return $pick([
        "{$kw} con un enfoque claro y natural",
        "Haz que {$kw} se sienta coherente y memorable",
        "{$kw} pensado para una experiencia más cuidada",
    ]);

    if ($tok === 'HERO_KICKER') return $pick([
        "Claro, natural y fácil de implementar",
        "Una experiencia que se nota sin exagerar",
        "Pensado para mejorar la percepción sin ruido",
    ]);

    if ($tok === 'FINAL_CTA') {
        $txt = $pick([
            "¿Quieres empezar con buen criterio? <strong>Cuéntanos tu caso y te orientamos.</strong>",
            "¿Listo para dar el siguiente paso? <strong>Te compartimos una propuesta clara.</strong>",
            "¿Te interesa implementarlo sin complicarte? <strong>Agenda y lo vemos.</strong>",
        ]);
        return ($type === 'editor' && !$wrapP) ? $txt : trim(strip_tags($txt));
    }

    // ✅ Genérico final (sin “Texto preparado…”)
    $generic = $this->pickVariant([
        "Texto listo para ajustar y publicar con claridad.",
        "Contenido preparado para encajar con tu página sin ruido.",
        "Sección redactada con enfoque natural y fácil de leer.",
    ], "gen|rid=".(int)$this->registroId."|seed=".$seed."|tok=".$tok);

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
    string $noRepetirCorpus,
    string $noRepetirHeadings
): array {
    // Tokens críticos base
    $critical = ['KIT_H1','PRICE_H2','CLIENTS_LABEL','TESTIMONIOS_TITLE','SEO_TITLE','HERO_H1'];
    for ($i=1; $i<=12; $i++) $critical[] = "CONT_{$i}";

    // Solo los que existen en la plantilla
    $want = [];
    foreach ($critical as $k) if (isset($tokensMeta[$k])) $want[] = $k;
    if (empty($want)) return $values;

    $kwLower = mb_strtolower(trim($this->shortKw()));

    // Reglas: estos NO pueden ser exactamente la keyword
    $mustNotBeKeywordOnly = ['SEO_TITLE','HERO_H1','KIT_H1','PRICE_H2','TESTIMONIOS_TITLE'];

    $needs = [];

    foreach ($want as $k) {
        $v = trim($this->toStr($values[$k] ?? ''));
        $plain = mb_strtolower(trim(strip_tags($v)));

        // vacío
        if ($plain === '') { $needs[] = $k; continue; }

        // frases genéricas / plantilleras
        if (
            str_contains($plain, 'contenido útil') ||
            str_contains($plain, 'bloque preparado') ||
            str_contains($plain, 'texto ordenado') ||
            str_contains($plain, 'perfil no recomendado')
        ) {
            $needs[] = $k; continue;
        }

        // NO puede ser igual a keyword (incluye SEO_TITLE y HERO_H1)
        if ($kwLower !== '' && in_array($k, $mustNotBeKeywordOnly, true) && $plain === $kwLower) {
            $needs[] = $k; continue;
        }

        // Longitudes mínimas por tipo
        if ($k === 'SEO_TITLE' && mb_strlen($plain) < 25) { $needs[] = $k; continue; }
        if ($k === 'HERO_H1'   && mb_strlen($plain) < 20) { $needs[] = $k; continue; }
        if (in_array($k, ['KIT_H1','PRICE_H2','TESTIMONIOS_TITLE'], true) && mb_strlen($plain) < 10) {
            $needs[] = $k; continue;
        }

        // CLIENTS_LABEL debe ser corto y sin puntuación
        if ($k === 'CLIENTS_LABEL') {
            if (str_contains($plain, '.') || count(preg_split('~\s+~u', $plain, -1, PREG_SPLIT_NO_EMPTY)) > 3) {
                $needs[] = $k; continue;
            }
        }

        // CONT_* demasiado corto o sin "sustancia" (heurística simple)
        if (preg_match('~^CONT_\d+$~', $k)) {
            if (mb_strlen($plain) < 35) { $needs[] = $k; continue; }
        }
    }

    $needs = array_values(array_unique($needs));
    if (empty($needs)) return $values;

    // Regeneración IA SOLO de los tokens que fallaron
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
        noRepetirHeadings: $noRepetirHeadings,
        currentValues: $values
    );

    // Normaliza y aplica fallbacks si algo vuelve vacío
    foreach ($regenerated as $k => $v) {
        $k = (string)$k;
        $meta = $tokensMeta[$k] ?? ['type'=>'plain','wrap_p'=>false];

        $val = $this->normalizeValueByTokenMeta($this->toStr($v), $meta);

        if ($this->isEmptyValue($val)) {
            $val = $this->fallbackForToken($k, $meta, $seed + 101, $themePlan, true);
        }

        $values[$k] = $val;
    }

    // 🔒 Blindaje final: si SEO_TITLE sigue quedando igual a keyword, fuerza fallback
    if (isset($tokensMeta['SEO_TITLE'])) {
        $seo = trim(strip_tags($this->toStr($values['SEO_TITLE'] ?? '')));
        $seoLc = mb_strtolower($seo);
        if ($seo === '' || ($kwLower !== '' && $seoLc === $kwLower) || mb_strlen($seo) < 25) {
            $meta = $tokensMeta['SEO_TITLE'] ?? ['type'=>'plain','wrap_p'=>false];
            $values['SEO_TITLE'] = $this->fallbackForToken('SEO_TITLE', $meta, $seed + 777, $themePlan, true);
        }
    }

    // 🔒 Blindaje final: si HERO_H1 queda igual a keyword, fuerza fallback
    if (isset($tokensMeta['HERO_H1'])) {
        $h1 = trim(strip_tags($this->toStr($values['HERO_H1'] ?? '')));
        $h1Lc = mb_strtolower($h1);
        if ($h1 === '' || ($kwLower !== '' && $h1Lc === $kwLower) || mb_strlen($h1) < 20) {
            $meta = $tokensMeta['HERO_H1'] ?? ['type'=>'plain','wrap_p'=>false];
            $values['HERO_H1'] = $this->fallbackForToken('HERO_H1', $meta, $seed + 999, $themePlan, true);
        }
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
    string $noRepetirHeadings,
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

    $rules = [];
    foreach ($keys as $k) {
        $k = (string)$k;
        if ($k === 'CLIENTS_LABEL') $rules[] = "- CLIENTS_LABEL: 1–2 palabras, etiqueta corta (sin punto).";
        if (in_array($k, ['KIT_H1','PRICE_H2','TESTIMONIOS_TITLE'], true)) $rules[] = "- {$k}: 4–9 palabras, comercial, específico, no genérico.";
        if (preg_match('~^CONT_\d+$~', $k)) $rules[] = "- {$k}: 1–2 frases con valor/beneficio real, sin humo.";
        if ($k === 'SEO_TITLE') $rules[] = "- SEO_TITLE: ≤ 60 caracteres, incluir keyword, enfoque venta/valor.";
        if ($k === 'HERO_H1')   $rules[] = "- HERO_H1: 6–12 palabras, humano, distinto a SEO_TITLE.";
    }
    $rulesText = implode("\n", array_values(array_unique($rules)));

    $prompt = <<<PROMPT
Devuelve SOLO JSON válido (sin markdown). RESPUESTA MINIFICADA.
Idioma: ES.
VARIATION (NO imprimir): {$variation}

Rol:
Redactor SEO experto en conversión. Estilo landing (beneficios, confianza, pasos, CTA). Multi-industria.

Keyword: {$this->keyword}
Tipo: {$this->tipo}

BRIEF:
- Ángulo: {$briefAngle}
- Tono: {$briefTone}
- Público: {$briefAud}
- CTA: {$briefCTA}

PLAN (referencia):
{$planText}

NO REPETIR (HEADINGS):
{$noRepetirHeadings}

NO REPETIR TÍTULOS:
{$noRepetirTitles}

NO REPETIR TEXTOS:
{$noRepetirCorpus}

Títulos prohibidos (no repetir):
{$banTitles}

PALABRAS CLAVE:
- Usa keyword principal + 2–5 variantes (LSI/long-tail).
- Si la keyword trae ciudad, úsala. Si no, no inventes.

REGLAS:
- ❌ No headings “Introducción/Conclusión/¿Qué es...?”
- ❌ No genéricos (“Contenido útil”, etc.)
- ✅ Beneficios concretos, sin inventar cifras.
- ✅ CTA natural.

Reglas por token:
{$rulesText}

Valores actuales (para NO copiar):
{$currentJson}

ESQUEMA:
{$schema}
PROMPT;

    $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 650, temperature: 0.95, topP: 0.9, jsonMode: true);

    $arr = [];
    try { $arr = $this->parseJsonStrict($raw); }
    catch (\Throwable $e) { $arr = $this->parseJsonLoosePairs($raw); }

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
    string $noRepetirCorpus,
    string $noRepetirHeadings
): array {
    $hasSeo = isset($tokensMeta['SEO_TITLE']);
    $hasH1  = isset($tokensMeta['HERO_H1']);
    if (!$hasSeo && !$hasH1) return $values;

    // base actual
    $seoBase = trim(strip_tags($this->toStr($values['SEO_TITLE'] ?? '')));
    $h1Base  = trim(strip_tags($this->toStr($values['HERO_H1'] ?? '')));

    $seoBase = $this->sanitizeTitle($seoBase, 'SEO_TITLE');
    $h1Base  = $this->sanitizeTitle($h1Base, 'HERO_H1');

    // usados globales + locales (para no repetir en este contenido)
    $globalUsed = array_values(array_unique(array_filter(array_map(function ($t) {
        return mb_strtolower(trim(strip_tags((string)$t)));
    }, $usedTitles))));

    $localUsed = [];
    if ($seoBase !== '') $localUsed[] = mb_strtolower($seoBase);
    if ($h1Base  !== '') $localUsed[] = mb_strtolower($h1Base);
    $localUsed = array_values(array_unique(array_filter($localUsed)));

    // helper: valida si un título "sirve"
    $isBad = function(string $t) use ($globalUsed, $localUsed): bool {
        $t = trim(strip_tags($t));
        if ($t === '') return true;

        if ($this->isBadTitlePattern($t)) return true;

        // repetido contra históricos
        foreach ($globalUsed as $u) {
            if ($u === '') continue;
            if (mb_strtolower($t) === $u) return true;
            if ($this->jaccardBigrams(mb_strtolower($t), $u) >= 0.62) return true;
        }

        // repetido dentro del mismo contenido
        $lc = mb_strtolower($t);
        if (in_array($lc, $localUsed, true)) return true;

        // muy corto
        if (mb_strlen($t) < 20) return true;

        return false;
    };

    // helper: 2 intentos IA y si falla => fallback
    $genWithIA = function(
        string $field,
        string $base,
        int $seedLocal
    ) use (
        $apiKey, $model, $brief, $themePlan,
        $noRepetirTitles, $noRepetirCorpus, $noRepetirHeadings,
        $usedTitles, $isBad, &$localUsed
    ): string {
        // intento 1
        $t1 = $this->rewriteTitleViaIA(
            $apiKey, $model, $base, $usedTitles, $brief, $seedLocal,
            $themePlan, $noRepetirTitles, $noRepetirCorpus, $noRepetirHeadings, $field
        );
        $t1 = $this->sanitizeTitle($t1, $field);

        if (!$isBad($t1)) {
            $localUsed[] = mb_strtolower($t1);
            $localUsed = array_values(array_unique($localUsed));
            return $t1;
        }

        // intento 2 (cambia seed => fuerza variedad)
        $t2 = $this->rewriteTitleViaIA(
            $apiKey, $model, $t1, array_merge($usedTitles, [$t1]), $brief, $seedLocal + 777,
            $themePlan, $noRepetirTitles, $noRepetirCorpus, $noRepetirHeadings, $field
        );
        $t2 = $this->sanitizeTitle($t2, $field);

        if (!$isBad($t2)) {
            $localUsed[] = mb_strtolower($t2);
            $localUsed = array_values(array_unique($localUsed));
            return $t2;
        }

        // fallback final (seed alterado para variar)
        $fb = $this->fallbackSpecificTitle($seedLocal + 1313, $themePlan, $field === 'SEO_TITLE');
        $fb = $this->sanitizeTitle($fb, $field);

        // garantiza que no sea duplicado local: si lo es, cambia seed otra vez
        if ($isBad($fb)) {
            $fb = $this->fallbackSpecificTitle($seedLocal + 2222, $themePlan, $field === 'SEO_TITLE');
            $fb = $this->sanitizeTitle($fb, $field);
        }

        $localUsed[] = mb_strtolower($fb);
        $localUsed = array_values(array_unique($localUsed));
        return $fb;
    };

    // ✅ IA-first SIEMPRE
    if ($hasSeo) {
        $seo = $genWithIA('SEO_TITLE', $seoBase, $seed);
        $values['SEO_TITLE'] = trim($seo);
    }

    if ($hasH1) {
        // si existe SEO, úsalo como "bloqueo" (no parecido)
        $h1Seed = $seed + 9;
        $h1 = $genWithIA('HERO_H1', $h1Base, $h1Seed);

        // evita similitud con SEO
        if ($hasSeo) {
            $seoNow = trim(strip_tags($this->toStr($values['SEO_TITLE'] ?? '')));
            $a = mb_strtolower($seoNow);
            $b = mb_strtolower($h1);
            if ($a === $b || $this->jaccardBigrams($a, $b) >= 0.70) {
                $h1 = $genWithIA('HERO_H1', $h1, $seed + 2021);
            }
        }

        $values['HERO_H1'] = trim($h1);
    }

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
        string $noRepetirHeadings,
        string $field
    ): string {
        $variation = "seed={$seed}|title_rewrite=1|field={$field}|job=" . substr($this->jobUuid, 0, 8) . "|rid=" . (int)$this->registroId;

        $briefAngle = $this->toStr($brief['angle'] ?? '');
        $briefTone  = $this->toStr($brief['tone'] ?? '');
        $schema = '{"title":""}';
        $ban = implode(' | ', array_slice(array_filter($usedTitles), 0, 20));

        $prompt = <<<PROMPT
    Devuelve SOLO JSON válido. RESPUESTA MINIFICADA.
    Idioma: ES.
    VARIATION (NO imprimir): {$variation}

    Rol:
    Redactor SEO Experto en conversión. Estilo LANDING (beneficio claro + confianza + CTA suave).
    Multi-industria: NO asumas un sector fijo.

    Keyword: {$this->keyword}
    Campo: {$field}

    BRIEF:
    - Ángulo: {$briefAngle}
    - Tono: {$briefTone}

    NO REPETIR (HEADINGS):
    {$noRepetirHeadings}

    NO REPETIR (TITULOS):
    {$noRepetirTitles}

    Títulos prohibidos:
    {$ban}

    PROHIBIDO (no uses estas estructuras):
    - "Contenido para {keyword} con enfoque en ..."
    - "Estrategia y copy para {keyword} sin relleno"
    - "con enfoque en ..."
    - "sin relleno"
    - títulos genéricos o tipo plantilla.
    -  Palabras tipo "garantizado/garantizada" o promesas absolutas.
    -  Frases repetitivas: "sin sorpresas", "proceso claro" (no las uses).
    -  Usar el separador "|" (usa ":" o nada).
    

    FORMATO RECOMENDADO:
    - Debe empezar por la keyword o contenerla claramente.
    - Añade 1 beneficio o promesa concreta + (opcional) CTA suave.
    Ejemplos de estilo (NO copies literal):
    "{keyword}: Precio claro y reserva rápida"
    "{keyword}: Atención inmediata y proceso simple"
    "{keyword}: Servicio premium con tarifas claras"

    REGLAS:
    - SEO_TITLE: <= 60 caracteres aprox.
    - HERO_H1: 6–12 palabras, humano, distinto al SEO_TITLE.
    - No inventes ciudad si no está en la keyword.

    Actual (para mejorar, no copiar):
    {$current}

    ESQUEMA:
    {$schema}
    PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 260, temperature: 0.95, topP: 0.9, jsonMode: true);

        $arr = [];
        try { $arr = $this->parseJsonStrict($raw); }
        catch (\Throwable $e) { $arr = $this->parseJsonLoosePairs($raw); }

        $new = trim(strip_tags($this->toStr($arr['title'] ?? '')));

        // ✅ blindaje: si IA vuelve a tirar plantilla, caemos a fallback bueno
        if ($new === '' || $this->isBadTitlePattern($new) || $this->isTitleTooSimilar($new, $usedTitles)) {
            return $this->fallbackSpecificTitle($seed + 31, $themePlan, $field === 'SEO_TITLE');
        }

        // recorte SEO
        if ($field === 'SEO_TITLE') {
             $new = $this->smartTruncateTitle($new, 60);
        }

        return $new;
    }




    private function fallbackSpecificTitle(int $seed, array $themePlan, bool $seoStyle): string
    {
        $kw = $this->shortKw();

        // Variantes “landing” multi-industria (NO solo precio/reserva)
        $seoOpts = [
            "{$kw}: Reserva rápida y atención directa",
            "{$kw}: Disponibilidad clara y respuesta ágil",
            "{$kw}: Servicio profesional, proceso simple",
            "{$kw}: Opciones a medida y gestión rápida",
            "{$kw}: Atención personalizada y sin esperas",
            "{$kw}: Calidad, comodidad y trato cercano",
            "{$kw}: Planificación fácil y confirmación rápida",
            "{$kw}: Solución inmediata con atención humana",
            "{$kw}: Proceso fácil y comunicación clara",
            "{$kw}: Elige tu opción y confirma en minutos",
            "{$kw}: Gestión rápida para clientes exigentes",
            "{$kw}: Servicio fiable con pasos claros",
        ];

        $h1Opts = [
            "{$kw} con reserva rápida y trato profesional",
            "Confirma {$kw} en minutos, sin complicaciones",
            "{$kw} con atención directa y proceso simple",
            "Tu {$kw} con respuesta rápida y confianza",
            "{$kw} pensado para comodidad y tranquilidad",
            "Solicita {$kw} con opciones claras y fáciles",
            "{$kw} con gestión rápida y atención humana",
            "Elige {$kw} y avanza con un paso claro",
            "{$kw} con servicio profesional y trato cercano",
        ];

        $opts = $seoStyle ? $seoOpts : $h1Opts;

        // Salt único por keyword + registro + seed (evita repetición entre items)
        $salt = "{$seed}|rid=".(int)$this->registroId."|kw=".$kw."|seo=".($seoStyle?'1':'0');

        $pick = $this->pickVariant($opts, $salt);

        // Límite SEO con recorte inteligente
        if ($seoStyle) $pick = $this->smartTruncateTitle($pick, 60);

        return $pick;
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
        "Precio claro y sin sorpresas (transparencia)",
        "Resultados / beneficios visibles (valor tangible)",
        "Rapidez y ejecución (sin fricción)",
        "Atención profesional y cercana (confianza)",
        "Servicio premium (calidad + detalle)",
        "Solución a un problema urgente (alivio + seguridad)",
        "Optimización / eficiencia (ahorro de tiempo/dinero)",
        "Experiencia guiada (te lo ponemos fácil)",
    ];

    $tones = [
        "Profesional directo",
        "Cercano y humano",
        "Premium sobrio",
        "Urgencia elegante (sin agresividad)",
        "Claro y resolutivo",
    ];

    $ctas  = [
        "Reserva ahora",
        "Solicitar precio",
        "Pedir disponibilidad",
        "Agendar",
        "Hablar con un asesor",
        "Recibir propuesta",
    ];

    $audiences = [
        "Personas que comparan opciones",
        "Clientes con prisa",
        "Clientes exigentes",
        "Pymes",
        "Familias",
        "Viajeros",
        "Negocios locales",
        "Empresas",
    ];

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


   private function extractHeadingsFromDraftJson(string $draftJson): array
    {
        $draftJson = trim((string)$draftJson);
        if ($draftJson === '') return [];

        $arr = json_decode($draftJson, true);
        if (!is_array($arr)) return [];

        $out = [];
        foreach ($arr as $k => $v) {
            $k = (string)$k;

            if (!preg_match('~(SEO_TITLE|HERO_H1|KIT_H1|PRICE_H2|TESTIMONIOS_TITLE|CLIENTS_LABEL|FAQ_TITLE|FAQ_\d+_Q|SECTION_\d+_TITLE)$~', $k)) {
                continue;
            }

            $t = trim(strip_tags($this->toStr($v)));
            if ($t === '') continue;

            $lc = mb_strtolower($t);
            if (str_contains($lc, 'introducción') || str_contains($lc, 'conclusión') || str_contains($lc, '¿qué es') || str_contains($lc, 'que es')) {
                continue;
            }

            $out[] = $t;
        }

        $out = array_values(array_unique($out));
        return array_slice($out, 0, 140);
    }

    private function isBadTitlePattern(string $title): bool
    {
        $t = mb_strtolower(trim(strip_tags($title)));

        if ($t === '') return true;

        $bannedContains = [
            'contenido para ',
            'estrategia y copy',
            'con enfoque en',
            'sin relleno',
            'beneficio principal',
            'resultado tangible',
            'tema guía',
            'plan de temas',
        ];

        foreach ($bannedContains as $needle) {
            if (str_contains($t, $needle)) return true;
        }

        // demasiado "plantilla"
        if (preg_match('~^contenido\s+para\s+.+\s+con\s+enfoque~iu', $title)) return true;

        return false;
    }

private function sanitizeTitle(string $title, string $field = 'SEO_TITLE'): string
{
    $t = trim(preg_replace('~\s+~u', ' ', strip_tags($title)));

    // Quita claims absolutos (evita problemas legales/SEO)
    $bannedWords = [
        'garantizada', 'garantizado', '100%', 'siempre', 'nunca',
    ];
    foreach ($bannedWords as $w) {
        $t = preg_replace('~\b' . preg_quote($w, '~') . '\b~iu', '', $t);
    }
    $t = trim(preg_replace('~\s+~u', ' ', $t));

    // (Opcional) si ya te cansaste de “sin sorpresas / proceso claro”
    $repetitivos = [
        'sin sorpresas', 'proceso claro', 'proceso transparente', 'precio claro'
    ];
    foreach ($repetitivos as $p) {
        $t = preg_replace('~\b' . preg_quote($p, '~') . '\b~iu', '', $t);
    }
    $t = trim(preg_replace('~\s+~u', ' ', $t));

    // Normaliza separadores: prefiere ":" en vez de "|"
    $t = str_replace([' | ', '|'], ': ', $t);
    $t = trim(preg_replace('~\s*:\s*~u', ': ', $t));

    // Capitaliza primera letra (sin intentar Title Case agresivo)
    if ($t !== '') {
        $first = mb_substr($t, 0, 1);
        $rest  = mb_substr($t, 1);
        $t = mb_strtoupper($first) . $rest;
    }

    // Limpieza de signos duplicados
    $t = trim($t, " -|,.:; ");

    // Limita SEO_TITLE a 60–62 chars (elige tu límite)
    if ($field === 'SEO_TITLE') {
    $t = $this->smartTruncateTitle($t, 60);
    }
    // Si quedó muy corto o termina raro, fuerza fallback
    $lc = mb_strtolower($t);
    if ($t === '' || mb_strlen($t) < 25 || preg_match('~[:\-]\s*$~u', $t) || preg_match('~\b(y|con|para|de|en)\s*$~iu', $t)) {
    $kw = $this->shortKw();

    $opts = [
        "{$kw}: Reserva rápida y atención discreta",
        "{$kw}: Experiencia exclusiva y trato profesional",
        "{$kw}: Sensualidad y relajación en un solo paso",
        "{$kw}: Servicio a domicilio con total privacidad",
        "{$kw}: Opciones claras y atención inmediata",
        "{$kw}: Discreción, confort y experiencia premium",
        "{$kw}: Elige tu sesión y confirma en minutos",
        "{$kw}: Una experiencia sensorial sin complicaciones",
    ];

    $salt = "sanitize|rid=".(int)$this->registroId."|kw=".$kw."|raw=".$t;
    $t = $this->pickVariant($opts, $salt);

    if ($field === 'SEO_TITLE') $t = $this->smartTruncateTitle($t, 60);
}



    // Si quedó raro/vacío, cae a algo seguro
    if ($t === '' || $this->isBadTitlePattern($t)) {
        // Nota: aquí no tenemos seed/themePlan. Se usa algo simple.
        $kw = $this->shortKw();
        $t = ($field === 'SEO_TITLE')
            ? mb_substr("{$kw}: Precio fijo. Reserva ya", 0, 60)
            : "{$kw} con precio fijo y reserva rápida";
    }

    return trim(preg_replace('~\s+~u', ' ', $t));
}

    private function smartTruncateTitle(string $s, int $limit = 60): string
    {
        $s = trim(preg_replace('~\s+~u', ' ', (string)$s));
        if ($s === '') return '';

        if (mb_strlen($s) <= $limit) return $s;

        $cut = mb_substr($s, 0, $limit);

        // intenta cortar en el último separador "seguro"
        $seps = ['. ', ': ', ' - ', ' — ', ', ', ' '];
        $best = '';

        foreach ($seps as $sep) {
            $pos = mb_strrpos($cut, $sep);
            if ($pos !== false && $pos >= 18) { // evita cortar demasiado corto
                $best = rtrim(mb_substr($cut, 0, $pos), " -|,.:; ");
                break;
            }
        }

        if ($best === '') {
            $best = rtrim($cut, " -|,.:; ");
        }

        // evita finales malos tipo "con", "y", "para", "de"
        $badEnds = ['con','y','para','de','del','la','el','en','a','al','que'];
        $words = preg_split('~\s+~u', $best, -1, PREG_SPLIT_NO_EMPTY);
        while (count($words) > 3) {
            $last = mb_strtolower(end($words));
            if (!in_array($last, $badEnds, true)) break;
            array_pop($words);
            $best = implode(' ', $words);
        }

        return trim($best);
    }


    private function pickVariant(array $opts, string $salt): string
    {
        $i = $this->stableSeedInt($salt) % max(1, count($opts));
        return (string)($opts[$i] ?? $opts[0]);
    }

}
