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
        // ✅ NUEVO por dispatch (evita “reusar” registros viejos)
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
            $noRepetirCorpus = $this->compactHistory($usedCorpus, 1600);

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
            // 5) Generar valores (en LOTES para evitar JSON truncado)
            // ===========================================================
            $brief = $this->creativeBrief($this->keyword);

            $values = $this->generateValuesForTemplateTokensChunked(
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
            // 6) Reemplazar tokens en el JSON Elementor
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

    // ===========================================================
    // Registro
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
    // Token extractor + contexto HTML
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

                // editor/html/content -> HTML
                $isHtml = in_array($parentKey, ['editor', 'html', 'content', 'description'], true);
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
    // ✅ IA en LOTES (evita truncado)
    // ===========================================================
    private function generateValuesForTemplateTokensChunked(
        string $apiKey,
        string $model,
        string $keyword,
        string $tipo,
        array $tokenMeta,
        string $noRepetirTitles,
        string $noRepetirCorpus,
        array $brief
    ): array {
        $keys = array_keys($tokenMeta);

        // Lotes chicos para evitar que DeepSeek “corte” el JSON
        $chunks = array_chunk($keys, 12);

        $all = [];
        $chunkIndex = 0;

        foreach ($chunks as $chunkKeys) {
            $chunkIndex++;

            $chunkMeta = [];
            foreach ($chunkKeys as $k) $chunkMeta[$k] = $tokenMeta[$k];

            // Solo el primer chunk se lleva el historial (para no inflar prompts)
            $titles = ($chunkIndex === 1) ? $noRepetirTitles : '';
            $corpus = ($chunkIndex === 1) ? $noRepetirCorpus : '';

            $partial = $this->generateValuesForTokenChunk(
                apiKey: $apiKey,
                model: $model,
                keyword: $keyword,
                tipo: $tipo,
                tokenMetaChunk: $chunkMeta,
                noRepetirTitles: $titles,
                noRepetirCorpus: $corpus,
                brief: $brief
            );

            foreach ($partial as $k => $v) $all[$k] = $v;
        }

        // Asegurar que no falte nada: fallback final
        foreach ($tokenMeta as $k => $m) {
            if (!array_key_exists($k, $all)) {
                $all[$k] = !empty($m['is_html'])
                    ? $this->ensureHtmlP($this->fallbackTextFor($k, $keyword))
                    : $this->fallbackTextFor($k, $keyword);
            }
        }

        return $all;
    }

    private function generateValuesForTokenChunk(
        string $apiKey,
        string $model,
        string $keyword,
        string $tipo,
        array $tokenMetaChunk,
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
        foreach ($tokenMetaChunk as $k => $m) {
            if (!empty($m['is_html'])) $htmlKeys[] = $k;
            else $plainKeys[] = $k;
        }

        $plainList = implode(', ', $plainKeys);
        $htmlList  = implode(', ', $htmlKeys);

        $titles = mb_substr((string)$noRepetirTitles, 0, 600);
        $corpus = mb_substr((string)$noRepetirCorpus, 0, 1000);

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. Sin markdown. Sin comentarios.
⚠️ OBLIGATORIO: JSON PLANO (un solo objeto).
⚠️ PROHIBIDO: keys vacías, keys nuevas o keys fuera de la lista.

Keyword: {$keyword}
Tipo: {$tipo}

BRIEF:
Ángulo: {$angle}
Tono: {$tone}
Público: {$aud}
CTA: {$cta}

NO repetir títulos:
{$titles}

NO repetir ideas:
{$corpus}

Reglas:
- Español.
- No dejar valores vacíos.
- No keyword stuffing.
- Keys HTML: SOLO <p>, <strong>, <br> y SIEMPRE en <p>...</p>.
- Keys texto: sin HTML.

DEVUELVE SOLO estas keys:
PLAIN: {$plainList}
HTML: {$htmlList}

Ejemplo:
{"HERO_H1":"...","SECTION_1_P":"<p>...</p>"}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 2400, temperature: 0.80, topP: 0.9, jsonMode: true);

        // Parser: strict -> loose -> fallback (nunca rompe)
        $arr = [];
        try {
            $arr = $this->parseJsonStrict($raw);
        } catch (\Throwable $e) {
            $arr = $this->parseJsonLoose($raw);
            Log::warning('DeepSeek JSON invalid; using loose parse', [
                'job_uuid' => $this->jobUuid,
                'err' => $e->getMessage(),
                'snippet' => mb_substr($raw, 0, 900),
            ]);
        }

        $out = [];
        foreach ($tokenMetaChunk as $k => $m) {
            $val = $arr[$k] ?? '';

            if (!empty($m['is_html'])) {
                $val = $this->keepAllowedInlineHtml($this->toStr($val));
                if ($this->isBlankHtml($val)) {
                    $val = $this->ensureHtmlP($this->fallbackTextFor($k, $keyword));
                } else {
                    $val = $this->ensureHtmlP($val);
                }
            } else {
                $val = trim(strip_tags($this->toStr($val)));
                if ($val === '') {
                    $val = $this->fallbackTextFor($k, $keyword);
                }
            }

            $out[$k] = $val;
        }

        return $out;
    }

    private function ensureHtmlP(string $htmlOrText): string
    {
        $s = trim((string)$htmlOrText);
        $s = $this->keepAllowedInlineHtml($s);
        if ($s === '' || $this->isBlankHtml($s)) return '<p>Texto pendiente.</p>';
        if (!preg_match('~<\s*p\b~i', $s)) {
            return '<p>' . htmlspecialchars(strip_tags($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        return $s;
    }

    // ===========================================================
    // DeepSeek HTTP
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
    // JSON Parsers
    // ===========================================================
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

        // eliminar keys inválidas
        $clean = [];
        foreach ($data as $k => $v) {
            if (!is_string($k)) continue;
            $k2 = trim($k);
            if ($k2 === '') continue;
            $clean[$k2] = $v;
        }

        return $clean;
    }

    // Parser “loose” para rescatar pares "KEY":"VALUE" aunque el JSON esté truncado
    private function parseJsonLoose(string $raw): array
    {
        $raw = trim((string)$raw);
        $raw = preg_replace('~^```(?:json)?\s*~i', '', $raw);
        $raw = preg_replace('~\s*```$~', '', $raw);
        $raw = trim($raw);

        $out = [];

        // matches: "KEY": "value"
        if (preg_match_all('~"([A-Z0-9_]+)"\s*:\s*"((?:\\\\.|[^"\\\\])*)"~u', $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = $row[1] ?? '';
                $inner = $row[2] ?? '';
                if ($k === '') continue;
                $decoded = json_decode('"' . $inner . '"', true);
                $out[$k] = is_string($decoded) ? $decoded : stripcslashes($inner);
            }
        }

        return $out;
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
    // Brief + fallbacks
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

    private function fallbackTextFor(string $tokenKey, string $keyword): string
    {
        $kw = trim($keyword) !== '' ? trim($keyword) : 'tu servicio';

        if (preg_match('/^SECTION_(\d+)_TITLE$/', $tokenKey, $mm)) {
            $i = (int)$mm[1];
            return "Sección {$i} para {$kw}";
        }

        if (preg_match('/^SECTION_(\d+)_P$/', $tokenKey, $mm)) {
            $i = (int)$mm[1];
            return "Contenido breve y adaptable para {$kw} (sección {$i}), orientado a claridad y conversión.";
        }

        if (str_starts_with($tokenKey, 'BTN_')) return "Solicitar información";
        if ($tokenKey === 'HERO_H1') return "{$kw} con estructura y copy que convierten";
        if ($tokenKey === 'HERO_KICKER') return "Mensaje claro, ejecución rápida";

        return "Contenido para {$kw}";
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
