<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

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
        // ✅ NUEVO por dispatch (ya no reaprovecha registros antiguos)
        $this->jobUuid = (string) Str::uuid();
    }

    public function handle(): void
    {
        $registro = null;

        try {
            // ===========================================================
            // 1) Registro
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
            if ($apiKey === '') {
                throw new \RuntimeException('NO_RETRY: DEEPSEEK_API_KEY no configurado');
            }

            // ===========================================================
            // 3) Historial anti-repetición (suave)
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

            $noRepetirTitles = implode(' | ', array_slice(array_filter($usedTitles), 0, 10));
            $noRepetirCorpus = $this->compactHistory($usedCorpus, 2000);

            // ===========================================================
            // 4) Cargar plantilla + tokens
            // ===========================================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int)$this->idDominio);

            $tokenMeta = $this->extractTokensAndContexts($tpl); // tokenKey => ['is_html'=>bool]
            $tokenKeys = array_keys($tokenMeta);

            if (count($tokenKeys) < 1) {
                throw new \RuntimeException("NO_RETRY: La plantilla no contiene tokens {{...}}. Template: {$tplPath}");
            }

            // ===========================================================
            // 5) Generar valores para tokens (IA)
            // ===========================================================
            $brief = $this->creativeBrief($this->keyword);

            $values = $this->generateValuesForTemplateTokens(
                apiKey: $apiKey,
                model: $model,
                keyword: $this->keyword,
                tipo: $this->tipo,
                tokenMeta: $tokenMeta,
                noRepetirTitles: $noRepetirTitles,
                noRepetirCorpus: $noRepetirCorpus,
                brief: $brief
            );

            // ===========================================================
            // 6) Reemplazar tokens
            // ===========================================================
            $dict = [];
            foreach ($values as $k => $v) {
                $dict['{{' . $k . '}}'] = $v;
            }

            $replacedCount = 0;
            $this->replaceTokensDeep($tpl, $dict, $replacedCount);
            $remaining = $this->collectRemainingTokensDeep($tpl);

            Log::info('GenerarContenidoKeywordJob tokens', [
                'job_uuid' => $this->jobUuid,
                'registro' => $this->registroId,
                'template' => $tplPath,
                'tokens_in_template' => count($tokenKeys),
                'nodes_replaced' => $replacedCount,
                'remaining_tokens_count' => count($remaining),
            ]);

            if ($replacedCount < 1) {
                throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}");
            }
            if (!empty($remaining)) {
                throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remaining, 0, 120)));
            }

            // ===========================================================
            // 7) Title + slug
            // ===========================================================
            $titleCandidate = $values['SEO_TITLE']
                ?? $values['seo_title']
                ?? $values['HERO_H1']
                ?? $values['hero_h1']
                ?? $this->keyword;

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
                'contenido_html' => json_encode($tpl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    private function getOrCreateRegistro(): Dominios_Contenido_DetallesModel
    {
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
        if (!is_file($templatePath)) throw new \RuntimeException("NO_RETRY: No existe el template en disco: {$templatePath}");

        $raw = (string) file_get_contents($templatePath);
        $tpl = json_decode($raw, true);

        if (!is_array($tpl) || !isset($tpl['content']) || !is_array($tpl['content'])) {
            throw new \RuntimeException('NO_RETRY: Template Elementor inválido: debe contener "content" (array).');
        }

        return [$tpl, $templatePath];
    }

    // ===========================================================
    // Tokens + contexto HTML
    // ===========================================================
    private function extractTokensAndContexts(array $tpl): array
    {
        $meta = []; // tokenKey => ['is_html'=>bool]

        $walk = function ($node, $parentKey = '') use (&$walk, &$meta) {
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    $walk($v, is_string($k) ? $k : $parentKey);
                }
                return;
            }

            if (!is_string($node) || $node === '') return;

            if (!preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $node, $m)) return;

            foreach ($m[1] as $tokenKey) {
                $key = (string)$tokenKey;

                // editor/html/content suelen ser HTML
                $isHtml = in_array($parentKey, ['editor', 'html', 'content', 'description', 'p_html', 'a_html'], true);

                // botones suelen ir en "text" (no HTML)
                if ($parentKey === 'text') $isHtml = false;

                if (!isset($meta[$key])) {
                    $meta[$key] = ['is_html' => $isHtml];
                } else {
                    $meta[$key]['is_html'] = $meta[$key]['is_html'] || $isHtml;
                }
            }
        };

        $walk($tpl, '');

        ksort($meta);
        return $meta;
    }

    // ===========================================================
    // IA: Generar exacto para tokens
    // ===========================================================
    private function generateValuesForTemplateTokens(
        string $apiKey,
        string $model,
        string $keyword,
        string $tipo,
        array $tokenMeta,
        string $noRepetirTitles,
        string $noRepetirCorpus,
        array $brief
    ): array {
        $angle = (string)($brief['angle'] ?? '');
        $tone  = (string)($brief['tone'] ?? '');
        $cta   = (string)($brief['cta'] ?? '');
        $aud   = (string)($brief['audience'] ?? '');

        $plainKeys = [];
        $htmlKeys  = [];
        foreach ($tokenMeta as $k => $m) {
            if (!empty($m['is_html'])) $htmlKeys[] = $k;
            else $plainKeys[] = $k;
        }

        $noRepetirTitles = mb_substr($noRepetirTitles, 0, 800);
        $noRepetirCorpus = mb_substr($noRepetirCorpus, 0, 1200);

        $plainList = implode(', ', $plainKeys);
        $htmlList  = implode(', ', $htmlKeys);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. Sin comentarios.
⚠️ OBLIGATORIO: JSON PLANO (un solo objeto). NO uses "PLAIN_KEYS" ni "HTML_KEYS".
⚠️ PROHIBIDO: keys vacías, keys nuevas o keys que no estén en la lista.

Keyword: {$keyword}
Tipo: {$tipo}

BRIEF:
Ángulo: {$angle}
Tono: {$tone}
Público: {$aud}
CTA: {$cta}

NO repetir títulos:
{$noRepetirTitles}

NO repetir textos/ideas:
{$noRepetirCorpus}

Reglas:
- No dejar valores vacíos.
- No keyword stuffing (no repitas "{$keyword}" en todos los títulos).
- Español.
- Keys HTML: SOLO <p>, <strong>, <br> y SIEMPRE envolver en <p>...</p>.

DEVUELVE SOLO estas keys (y nada más):
PLAIN_KEYS: {$plainList}
HTML_KEYS: {$htmlList}

Ejemplo de FORMA (no copies contenido):
{"HERO_H1":"...","BTN_PRESUPUESTO":"...","SECTION_1_P":"<p>...</p>"}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3800, temperature: 0.85, topP: 0.9, jsonMode: true);
        $arr = $this->parseJsonStrict($raw); // robusto

        $out = [];
        foreach ($tokenMeta as $k => $m) {
            $val = $arr[$k] ?? '';

            if (!empty($m['is_html'])) {
                $val = $this->keepAllowedInlineHtml($this->toStr($val));
                if ($this->isBlankHtml($val)) {
                    $val = "<p>" . htmlspecialchars($this->fallbackTextFor($k, $keyword), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</p>";
                }
            } else {
                $val = trim(strip_tags($this->toStr($val)));
                if ($val === '') {
                    $val = $this->fallbackTextFor($k, $keyword);
                }
            }

            $out[$k] = $val;
        }

        if (isset($out['HERO_H1']) && trim($out['HERO_H1']) === '') {
            $out['HERO_H1'] = $this->fallbackTextFor('HERO_H1', $keyword);
        }

        return $out;
    }

    private function fallbackTextFor(string $tokenKey, string $keyword): string
    {
        $kw = trim($keyword) !== '' ? trim($keyword) : 'tu servicio';

        // SECTION_X_TITLE
        if (preg_match('/^SECTION_(\d+)_TITLE$/', $tokenKey, $mm)) {
            $i = (int)$mm[1];
            $topics = [
                1 => "Qué incluye el servicio",
                2 => "Cómo trabajamos paso a paso",
                3 => "Entregables y tiempos",
                4 => "Qué mejora en la página",
                5 => "Errores comunes que evitamos",
                6 => "Para quién es ideal",
                7 => "Preguntas típicas antes de empezar",
                8 => "SEO sin relleno",
                9 => "Conversión y CTA",
                10 => "Estrategia y enfoque",
                11 => "Contenido y estructura",
                12 => "Diseño orientado a acción",
                13 => "Optimización continua",
                14 => "Checklist de publicación",
                15 => "Revisión y ajustes",
                16 => "Soporte y acompañamiento",
                17 => "Casos y ejemplos",
                18 => "Medición y seguimiento",
                19 => "Siguientes pasos",
                20 => "Alcance del proyecto",
                21 => "Requisitos para iniciar",
                22 => "Preguntas frecuentes",
                23 => "Bloque adicional",
                24 => "Bloque adicional",
                25 => "Bloque adicional",
                26 => "Bloque adicional",
            ];
            $base = $topics[$i] ?? ("Bloque " . $i);
            return "{$base} para {$kw}";
        }

        // ✅ SECTION_X_P (contenido de sección)
        if (preg_match('/^SECTION_(\d+)_P$/', $tokenKey, $mm)) {
            $i = (int)$mm[1];
            $base = [
                1 => "Te dejamos una estructura clara con secciones listas para publicar y ajustar al contexto.",
                2 => "Trabajamos por bloques: mensaje, beneficios, objeciones y CTA, manteniendo coherencia en todo.",
                3 => "Definimos entregables y plazos para que sepas exactamente qué se publica y cuándo.",
                4 => "Mejoras típicas: claridad del mensaje, orden visual y llamadas a la acción más consistentes.",
                5 => "Evitamos relleno, repetición y promesas vagas; cada bloque tiene un objetivo real.",
                6 => "Ideal si necesitas una página que explique bien tu oferta y convierta sin complicarte.",
                7 => "Respondemos dudas clave para reducir fricción y facilitar la decisión.",
                8 => "SEO natural: intención + semántica sin repetir keywords como robot.",
                9 => "Incluimos CTA y microcopy para guiar al usuario a la acción.",
                10 => "Alineamos propuesta, público y ángulo para que el contenido tenga dirección.",
                11 => "El contenido se diseña para escaneo: titulares claros y párrafos cortos.",
                12 => "Diseño y texto se apoyan: jerarquía, ritmo y foco en lo importante.",
                13 => "Dejamos base para iterar: ajustar secciones según resultados reales.",
                14 => "Checklist final para publicar sin errores: SEO básico, enlaces, CTA y legibilidad.",
                15 => "Incluimos una revisión de coherencia: tono, claridad y consistencia.",
                16 => "Si aplica, te guiamos con pasos claros para que no se quede a medias.",
                17 => "Ejemplos/variantes para adaptar a tu caso sin duplicar contenido.",
                18 => "Recomendaciones de medición para saber qué funciona y qué ajustar.",
                19 => "Siguiente paso claro: llamada, presupuesto o diagnóstico según el caso.",
                20 => "Alcance definido para evitar expectativas irreales y entregas infinitas.",
                21 => "Qué necesitamos de ti: oferta, público, referencias y 2–3 datos clave.",
                22 => "Preguntas frecuentes enfocadas en objeciones reales y decisiones.",
                23 => "Bloque extra adaptable según tu sector y prioridades.",
                24 => "Bloque extra adaptable según tu sector y prioridades.",
                25 => "Bloque extra adaptable según tu sector y prioridades.",
                26 => "Bloque extra adaptable según tu sector y prioridades.",
            ];
            return $base[$i] ?? "Contenido breve y adaptable para {$kw}, pensado para claridad y conversión.";
        }

        if (str_starts_with($tokenKey, 'BTN_')) return "Solicitar información";

        if ($tokenKey === 'HERO_H1') return "{$kw} con estructura y copy que convierten";
        if ($tokenKey === 'HERO_KICKER') return "Mensaje claro, ejecución rápida";

        return "Contenido para {$kw}";
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

    // ✅ Parser robusto (aplana si DeepSeek inventa grupos)
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
            $snip = mb_substr($raw, 0, 900);
            throw new \RuntimeException('DeepSeek no devolvió JSON válido. Snippet: ' . $snip);
        }

        $flat = [];
        if (isset($data['PLAIN_KEYS']) && is_array($data['PLAIN_KEYS'])) {
            foreach ($data['PLAIN_KEYS'] as $k => $v) $flat[$k] = $v;
        }
        if (isset($data['HTML_KEYS']) && is_array($data['HTML_KEYS'])) {
            foreach ($data['HTML_KEYS'] as $k => $v) $flat[$k] = $v;
        }
        if (!empty($flat)) $data = $flat;

        $clean = [];
        foreach ($data as $k => $v) {
            if (!is_string($k)) continue;
            $k2 = trim($k);
            if ($k2 === '') continue;
            $clean[$k2] = $v;
        }

        return $clean;
    }

    // ===========================================================
    // Reemplazo tokens
    // ===========================================================
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
    // Brief
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
        foreach ($arr as $k => $v) $parts[] = strip_tags($this->toStr($v));
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
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($j) ? $j : '';
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
        return trim((string)$clean);
    }
}
