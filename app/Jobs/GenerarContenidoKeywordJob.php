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

    private array $briefContext = [];

    public function __construct(
        public string $idDominio,
        public string $idDominioContenido,
        public string $tipo,
        public string $keyword
    ) {
        // ✅ NUEVO: UUID REAL para que cada dispatch cree un registro nuevo.
        // (Sigue siendo estable en retries porque el job serializa esta propiedad.)
        $this->jobUuid = (string) Str::uuid();
    }

    public function handle(): void
    {
        $registro = null;

        try {
            // ===========================================================
            // 1) Crear registro del job (para retries, reusa por job_uuid)
            // ===========================================================
            $registro = $this->getOrCreateRegistro();
            $this->registroId = (int) $registro->id_dominio_contenido_detalle;

            // Si ya está generado, no regenerar (solo para retries del mismo job)
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
            // 3) Cargar plantilla (dominio/env/default)
            // ===========================================================
            [$tpl, $tplPath] = $this->loadElementorTemplateForDomainWithPath((int) $this->idDominio);

            // ===========================================================
            // 4) Detectar tokens + contexto (title/text vs editor)
            // ===========================================================
            $tokensMeta = $this->collectTokensMeta($tpl);

            if (empty($tokensMeta)) {
                throw new \RuntimeException("NO_RETRY: La plantilla no contiene tokens {{TOKEN}}. Template: {$tplPath}");
            }

            // ===========================================================
            // 5) Brief + historial simple anti-repetición (opcional)
            // ===========================================================
            $brief = $this->creativeBrief($this->keyword);
            $this->briefContext = $brief;

            // ===========================================================
            // 6) Generar valores para TODOS los tokens encontrados
            // ===========================================================
            $values = $this->generateValuesForTemplateTokens($apiKey, $model, $tokensMeta, $brief);

            // ===========================================================
            // 7) Reemplazar tokens
            // ===========================================================
            [$filled, $replacedCount, $remainingTokens] = $this->fillTemplateTokensWithStats($tpl, $values);

            if ($replacedCount < 1) {
                throw new \RuntimeException("NO_RETRY: No se reemplazó ningún token. Template: {$tplPath}");
            }
            if (!empty($remainingTokens)) {
                throw new \RuntimeException("NO_RETRY: Tokens sin reemplazar: " . implode(' | ', array_slice($remainingTokens, 0, 80)));
            }

            // ===========================================================
            // 8) Title + slug
            // ===========================================================
            $title = trim($this->toStr($values['HERO_H1'] ?? $values['SEO_TITLE'] ?? $this->keyword));
            $title = trim(strip_tags($title));
            if ($title === '') $title = $this->keyword;

            $slugBase = Str::slug($title ?: $this->keyword);
            $slug = $slugBase . '-' . $registro->id_dominio_contenido_detalle;

            // ===========================================================
            // 9) Guardar
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

    // ===========================================================
    // Registro (por job_uuid). Cada dispatch nuevo => uuid nuevo.
    // ===========================================================
    private function getOrCreateRegistro(): Dominios_Contenido_DetallesModel
    {
        $existing = Dominios_Contenido_DetallesModel::where('job_uuid', $this->jobUuid)->first();
        if ($existing) return $existing;

        return Dominios_Contenido_DetallesModel::create([
            'job_uuid'             => $this->jobUuid,
            'id_dominio_contenido' => (int) $this->idDominioContenido,
            'id_dominio'           => (int) $this->idDominio,
            'tipo'                 => $this->tipo,
            'keyword'              => $this->keyword,
            'estatus'              => 'en_proceso',
            'modelo'               => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ]);
    }

    // ===========================================================
    // TEMPLATE LOADER (dominio -> env -> default)
    // ===========================================================
    private function loadElementorTemplateForDomainWithPath(int $idDominio): array
    {
        $dominio = DominiosModel::where('id_dominio', $idDominio)->first();
        if (!$dominio) throw new \RuntimeException("NO_RETRY: Dominio no encontrado (id={$idDominio})");

        $templateRel = trim((string)($dominio->elementor_template_path ?? ''));

        if ($templateRel === '') $templateRel = trim((string) env('ELEMENTOR_TEMPLATE_PATH', ''));

        // ✅ Default recomendado para estandarizar (ajústalo si tu ruta cambia)
        if ($templateRel === '') $templateRel = 'elementor/elementor-179-2025-12-27.json';

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
    // Token scanner con contexto
    // - tokens en settings.editor => html_fragment
    // - tokens en settings.title/text => plain
    // ===========================================================
    private function collectTokensMeta(array $tpl): array
    {
        $meta = []; // TOKEN => ['type' => 'plain'|'editor', 'contexts'=>[]]

        $walk = function ($node) use (&$walk, &$meta) {
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    // Si estamos en settings, el key importa (editor/title/text)
                    if (is_string($k) && in_array($k, ['editor','title','text'], true) && is_string($v)) {
                        if (preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $v, $m)) {
                            foreach (($m[1] ?? []) as $tok) {
                                $tok = (string) $tok;
                                if ($tok === '') continue;

                                $type = ($k === 'editor') ? 'editor' : 'plain';

                                if (!isset($meta[$tok])) {
                                    $meta[$tok] = ['type' => $type, 'contexts' => [$k]];
                                } else {
                                    // Si aparece en editor al menos una vez, gana editor
                                    if ($type === 'editor') $meta[$tok]['type'] = 'editor';
                                    $meta[$tok]['contexts'][] = $k;
                                    $meta[$tok]['contexts'] = array_values(array_unique($meta[$tok]['contexts']));
                                }
                            }
                        }
                    }

                    $walk($v);
                }
                return;
            }
        };

        $walk($tpl);

        ksort($meta);
        return $meta;
    }

    // ===========================================================
    // Generación IA para tokens
    // ===========================================================
    private function generateValuesForTemplateTokens(string $apiKey, string $model, array $tokensMeta, array $brief): array
    {
        $kw    = $this->shortKw();
        $tipo  = trim((string)$this->tipo);

        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $cta   = $this->toStr($brief['cta'] ?? '');
        $aud   = $this->toStr($brief['audience'] ?? '');

        // Skeleton exacto: todas las keys obligatorias (las que estén en la plantilla)
        $skeleton = [];
        foreach ($tokensMeta as $tok => $info) {
            $skeleton[$tok] = '';
        }

        $schemaJson = json_encode($skeleton, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $schemaJson = mb_substr((string)$schemaJson, 0, 12000);

        // Instrucciones por tipo
        $plainKeys  = [];
        $editorKeys = [];
        foreach ($tokensMeta as $tok => $info) {
            if (($info['type'] ?? 'plain') === 'editor') $editorKeys[] = $tok;
            else $plainKeys[] = $tok;
        }

        $plainList  = implode(', ', array_slice($plainKeys, 0, 200));
        $editorList = implode(', ', array_slice($editorKeys, 0, 200));

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido (sin markdown). RESPUESTA MINIFICADA.
Debes devolver EXACTAMENTE las mismas keys del ESQUEMA (sin agregar ni quitar).
PROHIBIDO keys vacías como "".
PROHIBIDO valores vacíos.

Contexto:
- Keyword: {$kw}
- Tipo: {$tipo}

BRIEF:
- Ángulo: {$angle}
- Tono: {$tone}
- Público: {$aud}
- CTA: {$cta}

REGLAS:
- Para keys "editor" (texto largo): usa SOLO texto + opcional <strong> y <br>. NO uses <h1>/<h2>/<h3>. Evita <p> porque la plantilla a veces ya lo envuelve.
- Para keys "plain" (títulos/botones): SOLO texto plano (sin etiquetas).
- Evita repetición: cada SECTION_X_TITLE y SECTION_X_P deben ser distintos (no copies).
- Longitud sugerida:
  - HERO_H1: 6–12 palabras
  - BTN_*: 1–3 palabras
  - SECTION_*_TITLE: 2–6 palabras
  - SECTION_*_P: 1–2 frases claras

LISTA editor:
{$editorList}

LISTA plain:
{$plainList}

ESQUEMA EXACTO (rellena valores):
{$schemaJson}
PROMPT;

        $raw = $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3600, temperature: 0.75, topP: 0.9, jsonMode: true);

        $arr = $this->safeParseOrRepairGeneric($apiKey, $model, $raw, array_keys($skeleton), $brief);

        // Normalizar + fallback por token
        $arr = $this->normalizeTokenValues($arr, $tokensMeta, $kw);

        return $arr;
    }

    // ===========================================================
    // DeepSeek request
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
    // Parse/Repair genérico
    // ===========================================================
    private function safeParseOrRepairGeneric(string $apiKey, string $model, string $raw, array $requiredKeys, array $brief): array
    {
        try {
            return $this->parseJsonStrict($raw);
        } catch (\Throwable $e) {
            $loose = $this->parseJsonLoosePairs($raw);
            if (!empty($loose)) return $loose;

            $fixed = $this->repairJsonGeneric($apiKey, $model, $raw, $requiredKeys, $brief);
            try {
                return $this->parseJsonStrict($fixed);
            } catch (\Throwable $e2) {
                $loose2 = $this->parseJsonLoosePairs($fixed);
                if (!empty($loose2)) return $loose2;
            }

            $snip = mb_substr((string)$raw, 0, 700);
            throw new \RuntimeException("DeepSeek no devolvió JSON válido. Snippet: " . $snip);
        }
    }

    private function repairJsonGeneric(string $apiKey, string $model, string $broken, array $requiredKeys, array $brief): string
    {
        $angle = $this->toStr($brief['angle'] ?? '');
        $tone  = $this->toStr($brief['tone'] ?? '');
        $broken = mb_substr(trim((string)$broken), 0, 9000);

        $keysList = implode(', ', array_slice($requiredKeys, 0, 250));

        $prompt = <<<PROMPT
Devuelve SOLO JSON válido. RESPUESTA MINIFICADA.
Tienes que devolver un objeto JSON con EXACTAMENTE estas keys (sin agregar ni quitar):
{$keysList}

Reglas:
- PROHIBIDO key vacía "".
- PROHIBIDO valores vacíos: llena todo.
- Si un valor no aplica, inventa uno razonable (sin claims falsos).
- Para textos largos, usa SOLO texto + opcional <strong> y <br>. NO uses <p>.

Estilo:
- Ángulo: {$angle}
- Tono: {$tone}

JSON roto:
{$broken}
PROMPT;

        return $this->deepseekText($apiKey, $model, $prompt, maxTokens: 3600, temperature: 0.20, topP: 0.90, jsonMode: true);
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
            throw new \RuntimeException('JSON inválido');
        }

        // limpiar keys vacías si vinieran
        if (array_key_exists('', $data)) unset($data['']);

        return $data;
    }

    // Loose: extrae pares "KEY":"VAL" aunque falten llaves perfectas
    private function parseJsonLoosePairs(string $raw): array
    {
        $raw = trim((string)$raw);
        $raw = preg_replace('~^```(?:json)?\s*~i', '', $raw);
        $raw = preg_replace('~\s*```$~', '', $raw);
        $raw = trim($raw);

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

        return $out;
    }

    // ===========================================================
    // Normalización + fallbacks por token
    // ===========================================================
    private function normalizeTokenValues(array $values, array $tokensMeta, string $kw): array
    {
        // Asegurar todas las keys
        foreach ($tokensMeta as $tok => $info) {
            if (!array_key_exists($tok, $values)) $values[$tok] = '';
        }

        // Normalizar según tipo
        foreach ($tokensMeta as $tok => $info) {
            $type = ($info['type'] ?? 'plain');

            if ($type === 'editor') {
                $v = $this->normalizeEditorFragment($this->toStr($values[$tok] ?? ''));
                if ($this->isBlankHtml($v)) $v = $this->fallbackForToken($tok, $type, $kw);
                $values[$tok] = $v;
            } else {
                $v = trim(strip_tags($this->toStr($values[$tok] ?? '')));
                $v = preg_replace('~\s+~u', ' ', (string)$v);
                $v = trim((string)$v);
                if ($v === '') $v = $this->fallbackForToken($tok, $type, $kw);
                // recortes suaves para no reventar UI
                if (str_starts_with($tok, 'BTN_') && mb_strlen($v) > 28) $v = mb_substr($v, 0, 28);
                if (str_contains($tok, 'H1') && mb_strlen($v) > 95) $v = mb_substr($v, 0, 95);
                if (str_contains($tok, 'TITLE') && mb_strlen($v) > 80) $v = mb_substr($v, 0, 80);
                $values[$tok] = trim((string)$v);
            }
        }

        // Anti “todo igual”: si SECTION_X_P quedó repetido, forzar fallback distinto
        $seen = [];
        foreach ($tokensMeta as $tok => $info) {
            if (!str_starts_with($tok, 'SECTION_')) continue;
            if (!str_ends_with($tok, '_P')) continue;

            $v = $values[$tok] ?? '';
            $key = mb_strtolower(trim(strip_tags((string)$v)));
            if ($key === '') continue;

            if (isset($seen[$key])) {
                $values[$tok] = $this->fallbackForToken($tok, ($info['type'] ?? 'plain'), $kw, forceUnique: true);
            } else {
                $seen[$key] = true;
            }
        }

        return $values;
    }

    private function fallbackForToken(string $tok, string $type, string $kw, bool $forceUnique = false): string
    {
        // SECTION_X_TITLE / SECTION_X_P
        if (preg_match('~^SECTION_(\d+)_TITLE$~', $tok, $m)) {
            $i = (int)($m[1] ?? 0);
            $base = [
                "Cómo trabajamos",
                "Entregables incluidos",
                "Proceso paso a paso",
                "Qué mejora en tu página",
                "Errores comunes que evitamos",
                "Para quién es ideal",
                "Resultados y enfoque",
                "Implementación y soporte",
                "Optimización y consistencia",
            ];
            $pick = $base[($i - 1) % count($base)] ?? "Sección {$i}";
            return $forceUnique ? "{$pick} ({$i})" : "{$pick}";
        }

        if (preg_match('~^SECTION_(\d+)_P$~', $tok, $m)) {
            $i = (int)($m[1] ?? 0);
            $variants = [
                "Te dejamos una estructura clara y textos listos para publicar, sin relleno ni promesas irreales.",
                "Ordenamos el mensaje para que sea fácil de escanear y lleve a la acción con naturalidad.",
                "Definimos beneficios, objeciones y CTA para que la página tenga intención y coherencia.",
                "Adaptamos el contenido al contexto de {$kw} con un enfoque directo y entendible.",
                "Priorizamos claridad: secciones con propósito, frases concretas y pasos claros.",
            ];
            $v = $variants[($i - 1) % count($variants)] ?? $variants[0];
            if ($forceUnique) $v .= " (bloque {$i})";
            // Para editor: fragmento sin <p>
            return $v;
        }

        // HERO / PACK / FAQ / CTA / BTN genéricos
        if ($tok === 'HERO_H1') return "{$kw} con estructura y copy que convierten";
        if ($tok === 'HERO_KICKER') return "Para equipos que valoran rapidez y claridad";
        if ($tok === 'PACK_H2') return "Nuestro proceso y entregables";
        if ($tok === 'FAQ_TITLE') return "Preguntas frecuentes";
        if ($tok === 'FINAL_CTA') return "¿Listo para avanzar con una entrega clara?";
        if ($tok === 'BTN_PRESUPUESTO') return "Solicitar presupuesto";
        if ($tok === 'BTN_REUNION') return "Agendar llamada";
        if ($tok === 'BTN_KITDIGITAL') return "Ver información";
        if ($tok === 'KITDIGITAL_BOLD') return "Opciones disponibles";
        if ($tok === 'CLIENTS_LABEL') return "Casos";
        if ($tok === 'CLIENTS_SUBTITLE') return "Claridad, orden y enfoque";
        if ($tok === 'PRICE_H2') return "Precio claro y entregables definidos";
        if ($tok === 'KIT_H1') return "Kit y bloques listos para publicar";
        if ($tok === 'PROJECTS_TITLE') return "Cómo lo implementamos";

        // fallback final
        return ($type === 'editor')
            ? "Contenido preparado para {$kw}, listo para adaptar y publicar."
            : "Contenido para {$kw}";
    }

    private function normalizeEditorFragment(string $html): string
    {
        // permitimos SOLO <strong> y <br>. (y si vienen <p>, los quitamos)
        $clean = strip_tags($html, '<strong><br><p>');
        $clean = trim((string)$clean);

        // Si viene envuelto en un único <p>...</p>, quitarlo para evitar doble <p> cuando plantilla ya envuelve.
        if (preg_match('~^\s*<p>\s*(.*?)\s*</p>\s*$~is', $clean, $m)) {
            $clean = trim((string)($m[1] ?? ''));
        }

        // compact whitespace
        $clean = preg_replace('~\s+~u', ' ', (string)$clean);
        $clean = str_replace(["<br />", "<br/>"], "<br>", (string)$clean);
        $clean = trim((string)$clean);

        return (string)$clean;
    }

    private function isBlankHtml(string $html): bool
    {
        $txt = trim(preg_replace('~\s+~u', ' ', strip_tags((string)$html)));
        return $txt === '';
    }

    // ===========================================================
    // Reemplazo tokens en plantilla
    // ===========================================================
    private function fillTemplateTokensWithStats(array $tpl, array $values): array
    {
        $dict = [];
        foreach ($values as $k => $v) {
            $dict['{{' . $k . '}}'] = (string)$v;
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
            if (preg_match_all('/\{\{[A-Z0-9_]+\}\}/', $n, $m)) {
                foreach ($m[0] as $tok) $found[] = $tok;
            }
        };
        $walk($node);
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    // ===========================================================
    // BRIEF
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
    private function toStr(mixed $v): string
    {
        if ($v === null) return '';
        if (is_string($v)) return $v;
        if (is_int($v) || is_float($v)) return (string)$v;
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v) || is_object($v)) {
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
}
