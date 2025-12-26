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

    public string $jobUuid;
    public ?int $registroId = null;
    private array $briefContext = [];

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {
        // ✅ Nuevo registro por corrida (no reusa registros viejos)
        $this->jobUuid = (string) Str::uuid();
    }

    public function handle(): void
    {
        $registro = null;

        try {
            // ===========================================================
            // 1) Registro por corrida del job
            // ===========================================================
            $registro = $this->getOrCreateRegistroPerJobRun();
            $this->registroId = (int)$registro->id_dominio_contenido_detalle;

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
            if ($apiKey === '') {
                throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');
            }

            // ===========================================================
            // 3) Historial anti-repetición
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
            // 4) Generación: redactor -> auditor -> repair
            // ===========================================================
            $final = null;

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $brief = $this->creativeBrief($this->keyword);
                $this->briefContext = $brief;

                // REDACTOR
                $draftPrompt = $this->promptRedactorJson(
                    $this->tipo,
                    $this->keyword,
                    $noRepetirTitles,
                    $noRepetirCorpus,
                    $brief
                );

                $draftRaw = $this->deepseekText($apiKey, $model, $draftPrompt, maxTokens: 3200, temperature: 0.92, topP: 0.90, jsonMode: true);
                $draftArr = $this->safeParseOrRepair($apiKey, $model, $draftRaw, $brief);
                $draftArr = $this->validateOrRepairCopy($apiKey, $model, $draftArr, $brief, 'redactor', $noRepetirTitles, $noRepetirCorpus);

                // AUDITOR
                $auditPrompt = $this->promptAuditorJson(
                    $this->tipo,
                    $this->keyword,
                    $draftArr,
                    $noRepetirTitles,
                    $noRepetirCorpus,
                    $brief
                );

                $auditedRaw = $this->deepseekText($apiKey, $model, $auditPrompt, maxTokens: 3400, temperature: 0.85, topP: 0.90, jsonMode: true);
                $candidateArr = $this->safeParseOrRepair($apiKey, $model, $auditedRaw, $brief);
                $candidateArr = $this->validateOrRepairCopy($apiKey, $model, $candidateArr, $brief, 'auditor', $noRepetirTitles, $noRepetirCorpus);

                // REPAIR si viola o similar
                if ($this->violatesSeoHardRules($candidateArr) || $this->isTooSimilarToAnyPrevious($candidateArr, $usedTitles, $usedCorpus)) {
                    $repairPrompt = $this->promptRepairJson($this->keyword, $candidateArr, $noRepetirTitles, $noRepetirCorpus, $brief);
                    $repairRaw = $this->deepseekText($apiKey, $model, $repairPrompt, maxTokens: 3400, temperature: 0.25, topP: 0.90, jsonMode: true);
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

                $usedTitles[] = $this->toStr($candidateArr['seo_title'] ?? $candidateArr['hero_h1'] ?? '');
                $usedCorpus[] = $this->copyTextFromArray($candidateArr);
                $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 12));
                $noRepetirCorpus = $this->compactHistory($usedCorpus, 2500);
            }

            if (!is_array($final)) {
                throw new \RuntimeException('No se pudo generar contenido final');
            }

            // ===========================================================
            // 5) Template + reemplazo tokens
            // ===========================================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);

            [$filled, $replacedCount, $remaining] = $this->fillElementorTemplate_tokens_withStats($tpl, $final);

            if ($replacedCount < 1) {
                throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}");
            }
            if (!empty($remaining)) {
                throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remaining, 0, 80)));
            }

            // ===========================================================
            // 6) Title + slug
            // ===========================================================
            $title = trim(strip_tags($this->toStr($final['seo_title'] ?? $final['hero_h1'] ?? $this->keyword)));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // 7) Guardar
            // ===========================================================
            $registro->update([
                'title'          => $title,
                'slug'           => $slug,
                'draft_html'     => json_encode($final, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
    // Registro por corrida (no reusa registros viejos)
    // ===========================================================
    private function getOrCreateRegistroPerJobRun(): Dominios_Contenido_DetallesModel
    {
        if ($this->registroId) {
            $r = Dominios_Contenido_DetallesModel::find($this->registroId);
            if ($r) return $r;
        }

        $existing = Dominios_Contenido_DetallesModel::where('job_uuid', $this->jobUuid)->first();
        if ($existing) return $existing;

        return Dominios_Contenido_DetallesModel::create([
            'job_uuid'             => $this->jobUuid,
            'id_dominio_contenido' => (int)$this->idDominioContenido,
            'id_dominio'           => (int)$this->idDominio,
            'tipo'                 => $this->tipo,
            'keyword'              => $this->keyword,
            'estatus'              => 'en_proceso',
            'modelo'               => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ]);
    }

    // ===========================================================
    // TEMPLATE LOADER
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) throw new \RuntimeException("NO_RETRY: Dominio no encontrado (id={$idDominio})");

        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));
        if ($templateRel === '') $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_PATH', ''));
        if ($templateRel === '') throw new \RuntimeException('NO_RETRY: No hay plantilla configurada.');

        if (preg_match('~^https?://~i', $templateRel)) {
            $u = parse_url($templateRel);
            $templateRel = $u['path'] ?? $templateRel;
        }

        $templateRel = preg_replace('~^/?storage/app/~i', '', $templateRel);
        $templateRel = ltrim(str_replace('\\', '/', $templateRel), '/');

        if (str_contains($templateRel, '..')) throw new \RuntimeException('NO_RETRY: Template path inválido ("..")');

        $templatePath = storage_path('app/' . $templateRel);
        if (!is_file($templatePath)) throw new \RuntimeException("NO_RETRY: No existe el template: {$templatePath}");

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('NO_RETRY: Template Elementor inválido (content array).');
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

        if (!$resp->successful()) {
            throw new \RuntimeException("DeepSeek error {$resp->status()}: {$resp->body()}");
        }

        $data = $resp->json();
        $text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') throw new \RuntimeException("DeepSeek returned empty text.");

        return $text;
    }

    // ===========================================================
    // PARSE + SKELETON
    // ===========================================================
    private function safeParseOrRepair(string $apiKey, string $model, string $raw, array $brief): array
    {
        try {
            $a = $this->parseJsonStrict($raw);
            return $this->mergeWithSkeleton($a);
        } catch (\Throwable $e) {
            $loose = $this->parseJsonLoose($raw);
            $merged = $this->mergeWithSkeleton($loose);

            if (trim($this->toStr($merged['hero_h1'] ?? '')) !== '' || trim($this->toStr($merged['pack_h2'] ?? '')) !== '') {
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
Devuelve SOLO JSON válido. Sin markdown. RESPUESTA MINIFICADA.
Keys obligatorias:
seo_title, hero_kicker, hero_h1, hero_p_html,
kit_h1, kit_p_html, pack_h2, pack_p_html, price_h2,
features (4), clients_label, clients_subtitle, clients_p_html,
reviews_label, testimonios_title, projects_title,
faq_title, faq (9),
final_cta_h3, btn_presupuesto, btn_reunion,
kitdigital_bold, kitdigital_p_html, btn_kitdigital

Reglas:
- HTML SOLO: <p>, <strong>, <br>
- SOLO 1 H1: hero_h1 (no uses <h1>)
- 4 features exactas y 9 FAQs exactas
- clients_subtitle 6–12 palabras
- NO vacíos ni "<p></p>"

Ángulo: {$angle}
Tono: {$tone}

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

        return $out;
    }

    private function extractJsonValueLoose(string $raw, string $key): mixed
    {
        $patternStr = '~"' . preg_quote($key, '~') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)~u';
        if (preg_match($patternStr, $raw, $m)) {
            $inner = (string)($m[1] ?? '');
            $decoded = json_decode('"' . $inner . '"', true);
            return is_string($decoded) ? $decoded : stripcslashes($inner);
        }

        return null;
    }

    // ===========================================================
    // VALIDATE / FALLBACKS (CORREGIDO: NO pisa lo bueno)
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
        $this->applyDynamicFallbacks($copy, force: true); // ✅ rellena vacíos, NO pisa todo

        try {
            return $this->validateAndFixCopy($copy);
        } catch (\Throwable $e) {
            // Repair IA 1 vez
            $repairRaw = $this->repairMissingFieldsViaDeepseek(
                $apiKey, $model, $copy, $brief, $stage, $e->getMessage(), $noRepetirTitles, $noRepetirCorpus
            );
            $repaired = $this->safeParseOrRepair($apiKey, $model, $repairRaw, $brief);
            $repaired = $this->sanitizeAndNormalizeCopy($repaired);
            $this->applyDynamicFallbacks($repaired, force: true, hard: true);

            return $this->validateAndFixCopy($repaired);
        }
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
Devuelve SOLO JSON válido. RESPUESTA MINIFICADA.
Keyword: {$this->keyword}
Tipo: {$this->tipo}
Etapa: {$stage}
Error: {$error}

BRIEF:
Ángulo: {$angle}
Tono: {$tone}
Público: {$aud}
CTA: {$cta}

Reglas:
- NO vacíos
- 4 features exactas + 9 FAQs exactas
- clients_subtitle 6–12 palabras
- HTML SOLO: <p>, <strong>, <br>

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

JSON actual:
{$json}
PROMPT;

        return $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3400, temperature: 0.20, topP: 0.90, jsonMode: true);
    }

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

        // recorta pero no destruye
        if (count($copy['features']) > 4) $copy['features'] = array_slice($copy['features'], 0, 4);
        if (count($copy['faq']) > 9) $copy['faq'] = array_slice($copy['faq'], 0, 9);

        return $copy;
    }

    private function validateAndFixCopy(array $copy): array
    {
        $copy = $this->sanitizeAndNormalizeCopy($copy);
        $this->applyDynamicFallbacks($copy, force: true); // ✅ rellena vacíos, NO pisa

        foreach ([
            'seo_title','hero_kicker','hero_h1',
            'kit_h1','pack_h2','price_h2',
            'clients_label','clients_subtitle',
            'reviews_label','testimonios_title','projects_title',
            'faq_title','final_cta_h3',
            'btn_presupuesto','btn_reunion',
            'kitdigital_bold','btn_kitdigital',
        ] as $k) $this->requireText($copy[$k] ?? '', $k);

        foreach (['hero_p_html','kit_p_html','pack_p_html','clients_p_html','kitdigital_p_html'] as $k) {
            $this->requireHtml($copy[$k] ?? '', $k);
        }

        if (count($copy['features']) !== 4) throw new \RuntimeException('Debe haber EXACTAMENTE 4 features.');
        if (count($copy['faq']) !== 9) throw new \RuntimeException('Debe haber EXACTAMENTE 9 FAQs.');

        for ($i=0; $i<4; $i++) {
            $this->requireText($copy['features'][$i]['title'] ?? '', "features[$i].title");
            $this->requireHtml($copy['features'][$i]['p_html'] ?? '', "features[$i].p_html");
        }
        for ($i=0; $i<9; $i++) {
            $this->requireText($copy['faq'][$i]['q'] ?? '', "faq[$i].q");
            $this->requireHtml($copy['faq'][$i]['a_html'] ?? '', "faq[$i].a_html");
        }

        return $copy;
    }

    private function applyDynamicFallbacks(array &$copy, bool $force = false, bool $hard = false): void
    {
        $kw = $this->shortKw();

        // ✅ CORRECCIÓN: force = “rellena vacíos”, NO “pisa todo”
        $needText = function(string $k) use (&$copy): bool {
            return trim(strip_tags($this->toStr($copy[$k] ?? ''))) === '';
        };

        $needHtml = function(string $k) use (&$copy): bool {
            $h = $this->keepAllowedInlineHtml($this->stripH1Tags($this->toStr($copy[$k] ?? '')));
            return ($h === '' || $this->isBlankHtml($h) || preg_match('~<p>\s*</p>~i', $h));
        };

        if ($needText('hero_kicker')) $copy['hero_kicker'] = $this->pick(["Web que convierte","Sitio optimizado","Mensaje claro","Estructura sólida"]);
        if ($needText('hero_h1'))     $copy['hero_h1'] = "{$kw} con estructura y copy que convierten";
        if ($needHtml('hero_p_html')) $copy['hero_p_html'] = "<p>Contenido pensado para {$kw}: claro, escaneable y orientado a convertir.</p>";

        if ($needText('kit_h1'))      $copy['kit_h1'] = "Bloques listos para {$kw}";
        if ($needHtml('kit_p_html'))  $copy['kit_p_html'] = "<p>Secciones coherentes y textos listos para adaptar y publicar, con CTA claro.</p>";

        if ($needText('pack_h2'))     $copy['pack_h2'] = "Estructura y copy para {$kw}";
        if ($needHtml('pack_p_html')) $copy['pack_p_html'] = "<p>Mensajes alineados a intención, con beneficios claros y CTA consistente.</p>";

        if ($needText('price_h2'))    $copy['price_h2'] = $this->pick(["Plan claro y entregables definidos","Entrega lista para publicar","Implementación rápida y ordenada"]);

        if ($needText('clients_label'))    $copy['clients_label'] = $this->pick(["Marcas","Equipos","Negocios","Proyectos"]);
        if ($needText('clients_subtitle')) $copy['clients_subtitle'] = $this->pick(["Claridad, orden y enfoque","Mensaje directo y estructura","Secciones con propósito"]);
        if ($needHtml('clients_p_html'))   $copy['clients_p_html'] = "<p>Ideal para equipos que necesitan una web coherente: propuesta clara y textos listos para publicar.</p>";

        if ($needText('reviews_label'))     $copy['reviews_label'] = $this->pick(["Reseñas","Opiniones","Resultados","Valoraciones"]);
        if ($needText('testimonios_title')) $copy['testimonios_title'] = $this->pick(["Lo que suelen valorar","Qué suele funcionar","Puntos fuertes del enfoque"]);
        if ($needText('projects_title'))    $copy['projects_title'] = "Cómo trabajamos en {$kw}";
        if ($needText('faq_title'))         $copy['faq_title'] = "Preguntas frecuentes";

        if ($needText('final_cta_h3'))     $copy['final_cta_h3'] = "¿Quieres publicarlo y avanzar?";
        if ($needText('btn_presupuesto'))  $copy['btn_presupuesto'] = $this->pick(["Pedir propuesta","Solicitar presupuesto","Ver opciones"]);
        if ($needText('btn_reunion'))      $copy['btn_reunion'] = $this->pick(["Agendar llamada","Reservar llamada","Hablar ahora"]);

        if ($needText('kitdigital_bold'))   $copy['kitdigital_bold'] = $this->pick(["Información de ayudas","Opciones disponibles","Kit Digital"]);
        if ($needHtml('kitdigital_p_html')) $copy['kitdigital_p_html'] = "<p>Si aplica, te guiamos en el proceso y dejamos la entrega lista para publicar.</p>";
        if ($needText('btn_kitdigital'))    $copy['btn_kitdigital'] = $this->pick(["Ver información","Consultar","Empezar"]);

        // FEATURES: asegura 4 y rellena vacíos internos
        if (!isset($copy['features']) || !is_array($copy['features'])) $copy['features'] = [];
        $fallbackFeatures = [
            ['title' => "Mensaje claro", 'p_html' => "<p>Texto directo, sin ruido, fácil de escanear y adaptar.</p>"],
            ['title' => "Estructura con intención", 'p_html' => "<p>Secciones ordenadas para guiar lectura y decisión.</p>"],
            ['title' => "SEO natural", 'p_html' => "<p>Semántica integrada sin repetir palabras de forma artificial.</p>"],
            ['title' => "Listo para publicar", 'p_html' => "<p>Bloques y textos listos para WordPress/Elementor.</p>"],
        ];

        if ($hard || count($copy['features']) !== 4) $copy['features'] = $fallbackFeatures;

        for ($i=0; $i<4; $i++) {
            if (!isset($copy['features'][$i]) || !is_array($copy['features'][$i])) $copy['features'][$i] = $fallbackFeatures[$i];
            $t = trim(strip_tags($this->toStr($copy['features'][$i]['title'] ?? '')));
            $p = $this->keepAllowedInlineHtml($this->toStr($copy['features'][$i]['p_html'] ?? ''));
            if ($t === '') $copy['features'][$i]['title'] = $fallbackFeatures[$i]['title'];
            if ($p === '' || $this->isBlankHtml($p) || preg_match('~<p>\s*</p>~i', $p)) $copy['features'][$i]['p_html'] = $fallbackFeatures[$i]['p_html'];
        }

        // FAQ: asegura 9 y rellena vacíos internos
        if (!isset($copy['faq']) || !is_array($copy['faq'])) $copy['faq'] = [];
        if ($hard || count($copy['faq']) !== 9) {
            $copy['faq'] = [];
            $qTpl = [
                "¿Qué incluye este contenido?",
                "¿Cuánto tarda en estar listo?",
                "¿Qué necesito aportar para empezar?",
                "¿Se adapta a mi sector o ciudad?",
                "¿Cómo evitamos contenido duplicado?",
                "¿Puedo publicarlo yo mismo?",
                "¿Qué diferencia esto de algo genérico?",
                "¿Hay ajustes después de la entrega?",
                "¿Qué no incluye para evitar expectativas falsas?",
            ];
            $aTpl = [
                "<p>Estructura, textos y bloques listos para publicar y ajustar.</p>",
                "<p>Avanza rápido con un brief claro; depende del alcance.</p>",
                "<p>Oferta, público y 2–3 referencias. Si falta claridad, se define contigo.</p>",
                "<p>Sí, ajustamos enfoque y lenguaje sin forzar términos.</p>",
                "<p>Se varía estructura y redacción y se compara contra historial reciente.</p>",
                "<p>Sí. Queda en formato simple para Elementor/WordPress.</p>",
                "<p>Está pensado para intención y claridad, no para rellenar secciones.</p>",
                "<p>Se contempla una ronda razonable de ajustes para coherencia.</p>",
                "<p>No promete resultados irreales: se define alcance y entregables.</p>",
            ];
            for ($i=0; $i<9; $i++) $copy['faq'][] = ['q' => $qTpl[$i], 'a_html' => $aTpl[$i]];
        }

        for ($i=0; $i<9; $i++) {
            $q = trim(strip_tags($this->toStr($copy['faq'][$i]['q'] ?? '')));
            $a = $this->keepAllowedInlineHtml($this->toStr($copy['faq'][$i]['a_html'] ?? ''));
            if ($q === '') $copy['faq'][$i]['q'] = "Pregunta frecuente " . ($i+1);
            if ($a === '' || $this->isBlankHtml($a) || preg_match('~<p>\s*</p>~i', $a)) $copy['faq'][$i]['a_html'] = "<p>Respuesta breve y concreta alineada a entregables.</p>";
        }
    }

    // ===========================================================
    // TOKENS: reemplazo robusto ({{TOKEN}} o {{ TOKEN }})
    // ===========================================================
    private function fillElementorTemplate_tokens_withStats(array $tpl, array $copy): array
    {
        $copy = $this->validateAndFixCopy($copy);

        $tokenNames = $this->collectTokenNamesDeep($tpl);
        $dictByName = $this->buildTokenValuesForTemplate($copy, $tokenNames);

        $replacedCount = 0;
        $this->replaceTokensDeepRegex($tpl, $dictByName, $replacedCount);

        $remaining = $this->collectRemainingTokensDeep($tpl);

        return [$tpl, $replacedCount, $remaining];
    }

    private function collectTokenNamesDeep(mixed $node): array
    {
        $found = [];
        $walk = function ($n) use (&$walk, &$found) {
            if (is_array($n)) { foreach ($n as $v) $walk($v); return; }
            if (!is_string($n) || $n === '') return;
            if (preg_match_all('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', $n, $m)) {
                foreach ($m[1] as $name) $found[] = $name;
            }
        };
        $walk($node);
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    private function buildTokenValuesForTemplate(array $copy, array $tokenNames): array
    {
        $getTxt = fn($k) => trim(strip_tags($this->toStr($copy[$k] ?? '')));
        $getHtml = fn($k) => $this->keepAllowedInlineHtml($this->toStr($copy[$k] ?? ''));

        $values = [
            // ✅ Tus tokens reales (screenshot)
            'HERO_H1'        => $getTxt('hero_h1'),
            'HERO_KICKER'    => $getTxt('hero_kicker'),
            'BTN_PRESUPUESTO'=> $getTxt('btn_presupuesto'),

            // otros “pretty tokens” típicos
            'HERO_P'         => $getHtml('hero_p_html'),
            'KIT_H1'         => $getTxt('kit_h1'),
            'KIT_P'          => $getHtml('kit_p_html'),
            'PACK_H2'        => $getTxt('pack_h2'),
            'PACK_P'         => $getHtml('pack_p_html'),
            'PRICE_H2'       => $getTxt('price_h2'),

            'CLIENTS_LABEL'    => $getTxt('clients_label'),
            'CLIENTS_SUBTITLE' => $getTxt('clients_subtitle'),
            'CLIENTS_P'        => $getHtml('clients_p_html'),

            'REVIEWS_LABEL'     => $getTxt('reviews_label'),
            'TESTIMONIOS_TITLE' => $getTxt('testimonios_title'),
            'PROJECTS_TITLE'    => $getTxt('projects_title'),

            'FAQ_TITLE'         => $getTxt('faq_title'),
            'FINAL_CTA'         => $getTxt('final_cta_h3'),

            'BTN_REUNION'       => $getTxt('btn_reunion'),

            'KITDIGITAL_BOLD'   => $getTxt('kitdigital_bold'),
            'KITDIGITAL_P'      => $getHtml('kitdigital_p_html'),
            'BTN_KITDIGITAL'    => $getTxt('btn_kitdigital'),
        ];

        // features / faq tokens
        for ($i=1; $i<=4; $i++) {
            $values["FEATURE_{$i}_TITLE"] = trim(strip_tags($this->toStr($copy['features'][$i-1]['title'] ?? '')));
            $values["FEATURE_{$i}_P"]     = $this->keepAllowedInlineHtml($this->toStr($copy['features'][$i-1]['p_html'] ?? ''));
        }

        for ($i=1; $i<=9; $i++) {
            $values["FAQ_{$i}_Q"] = trim(strip_tags($this->toStr($copy['faq'][$i-1]['q'] ?? '')));
            $values["FAQ_{$i}_A"] = $this->keepAllowedInlineHtml($this->toStr($copy['faq'][$i-1]['a_html'] ?? ''));
        }

        // ✅ Para no dejar tokens sueltos: rellena cualquier token desconocido con algo decente
        $kw = $this->shortKw();
        $out = [];
        foreach ($tokenNames as $name) {
            $val = $values[$name] ?? null;
            if ($val === null || trim(strip_tags((string)$val)) === '') {
                if (str_starts_with($name, 'BTN_')) $val = $getTxt('btn_presupuesto') ?: "Ver opciones";
                else $val = $kw;
            }
            $out[$name] = (string)$val;
        }

        return $out;
    }

    private function replaceTokensDeepRegex(mixed &$node, array $dictByName, int &$count): void
    {
        if (is_array($node)) {
            foreach ($node as &$v) $this->replaceTokensDeepRegex($v, $dictByName, $count);
            return;
        }

        if (!is_string($node) || $node === '') return;
        if (!str_contains($node, '{{')) return;

        $orig = $node;
        $node = preg_replace_callback('/\{\{\s*([A-Z0-9_]+)\s*\}\}/', function ($m) use ($dictByName) {
            $name = $m[1] ?? '';
            if ($name !== '' && array_key_exists($name, $dictByName)) {
                return (string)$dictByName[$name];
            }
            return $m[0];
        }, $node);

        if ($node !== $orig) $count++;
    }

    private function collectRemainingTokensDeep(mixed $node): array
    {
        $found = [];
        $walk = function ($n) use (&$walk, &$found) {
            if (is_array($n)) { foreach ($n as $v) $walk($v); return; }
            if (!is_string($n) || $n === '') return;
            if (preg_match_all('/\{\{\s*[A-Z0-9_]+\s*\}\}/', $n, $m)) foreach ($m[0] as $tok) $found[] = $tok;
        };
        $walk($node);
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    // ===========================================================
    // PROMPTS
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
            'angle' => $angles[random_int(0, count($angles) - 1)],
            'tone' => $tones[random_int(0, count($tones) - 1)],
            'cta' => $ctas[random_int(0, count($ctas) - 1)],
            'audience' => $audiences[random_int(0, count($audiences) - 1)],
        ];
    }

    private function promptRedactorJson(string $tipo, string $keyword, string $noRepetirTitles, string $noRepetirCorpus, array $brief): string
    {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $cta   = $this->toStr($brief['cta'] ?? '');
        $aud   = $this->toStr($brief['audience'] ?? '');

        return <<<PROMPT
Devuelve SOLO JSON válido (sin markdown). RESPUESTA MINIFICADA.
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
- NO vacíos ni "<p></p>".
- SOLO 1 H1: hero_h1 (no uses <h1>).
- Evita keyword stuffing.
- HTML SOLO: <p>, <strong>, <br>
- EXACTAMENTE 4 features y EXACTAMENTE 9 FAQs.
- clients_subtitle 6–12 palabras
- seo_title 60–65 chars aprox

ESQUEMA:
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

Reglas:
- clients_subtitle 6–12 palabras
- clients_p_html distinto a pack_p_html

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
- clients_subtitle 6–12 palabras
- clients_p_html distinto a pack_p_html
- HTML: <p>, <strong>, <br>

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos:
{$noRepetirCorpus}

JSON a reparar:
{$short}
PROMPT;
    }

    // ===========================================================
    // Anti-repetición / SEO
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

    private function violatesSeoHardRules(array $copy): bool
    {
        $all = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($all) && preg_match('~<\s*/?\s*h1\b~i', $all)) return true;

        $seo = $this->toStr($copy['seo_title'] ?? '');
        if ($seo !== '' && mb_strlen($seo) > 70) return true;

        if (trim($this->toStr($copy['hero_h1'] ?? '')) === '') return true;

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
}
