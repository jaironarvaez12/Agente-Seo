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

    public $timeout = 4200;
    public $tries   = 5;
    public $backoff = [60, 120, 300, 600, 900];

    // ✅ Idempotencia: mismo input => mismo job_uuid
    public string $jobUuid;

    // Opcional (no confíes en esto para reintentos del queue driver database)
    public ?int $registroId = null;

    private array $briefContext = [];

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {
        // ✅ determinístico (si se dispara 2 veces el mismo trabajo, cae en el mismo registro)
        $base = (int)$this->idDominioContenido . '|' . (int)$this->idDominio . '|' . trim((string)$this->tipo) . '|' . mb_strtolower(trim((string)$this->keyword));
        $this->jobUuid = sha1($base);
    }

    public function handle(): void
    {
        // ===========================================================
        // ✅ 1) Obtener/crear 1 SOLO registro (idempotente)
        // ===========================================================
        $registro = Dominios_Contenido_DetallesModel::where('job_uuid', $this->jobUuid)->first();

        if (!$registro) {
            try {
                $registro = Dominios_Contenido_DetallesModel::create([
                    'job_uuid' => $this->jobUuid,
                    'id_dominio_contenido' => (int)$this->idDominioContenido,
                    'id_dominio' => (int)$this->idDominio,
                    'tipo' => $this->tipo,
                    'keyword' => $this->keyword,
                    'estatus' => 'en_proceso',
                    'modelo' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
                ]);
            } catch (\Throwable $e) {
                // Si otro worker lo creó al mismo tiempo por el unique index, lo buscamos.
                $registro = Dominios_Contenido_DetallesModel::where('job_uuid', $this->jobUuid)->first();
                if (!$registro) throw $e;
            }
        } else {
            $registro->update([
                'estatus' => 'en_proceso',
                'modelo' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            ]);
        }

        $this->registroId = (int)$registro->id_dominio_contenido_detalle;

        try {
            $apiKey = (string) env('DEEPSEEK_API_KEY', '');
            $model  = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');

            if ($apiKey === '') {
                throw new \RuntimeException('DEEPSEEK_API_KEY no configurado');
            }

            // ===========================================================
            // Historial para NO repetir
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
            // Generación con 3 intentos internos
            // ===========================================================
            $final = null;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $brief = $this->creativeBrief($this->keyword);
                $this->briefContext = $brief;

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
                    maxTokens: 3200,
                    temperature: 0.92,
                    topP: 0.90,
                    jsonMode: true
                );

                $draftArr = $this->safeParseOrRepair($apiKey, $model, $draftRaw, $brief);
                $draftArr = $this->validateOrRepairCopy($apiKey, $model, $draftArr, $brief, 'redactor', $noRepetirTitles, $noRepetirCorpus);

                // 2) AUDITOR
                $auditPrompt = $this->promptAuditorJson(
                    $this->tipo,
                    $this->keyword,
                    $draftArr,
                    $noRepetirTitles,
                    $noRepetirCorpus,
                    $brief
                );

                $auditedRaw = $this->deepseekText(
                    $apiKey, $model, $auditPrompt,
                    maxTokens: 3400,
                    temperature: 0.85,
                    topP: 0.90,
                    jsonMode: true
                );

                $candidateArr = $this->safeParseOrRepair($apiKey, $model, $auditedRaw, $brief);
                $candidateArr = $this->validateOrRepairCopy($apiKey, $model, $candidateArr, $brief, 'auditor', $noRepetirTitles, $noRepetirCorpus);

                // 3) REPAIR si viola reglas o es muy similar
                if ($this->violatesSeoHardRules($candidateArr) || $this->isTooSimilarToAnyPrevious($candidateArr, $usedTitles, $usedCorpus)) {
                    $repairPrompt = $this->promptRepairJson(
                        $this->keyword,
                        $candidateArr,
                        $noRepetirTitles,
                        $noRepetirCorpus,
                        $brief
                    );

                    $repairRaw = $this->deepseekText(
                        $apiKey, $model, $repairPrompt,
                        maxTokens: 3400,
                        temperature: 0.25,
                        topP: 0.90,
                        jsonMode: true
                    );

                    $candidateArr = $this->safeParseOrRepair($apiKey, $model, $repairRaw, $brief);
                    $candidateArr = $this->validateOrRepairCopy($apiKey, $model, $candidateArr, $brief, 'repair', $noRepetirTitles, $noRepetirCorpus);
                }

                $final = $candidateArr;

                if (
                    !$this->isTooSimilarToAnyPrevious($candidateArr, $usedTitles, $usedCorpus) &&
                    !$this->violatesSeoHardRules($candidateArr)
                ) {
                    break;
                }

                // alimentar historial para el siguiente intento
                $usedTitles[] = $this->toStr($candidateArr['seo_title'] ?? $candidateArr['hero_h1'] ?? '');
                $usedCorpus[] = $this->copyTextFromArray($candidateArr);
                $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 12));
                $noRepetirCorpus = $this->compactHistory($usedCorpus, 2500);
            }

            if (!is_array($final)) {
                throw new \RuntimeException('No se pudo generar contenido final');
            }

            // ===========================================================
            // Cargar template por dominio
            // ===========================================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);

            // ===========================================================
            // Reemplazo por TOKENS
            // ===========================================================
            [$filled, $replacedCount, $remaining] = $this->fillElementorTemplate_byPrettyTokens_withStats($tpl, $final);

            if ($replacedCount < 8) {
                throw new \RuntimeException("Template no parece tokenizado (replacedCount={$replacedCount}). Template: {$tplPath}");
            }

            if (count($remaining) > 0) {
                throw new \RuntimeException("Quedaron tokens sin reemplazar: " . implode(' | ', array_slice($remaining, 0, 50)));
            }

            // Post-pass usando copy (sin textos fijos)
            [$filled, $forcedCount] = $this->forceReplaceStaticTextsInTemplate($filled, $final);

            // ===========================================================
            // Title + slug
            // ===========================================================
            $title = trim(strip_tags($this->toStr($final['seo_title'] ?? $final['hero_h1'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // Guardar en BD
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
            $isLast = ($this->attempts() >= (int)$this->tries);

            $registro->update([
                'estatus' => $isLast ? 'error_final' : 'error',
                'error' => $e->getMessage() . ' | attempts=' . $this->attempts(),
            ]);

            throw $e;
        }
    }

    // ===========================================================
    // ✅ Producción: si IA deja vacío, NO fallar -> fallback dinámico
    // ===========================================================
    private function validateOrRepairCopy(
        string $apiKey,
        string $model,
        array $copy,
        array $brief,
        string $stage,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): array {
        $this->briefContext = $brief;

        $copy = $this->sanitizeAndNormalizeCopy($copy);
        $this->applyDynamicFallbacks($copy, force: false);

        try {
            return $this->validateAndFixCopy($copy);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();

            if (str_contains($msg, 'Campo vacío generado:') || str_contains($msg, 'HTML vacío generado:')) {
                $this->applyDynamicFallbacks($copy, force: true);
                return $this->validateAndFixCopy($copy);
            }

            $repairRaw = $this->repairMissingFieldsViaDeepseek(
                $apiKey, $model, $copy, $brief, $stage, $msg, $noRepetirTitles, $noRepetirCorpus
            );

            $repaired = $this->safeParseOrRepair($apiKey, $model, $repairRaw, $brief);
            $repaired = $this->sanitizeAndNormalizeCopy($repaired);
            $this->applyDynamicFallbacks($repaired, force: true);

            try {
                return $this->validateAndFixCopy($repaired);
            } catch (\Throwable $e2) {
                $this->applyDynamicFallbacks($repaired, force: true, hard: true);
                return $this->validateAndFixCopy($repaired);
            }
        }
    }

    // ===========================================================
    // ✅ Fallbacks GENÉRICOS (sirven para cualquier plantilla)
    // - Sin “Qué aporta”
    // ===========================================================
    private function applyDynamicFallbacks(array &$copy, bool $force = false, bool $hard = false): void
    {
        $kw = $this->shortKw();
        $tone  = $this->toStr($this->briefContext['tone'] ?? '');
        $cta   = $this->toStr($this->briefContext['cta'] ?? '');
        $aud   = $this->toStr($this->briefContext['audience'] ?? '');

        $needText = function(string $k) use (&$copy): bool {
            return trim(strip_tags($this->toStr($copy[$k] ?? ''))) === '';
        };

        $needHtml = function(string $k) use (&$copy): bool {
            $h = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($copy[$k] ?? '')));
            return ($h === '' || $this->isBlankHtml($h) || preg_match('~<p>\s*</p>~i', $h));
        };

        // HERO / PACK / KIT
        if ($needText('hero_kicker')) $copy['hero_kicker'] = $this->pick(["Sitio listo para publicar","Diseño claro","Estructura sólida","Mensaje directo"]);
        if ($needText('hero_h1')) $copy['hero_h1'] = "{$kw} con estructura y mensaje claro";
        if ($needHtml('hero_p_html')) {
            $copy['hero_p_html'] = "<p>Contenido preparado para {$kw}: claro, escaneable y pensado para guiar a la acción. {$tone}" . ($aud !== '' ? " Enfocado en {$aud}." : '') . "</p>";
        }

        if ($needText('kit_h1')) $copy['kit_h1'] = "Bloques listos para {$kw}";
        if ($needHtml('kit_p_html')) $copy['kit_p_html'] = "<p>Secciones coherentes y textos listos para adaptar y publicar en {$kw}, sin huecos ni relleno.</p>";

        if ($needText('pack_h2')) $copy['pack_h2'] = "Estructura y copy para {$kw}";
        if ($needHtml('pack_p_html')) $copy['pack_p_html'] = "<p>Copy y secciones alineadas a intención, con CTA consistente y mensajes que se entienden rápido. {$cta}</p>";

        if ($needText('price_h2')) $copy['price_h2'] = $this->pick(["Plan claro y entregables definidos","Entrega lista para publicar","Implementación rápida y ordenada"]);

        // CLIENTS
        if ($needText('clients_label')) $copy['clients_label'] = $this->pick(["Marcas","Equipos","Negocios","Proyectos"]);
        if ($needText('clients_subtitle')) $copy['clients_subtitle'] = $this->pick(["Claridad, orden y enfoque","Mensaje directo y estructura","Contenido útil y coherente","Secciones con propósito"]);
        else {
            $s = trim(strip_tags($this->toStr($copy['clients_subtitle'])));
            $words = preg_split('~\s+~u', $s, -1, PREG_SPLIT_NO_EMPTY);
            if ($words && count($words) > 12) $copy['clients_subtitle'] = implode(' ', array_slice($words, 0, 12));
        }
        if ($needHtml('clients_p_html')) {
            $copy['clients_p_html'] = "<p>Ideal para {$copy['clients_label']} que necesitan una web coherente: propuesta clara, secciones con intención y textos listos para publicar sin improvisar.</p>";
        }

        if ($needText('reviews_label')) $copy['reviews_label'] = $this->pick(["Reseñas","Opiniones","Resultados","Valoraciones"]);
        if ($needText('testimonios_title')) $copy['testimonios_title'] = $this->pick(["Lo que suelen valorar","Qué suele funcionar","Puntos fuertes del enfoque"]);
        if ($needText('projects_title')) $copy['projects_title'] = $this->pick(["Ejemplos de estructura","Ideas de implementación","Bloques listos para adaptar"]);

        // CTA / botones
        if ($needText('final_cta_h3')) $copy['final_cta_h3'] = "¿Quieres publicarlo y avanzar?";
        if ($needText('btn_presupuesto')) $copy['btn_presupuesto'] = $this->pick(["Pedir propuesta","Solicitar presupuesto","Ver opciones"]);
        if ($needText('btn_reunion')) $copy['btn_reunion'] = $this->pick(["Agendar llamada","Reservar llamada","Hablar ahora"]);

        // Kit Digital (genérico)
        if ($needText('kitdigital_bold')) $copy['kitdigital_bold'] = $this->pick(["Información de ayudas","Opciones disponibles","Kit Digital"]);
        if ($needHtml('kitdigital_p_html')) $copy['kitdigital_p_html'] = "<p>Si aplica, te guiamos en el proceso y dejamos {$kw} listo para publicar con pasos claros y entregables definidos.</p>";
        if ($needText('btn_kitdigital')) $copy['btn_kitdigital'] = $this->pick(["Ver información","Consultar","Empezar"]);

        // SEO title
        if ($needText('seo_title')) $copy['seo_title'] = "Contenido para {$kw} listo para publicar y adaptar";
        $seo = trim(strip_tags($this->toStr($copy['seo_title'])));
        if (mb_strlen($seo) > 65) $seo = rtrim(mb_substr($seo, 0, 65), " \t\n\r\0\x0B-–—|:");
        $copy['seo_title'] = $seo;

        // Estructuras
        $copy['features'] = (isset($copy['features']) && is_array($copy['features'])) ? $copy['features'] : [];
        $copy['faq']      = (isset($copy['faq']) && is_array($copy['faq'])) ? $copy['faq'] : [];

        // features: asegurar 4 (GENÉRICAS)
        if ($hard || count($copy['features']) !== 4) {
            $copy['features'] = [
                ['title' => "Mensaje claro", 'p_html' => "<p>Texto directo, sin ruido, fácil de escanear y adaptar.</p>"],
                ['title' => "Estructura con intención", 'p_html' => "<p>Secciones ordenadas para guiar lectura y decisión.</p>"],
                ['title' => "SEO natural", 'p_html' => "<p>Semántica integrada sin repetir palabras de forma artificial.</p>"],
                ['title' => "Listo para publicar", 'p_html' => "<p>Bloques y textos listos para WordPress/Elementor.</p>"],
            ];
        } else {
            for ($i=0; $i<4; $i++) {
                if (!isset($copy['features'][$i]) || !is_array($copy['features'][$i])) $copy['features'][$i] = [];
                if (trim(strip_tags($this->toStr($copy['features'][$i]['title'] ?? ''))) === '') {
                    $copy['features'][$i]['title'] = $this->pick(["Bloque clave","Mejora","Ventaja","Punto fuerte"]);
                }
                $p = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($copy['features'][$i]['p_html'] ?? '')));
                if ($p === '' || $this->isBlankHtml($p)) {
                    $copy['features'][$i]['p_html'] = "<p>Contenido breve y reutilizable para múltiples diseños y secciones.</p>";
                }
            }
        }

        // faq: asegurar 9
        if ($hard || count($copy['faq']) !== 9) {
            $copy['faq'] = [];
            $qTpl = [
                "¿Qué incluye este contenido?",
                "¿Cuánto tarda en estar listo?",
                "¿Qué necesito aportar para empezar?",
                "¿Se puede adaptar a mi sector?",
                "¿Cómo evitamos duplicados?",
                "¿Puedo publicarlo yo mismo?",
                "¿Qué diferencia esto de algo genérico?",
                "¿Hay ajustes después de la entrega?",
                "¿Qué no incluye para evitar expectativas falsas?",
            ];
            $aTpl = [
                "<p>Incluye estructura, textos y bloques listos para publicar y ajustar.</p>",
                "<p>Depende del alcance, pero se avanza rápido con un brief claro.</p>",
                "<p>Oferta, público y 2–3 referencias. Si falta claridad, se define contigo.</p>",
                "<p>Sí, se ajusta el enfoque y el lenguaje sin forzar términos.</p>",
                "<p>Se varía estructura y redacción y se compara contra historial reciente.</p>",
                "<p>Sí. Queda en formato simple para Elementor/WordPress.</p>",
                "<p>Está pensado para intención y claridad, no para rellenar secciones.</p>",
                "<p>Se contempla una ronda razonable de ajustes para coherencia.</p>",
                "<p>No promete resultados irreales: se define alcance y entregables.</p>",
            ];
            for ($i=0; $i<9; $i++) $copy['faq'][] = ['q' => $qTpl[$i], 'a_html' => $aTpl[$i]];
        } else {
            for ($i=0; $i<9; $i++) {
                if (!isset($copy['faq'][$i]) || !is_array($copy['faq'][$i])) $copy['faq'][$i] = [];
                if (trim(strip_tags($this->toStr($copy['faq'][$i]['q'] ?? ''))) === '') $copy['faq'][$i]['q'] = "Pregunta frecuente";
                $a = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($copy['faq'][$i]['a_html'] ?? '')));
                if ($a === '' || $this->isBlankHtml($a)) $copy['faq'][$i]['a_html'] = "<p>Respuesta breve, clara y reutilizable para distintos diseños.</p>";
            }
        }

        if ($needText('faq_title')) $copy['faq_title'] = "Preguntas frecuentes";
    }

    // ===========================================================
    // TEMPLATE LOADER
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) throw new \RuntimeException("Dominio no encontrado (id={$idDominio})");

        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));
        if ($templateRel === '') $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_PATH', ''));
        if ($templateRel === '') throw new \RuntimeException('No hay plantilla configurada (dominio ni env ELEMENTOR_TEMPLATE_PATH).');

        $templateRel = str_replace(['https:', 'http:'], '', $templateRel);

        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) throw new \RuntimeException('Template path inválido (no se permite "..")');

        $templatePath = storage_path('app/' . $templateRel);
        if (!is_file($templatePath)) throw new \RuntimeException("No existe el template en disco: {$templatePath} (path={$templateRel})");

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

        if (!$resp->successful()) throw new \RuntimeException("DeepSeek error {$resp->status()}: {$resp->body()}");

        $data = $resp->json();
        $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') throw new \RuntimeException("DeepSeek returned empty text.");

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
            'hero_kicker' => '',
            'hero_h1' => '',
            'hero_p_html' => '<p></p>',

            'kit_h1' => '',
            'kit_p_html' => '<p></p>',

            'pack_h2' => '',
            'pack_p_html' => '<p></p>',
            'price_h2' => '',

            'features' => [],

            'clients_label' => '',
            'clients_subtitle' => '',
            'clients_p_html' => '<p></p>',

            'reviews_label' => '',
            'testimonios_title' => '',
            'projects_title' => '',

            'faq_title' => '',
            'faq' => [],

            'final_cta_h3' => '',

            'btn_presupuesto' => '',
            'btn_reunion' => '',

            'kitdigital_bold' => '',
            'kitdigital_p_html' => '<p></p>',
            'btn_kitdigital' => '',
        ];

        $out = $skeleton;
        foreach ($partial as $k => $v) $out[$k] = $v;
        return $out;
    }

    private function repairJsonViaDeepseek(string $apiKey, string $model, string $broken, array $brief): string
    {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $broken = mb_substr(trim((string)$broken), 0, 9000);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. Sin texto fuera del JSON.
RESPUESTA MINIFICADA (sin saltos de línea).

Completa este JSON y devuélvelo VÁLIDO con estas keys obligatorias:
seo_title, hero_kicker, hero_h1, hero_p_html,
kit_h1, kit_p_html, pack_h2, pack_p_html, price_h2,
features (4),
clients_label, clients_subtitle, clients_p_html,
reviews_label, testimonios_title, projects_title,
faq_title, faq (9),
final_cta_h3, btn_presupuesto, btn_reunion,
kitdigital_bold, kitdigital_p_html, btn_kitdigital

Reglas:
- HTML permitido SOLO: <p>, <strong>, <br>
- SOLO 1 H1: hero_h1 (no uses <h1>)
- 4 features exactas y 9 FAQs exactas
- clients_subtitle MUY CORTO (6–12 palabras)
- NO vacíos ni "<p></p>"

Estilo:
- Ángulo: {$angle}
- Tono: {$tone}

JSON roto:
{$broken}
PROMPT;

        return $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3400, temperature: 0.18, topP: 0.90, jsonMode: true);
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
            'seo_title','hero_kicker','hero_h1','hero_p_html',
            'kit_h1','kit_p_html',
            'pack_h2','pack_p_html','price_h2',
            'clients_label','clients_subtitle','clients_p_html',
            'reviews_label','testimonios_title','projects_title',
            'faq_title','final_cta_h3',
            'btn_presupuesto','btn_reunion',
            'kitdigital_bold','kitdigital_p_html','btn_kitdigital'
        ] as $key) {
            $v = $this->extractJsonValueLoose($raw, $key);
            if ($v !== null) $out[$key] = $v;
        }

        $featuresBlock = $this->extractBetween($raw, '"features"', '"clients_label"');
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
        if ($p2 === false) return mb_substr($raw, $p1, 6500);
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
            "Calidad consistente (tono de marca, precisión)",
            "Orientado a leads (CTA, objeciones, conversión)",
            "Personalización (sector/ciudad/propuesta)",
            "Proceso y metodología (pasos, validación, control)",
            "Diferenciación (propuesta y posicionamiento)",
            "SEO natural (semántica, intención, sin stuffing)",
            "Claridad del mensaje (menos ruido, más foco)",
            "Escalabilidad (reutilizable, fácil de publicar)",
            "Transparencia (sin claims falsos)",
            "UX (escaneable, móvil, comprensión rápida)",
            "Estrategia + copy (enfoque, no solo texto)",
        ];
        $tones = ["Profesional directo","Cercano y humano","Sobrio","Enérgico","Técnico simple"];
        $ctas  = ["Reserva/Agenda","Consulta","Presupuesto","Diagnóstico"];
        $audiences = ["Pymes","Negocio local","Servicios","Marcas en crecimiento","Profesionales"];

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

REGLAS DURAS:
- NO dejes NINGÚN campo vacío. Prohibido "", null o "<p></p>" o "<p> </p>".
- SOLO 1 H1: hero_h1 (no uses <h1>).
- No usar “Introducción”, “Conclusión”, “¿Qué es…?”.
- Sin testimonios reales ni claims falsos.
- Evita keyword stuffing.
- HTML permitido SOLO: <p>, <strong>, <br>.
- EXACTAMENTE 4 features y EXACTAMENTE 9 FAQs.
- Longitudes cortas:
  hero_p_html/kit_p_html/pack_p_html/clients_p_html/kitdigital_p_html <= 320 chars
  feature p_html <= 240 chars
  faq a_html <= 260 chars
  seo_title 60-65 chars
- clients_subtitle MUY CORTO (6–12 palabras).

ESQUEMA EXACTO (NO cambies keys):
{"seo_title":"...","hero_kicker":"...","hero_h1":"...","hero_p_html":"<p>...</p>","kit_h1":"...","kit_p_html":"<p>...</p>","pack_h2":"...","pack_p_html":"<p>...</p>","price_h2":"...","features":[{"title":"...","p_html":"<p>...</p>"},{"title":"...","p_html":"<p>...</p>"},{"title":"...","p_html":"<p>...</p>"},{"title":"...","p_html":"<p>...</p>"}],"clients_label":"...","clients_subtitle":"...","clients_p_html":"<p>...</p>","reviews_label":"...","testimonios_title":"...","projects_title":"...","faq_title":"...","faq":[{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"},{"q":"...","a_html":"<p>...</p>"}],"final_cta_h3":"...","btn_presupuesto":"...","btn_reunion":"...","kitdigital_bold":"...","kitdigital_p_html":"<p>...</p>","btn_kitdigital":"..."}
PROMPT;
    }

    private function promptAuditorJson(string $tipo, string $keyword, array $draft, string $noRepetirTitles, string $noRepetirCorpus, array $brief): string
    {
        $draftShort = mb_substr(json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8500);
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

Reglas clave:
- clients_subtitle MUY CORTO (6–12 palabras).
- clients_p_html NO debe copiar pack_p_html.

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

BORRADOR:
{$draftShort}
PROMPT;
    }

    private function promptRepairJson(string $keyword, array $json, string $noRepetirTitles, string $noRepetirCorpus, array $brief): string
    {
        $short = mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 8500);
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');

        return <<<PROMPT
Corrige este JSON para que cumpla reglas y sea distinto.
Devuelve SOLO JSON válido con el MISMO esquema. RESPUESTA MINIFICADA.
NO puede haber campos vacíos ni "<p></p>".

Keyword: {$keyword}
Ángulo: {$angle}
Tono: {$tone}

Checklist:
- Solo 1 H1: hero_h1 (no uses <h1>)
- 4 features / 9 faq exactas
- clients_subtitle MUY CORTO (6–12 palabras)
- clients_p_html distinto a pack_p_html
- HTML: <p>, <strong>, <br>
- Nada vacío / nada "<p></p>"

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

JSON a reparar:
{$short}
PROMPT;
    }

    private function repairMissingFieldsViaDeepseek(
        string $apiKey,
        string $model,
        array $current,
        array $brief,
        string $stage,
        string $error,
        string $noRepetirTitles,
        string $noRepetirCorpus
    ): string {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $cta   = $this->toStr($brief['cta'] ?? '');
        $aud   = $this->toStr($brief['audience'] ?? '');

        $json = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = mb_substr((string)$json, 0, 9000);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. Sin texto fuera del JSON.
RESPUESTA MINIFICADA (sin saltos de línea).

Contexto:
- Keyword: {$this->keyword}
- Tipo: {$this->tipo}
- Etapa: {$stage}
- Error: {$error}

BRIEF:
- Ángulo: {$angle}
- Tono: {$tone}
- Público: {$aud}
- CTA: {$cta}

Reglas:
- NO dejar campos vacíos.
- clients_subtitle MUY CORTO (6–12 palabras).
- clients_p_html NO debe copiar pack_p_html.
- 4 features exactas y 9 FAQs exactas.
- HTML SOLO: <p>, <strong>, <br>.

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

JSON actual:
{$json}
PROMPT;

        return $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3400, temperature: 0.20, topP: 0.90, jsonMode: true);
    }

    // ===========================================================
    // SANITIZE + VALIDATE
    // ===========================================================
    private function sanitizeAndNormalizeCopy(array $copy): array
    {
        $copy['features'] = isset($copy['features']) && is_array($copy['features']) ? $copy['features'] : [];
        $copy['faq']      = isset($copy['faq']) && is_array($copy['faq']) ? $copy['faq'] : [];

        foreach (['hero_p_html','kit_p_html','pack_p_html','clients_p_html','kitdigital_p_html'] as $k) {
            if (isset($copy[$k])) {
                $copy[$k] = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($copy[$k])));
            }
        }

        if (isset($copy['seo_title'])) {
            $seo = trim(strip_tags($this->toStr($copy['seo_title'])));
            if (mb_strlen($seo) > 65) $seo = rtrim(mb_substr($seo, 0, 65), " \t\n\r\0\x0B-–—|:");
            $copy['seo_title'] = $seo;
        }

        if (count($copy['features']) > 4) $copy['features'] = array_slice($copy['features'], 0, 4);
        if (count($copy['faq']) > 9) $copy['faq'] = array_slice($copy['faq'], 0, 9);

        return $copy;
    }

    private function validateAndFixCopy(array $copy): array
    {
        $copy = $this->sanitizeAndNormalizeCopy($copy);

        $this->applyDynamicFallbacks($copy, force: true);

        foreach ([
            'seo_title','hero_kicker','hero_h1',
            'kit_h1','pack_h2','price_h2',
            'clients_label','clients_subtitle',
            'reviews_label','testimonios_title','projects_title',
            'faq_title','final_cta_h3',
            'btn_presupuesto','btn_reunion',
            'kitdigital_bold','btn_kitdigital',
        ] as $k) {
            $this->requireText($copy[$k] ?? '', $k);
        }

        foreach (['hero_p_html','kit_p_html','pack_p_html','clients_p_html','kitdigital_p_html'] as $k) {
            $this->requireHtml($copy[$k] ?? '', $k);
        }

        if (!is_array($copy['features']) || count($copy['features']) !== 4) {
            throw new \RuntimeException('Debe haber EXACTAMENTE 4 features generadas.');
        }
        if (!is_array($copy['faq']) || count($copy['faq']) !== 9) {
            throw new \RuntimeException('Debe haber EXACTAMENTE 9 FAQs generadas.');
        }

        foreach ($copy['features'] as $i => $f) {
            if (!is_array($f)) throw new \RuntimeException("Feature inválida index {$i}");
            $this->requireText($f['title'] ?? '', "features[$i].title");
            $this->requireHtml($f['p_html'] ?? '', "features[$i].p_html");
        }

        foreach ($copy['faq'] as $i => $q) {
            if (!is_array($q)) throw new \RuntimeException("FAQ inválida index {$i}");
            $this->requireText($q['q'] ?? '', "faq[$i].q");
            $this->requireHtml($q['a_html'] ?? '', "faq[$i].a_html");
        }

        $s = trim(strip_tags($this->toStr($copy['clients_subtitle'] ?? '')));
        $words = preg_split('~\s+~u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if ($words && count($words) > 12) $copy['clients_subtitle'] = implode(' ', array_slice($words, 0, 12));

        if ($this->violatesSeoHardRules($copy)) {
            throw new \RuntimeException("El JSON viola reglas SEO/H1");
        }

        return $copy;
    }

    // ===========================================================
    // TOKENS
    // ===========================================================
    private function fillElementorTemplate_byPrettyTokens_withStats(array $tpl, array $copy): array
    {
        $copy = $this->validateAndFixCopy($copy);

        $dict = $this->buildPrettyTokenDictionary($copy);

        $replacedCount = 0;
        $this->replaceTokensDeep($tpl, $dict, $replacedCount);

        $remaining = $this->collectRemainingTokensDeep($tpl);

        return [$tpl, $replacedCount, $remaining];
    }

    private function buildPrettyTokenDictionary(array $copy): array
    {
        $featuresListHtml = $this->buildFeaturesListHtml($copy);

        $dict = [
            // CORE
            '{{HERO_KICKER}}' => trim(strip_tags($this->toStr($copy['hero_kicker']))),
            '{{HERO_H1}}'     => trim(strip_tags($this->toStr($copy['hero_h1']))),
            '{{HERO_P}}'      => $this->keepAllowedInlineHtml($this->toStr($copy['hero_p_html'])),

            '{{PACK_H2}}'   => trim(strip_tags($this->toStr($copy['pack_h2']))),
            '{{PACK_P}}'    => $this->keepAllowedInlineHtml($this->toStr($copy['pack_p_html'])),
            '{{PRICE_H2}}'  => trim(strip_tags($this->toStr($copy['price_h2']))),

            '{{KIT_H1}}' => trim(strip_tags($this->toStr($copy['kit_h1']))),
            '{{KIT_P}}'  => $this->keepAllowedInlineHtml($this->toStr($copy['kit_p_html'])),

            '{{FEATURE_1_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][0]['title']))),
            '{{FEATURE_1_P}}'     => $this->keepAllowedInlineHtml($this->toStr($copy['features'][0]['p_html'])),

            '{{FEATURE_2_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][1]['title']))),
            '{{FEATURE_2_P}}'     => $this->keepAllowedInlineHtml($this->toStr($copy['features'][1]['p_html'])),

            '{{FEATURE_3_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][2]['title']))),
            '{{FEATURE_3_P}}'     => $this->keepAllowedInlineHtml($this->toStr($copy['features'][2]['p_html'])),

            '{{FEATURE_4_TITLE}}' => trim(strip_tags($this->toStr($copy['features'][3]['title']))),
            '{{FEATURE_4_P}}'     => $this->keepAllowedInlineHtml($this->toStr($copy['features'][3]['p_html'])),

            '{{FEATURES_LIST_HTML}}' => $featuresListHtml,

            '{{CLIENTS_LABEL}}'     => trim(strip_tags($this->toStr($copy['clients_label']))),
            '{{CLIENTS_SUBTITLE}}'  => trim(strip_tags($this->toStr($copy['clients_subtitle']))),
            '{{CLIENTS_P}}'         => $this->keepAllowedInlineHtml($this->toStr($copy['clients_p_html'])),

            '{{REVIEWS_LABEL}}'     => trim(strip_tags($this->toStr($copy['reviews_label']))),
            '{{TESTIMONIOS_TITLE}}' => trim(strip_tags($this->toStr($copy['testimonios_title']))),
            '{{PROJECTS_TITLE}}'    => trim(strip_tags($this->toStr($copy['projects_title']))),

            '{{FAQ_TITLE}}' => trim(strip_tags($this->toStr($copy['faq_title']))),

            '{{FINAL_CTA}}' => trim(strip_tags($this->toStr($copy['final_cta_h3']))),

            '{{BTN_PRESUPUESTO}}' => trim(strip_tags($this->toStr($copy['btn_presupuesto']))),
            '{{BTN_REUNION}}'     => trim(strip_tags($this->toStr($copy['btn_reunion']))),

            '{{KITDIGITAL_BOLD}}' => trim(strip_tags($this->toStr($copy['kitdigital_bold']))),
            '{{KITDIGITAL_P}}'    => $this->keepAllowedInlineHtml($this->toStr($copy['kitdigital_p_html'])),
            '{{BTN_KITDIGITAL}}'  => trim(strip_tags($this->toStr($copy['btn_kitdigital']))),
        ];

        // FAQ 1..9
        for ($i = 0; $i < 9; $i++) {
            $dict['{{FAQ_' . ($i + 1) . '_Q}}'] = trim(strip_tags($this->toStr($copy['faq'][$i]['q'])));
            $dict['{{FAQ_' . ($i + 1) . '_A}}'] = $this->keepAllowedInlineHtml($this->toStr($copy['faq'][$i]['a_html']));
        }

        // ===========================================================
        // ✅ TOKENS UNIVERSALES (para cualquier plantilla)
        // ===========================================================
        $univ = $this->buildUniversalTokenValues($copy);

        foreach ($univ as $tok => $val) {
            $dict[$tok] = $val;
        }

        return $dict;
    }

    private function buildUniversalTokenValues(array $copy): array
    {
        // 12 secciones (title/p)
        $sectionsTitle = [];
        $sectionsP = [];

        $sectionsTitle[] = trim(strip_tags($this->toStr($copy['hero_h1'] ?? '')));
        $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($copy['hero_p_html'] ?? '<p></p>'));

        $sectionsTitle[] = trim(strip_tags($this->toStr($copy['pack_h2'] ?? '')));
        $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($copy['pack_p_html'] ?? '<p></p>'));

        $sectionsTitle[] = trim(strip_tags($this->toStr($copy['kit_h1'] ?? '')));
        $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($copy['kit_p_html'] ?? '<p></p>'));

        $sectionsTitle[] = trim(strip_tags($this->toStr($copy['clients_subtitle'] ?? '')));
        $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($copy['clients_p_html'] ?? '<p></p>'));

        $sectionsTitle[] = trim(strip_tags($this->toStr($copy['projects_title'] ?? '')));
        $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($copy['kitdigital_p_html'] ?? '<p></p>'));

        // Rellenar con features + faq
        if (!empty($copy['features']) && is_array($copy['features'])) {
            foreach ($copy['features'] as $f) {
                if (!is_array($f)) continue;
                $sectionsTitle[] = trim(strip_tags($this->toStr($f['title'] ?? '')));
                $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($f['p_html'] ?? '<p></p>'));
            }
        }
        if (!empty($copy['faq']) && is_array($copy['faq'])) {
            foreach ($copy['faq'] as $q) {
                if (!is_array($q)) continue;
                $sectionsTitle[] = trim(strip_tags($this->toStr($q['q'] ?? '')));
                $sectionsP[]     = $this->keepAllowedInlineHtml($this->toStr($q['a_html'] ?? '<p></p>'));
            }
        }

        // bullets 1..12 (cortos)
        $bullets = [];
        if (!empty($copy['features']) && is_array($copy['features'])) {
            foreach ($copy['features'] as $f) {
                if (!is_array($f)) continue;
                $t = trim(strip_tags($this->toStr($f['title'] ?? '')));
                if ($t !== '') $bullets[] = $t;
            }
        }
        if (!empty($copy['faq']) && is_array($copy['faq'])) {
            foreach ($copy['faq'] as $q) {
                if (!is_array($q)) continue;
                $t = trim(strip_tags($this->toStr($q['q'] ?? '')));
                if ($t !== '') $bullets[] = $t;
            }
        }
        $bullets = array_values(array_unique(array_filter($bullets)));

        // badges 1..6 (microcopy)
        $badges = array_values(array_filter(array_unique([
            trim(strip_tags($this->toStr($copy['hero_kicker'] ?? ''))),
            trim(strip_tags($this->toStr($copy['clients_label'] ?? ''))),
            trim(strip_tags($this->toStr($copy['reviews_label'] ?? ''))),
            trim(strip_tags($this->toStr($copy['kitdigital_bold'] ?? ''))),
            trim(strip_tags($this->toStr($copy['price_h2'] ?? ''))),
        ])));

        $out = [];

        for ($i=1; $i<=12; $i++) {
            $t = $sectionsTitle[$i-1] ?? '';
            $p = $sectionsP[$i-1] ?? '<p></p>';
            if ($t === '') $t = "Sección {$i}";
            if ($this->isBlankHtml($p)) $p = "<p>Texto breve y adaptable para esta sección.</p>";

            $out["{{SECTION_{$i}_TITLE}}"] = $t;
            $out["{{SECTION_{$i}_P}}"]     = $p;
        }

        for ($i=1; $i<=12; $i++) {
            $b = $bullets[$i-1] ?? "Punto {$i}";
            $out["{{BULLET_{$i}}}"] = $b;
        }

        for ($i=1; $i<=6; $i++) {
            $b = $badges[$i-1] ?? "Etiqueta {$i}";
            $out["{{BADGE_{$i}}}"] = $b;
        }

        // CTAs 1..3
        $out["{{CTA_1_TITLE}}"] = trim(strip_tags($this->toStr($copy['final_cta_h3'] ?? '')));
        $out["{{CTA_1_P}}"]     = $this->keepAllowedInlineHtml($this->toStr($copy['hero_p_html'] ?? '<p></p>'));
        $out["{{CTA_1_BTN}}"]   = trim(strip_tags($this->toStr($copy['btn_presupuesto'] ?? '')));

        $out["{{CTA_2_TITLE}}"] = trim(strip_tags($this->toStr($copy['pack_h2'] ?? '')));
        $out["{{CTA_2_P}}"]     = $this->keepAllowedInlineHtml($this->toStr($copy['pack_p_html'] ?? '<p></p>'));
        $out["{{CTA_2_BTN}}"]   = trim(strip_tags($this->toStr($copy['btn_reunion'] ?? '')));

        $out["{{CTA_3_TITLE}}"] = trim(strip_tags($this->toStr($copy['kitdigital_bold'] ?? '')));
        $out["{{CTA_3_P}}"]     = $this->keepAllowedInlineHtml($this->toStr($copy['kitdigital_p_html'] ?? '<p></p>'));
        $out["{{CTA_3_BTN}}"]   = trim(strip_tags($this->toStr($copy['btn_kitdigital'] ?? '')));

        return $out;
    }

    private function buildFeaturesListHtml(array $copy): string
    {
        $parts = [];
        for ($i = 0; $i < 4; $i++) {
            $t = trim(strip_tags($this->toStr($copy['features'][$i]['title'] ?? '')));
            $pTxt = trim(strip_tags($this->toStr($copy['features'][$i]['p_html'] ?? '')));
            if ($t === '') $t = "Bloque " . ($i+1);
            if ($pTxt === '') $pTxt = "Texto breve y reutilizable.";
            $parts[] = "<p><strong>" . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ":</strong> " . htmlspecialchars($pTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
        }
        return implode('', $parts);
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
    // Post-pass (opcional)
    // ===========================================================
    private function forceReplaceStaticTextsInTemplate(array $tpl, array $copy): array
    {
        $mapExact = [];

        $bp = trim(strip_tags($this->toStr($copy['btn_presupuesto'] ?? '')));
        $br = trim(strip_tags($this->toStr($copy['btn_reunion'] ?? '')));
        $bk = trim(strip_tags($this->toStr($copy['btn_kitdigital'] ?? '')));
        $fc = trim(strip_tags($this->toStr($copy['final_cta_h3'] ?? '')));

        if ($bp !== '') {
            $mapExact["Solicitar presupuesto"] = $bp;
            $mapExact["Pedir presupuesto"] = $bp;
            $mapExact["Solicitar propuesta"] = $bp;
        }
        if ($br !== '') {
            $mapExact["Reservar llamada"] = $br;
            $mapExact["Agendar reunión"] = $br;
            $mapExact["Agendar reunion"] = $br;
            $mapExact["Agendar llamada"] = $br;
        }
        if ($bk !== '') {
            $mapExact["Acceder al Kit Digital"] = $bk;
            $mapExact["Ver Kit Digital"] = $bk;
            $mapExact["Solicitar Kit Digital"] = $bk;
        }
        if ($fc !== '') {
            $mapExact["¿Listo para avanzar?"] = $fc;
        }

        $count = 0;
        if (!empty($mapExact)) $this->replaceStringsRecursive($tpl, $mapExact, $count);

        return [$tpl, $count];
    }

    private function replaceStringsRecursive(mixed &$node, array $mapExact, int &$count): void
    {
        if (is_array($node)) {
            foreach ($node as &$v) $this->replaceStringsRecursive($v, $mapExact, $count);
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
                if (str_contains($node, $from)) $node = str_replace($from, $to, $node);
            }
        }

        if ($node !== $orig) $count++;
    }

    // ===========================================================
    // Utils / validation
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
        $clean = strip_tags($html, '<p><strong><br>');
        $clean = preg_replace('~\s+~u', ' ', $clean);
        $clean = str_replace(['</p> <p>','</p><p>'], '</p><p>', $clean);
        return trim((string)$clean);
    }

    private function requireText(mixed $value, string $field): string
    {
        $v = trim(strip_tags($this->toStr($value)));
        if ($v === '') throw new \RuntimeException("Campo vacío generado: {$field}");
        return $v;
    }

    private function requireHtml(mixed $html, string $field): string
    {
        $h = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($html)));
        if ($h === '' || $this->isBlankHtml($h) || preg_match('~<p>\s*</p>~i', $h)) {
            throw new \RuntimeException("HTML vacío generado: {$field}");
        }
        return $h;
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

    // ===========================================================
    // Anti-repetición
    // ===========================================================
    private function isTooSimilarToAnyPrevious(array $copy, array $usedTitles, array $usedCorpus): bool
    {
        $title = mb_strtolower(trim($this->toStr($copy['seo_title'] ?? $copy['hero_h1'] ?? '')));
        if ($title !== '') {
            foreach ($usedTitles as $t) {
                $t2 = mb_strtolower(trim((string)$t));
                if ($t2 !== '' && $this->jaccardBigrams($title, $t2) >= 0.65) return true;
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
        for ($i = 0; $i < $n - 1; $i++) $out[$chars[$i] . $chars[$i + 1]] = 1;
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
        foreach ([
            'seo_title','hero_kicker','hero_h1','hero_p_html',
            'kit_h1','kit_p_html','pack_h2','pack_p_html','price_h2',
            'clients_label','clients_subtitle','clients_p_html',
            'reviews_label','testimonios_title','projects_title',
            'faq_title','final_cta_h3','btn_presupuesto','btn_reunion',
            'kitdigital_bold','kitdigital_p_html','btn_kitdigital'
        ] as $k) {
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
}
