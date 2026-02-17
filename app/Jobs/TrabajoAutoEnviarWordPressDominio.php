<?php

namespace App\Jobs;

use App\Models\DominiosModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrabajoAutoEnviarWordPressDominio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $idDominio, public bool $forzar = false) {}

    public function handle(): void
    {
        $dom = DominiosModel::where('id_dominio', $this->idDominio)->first();
        if (!$dom) {
            Log::warning("WP JOB: dominio {$this->idDominio} no existe");
            return;
        }

        Log::info("WP JOB START dominio={$this->idDominio} forzar=" . ($this->forzar ? '1' : '0'));

        if (!(int)$dom->wp_auto_activo) {
            Log::info("WP JOB: wp_auto_activo=0 dominio={$this->idDominio}");
            return;
        }

        if (
            !$this->forzar &&
            $dom->wp_siguiente_ejecucion &&
            now()->lt(Carbon::parse($dom->wp_siguiente_ejecucion))
        ) {
            Log::info("WP JOB: aun no toca dominio={$this->idDominio} next={$dom->wp_siguiente_ejecucion}");
            return;
        }

        // ✅ MISMA LÓGICA que tu publicar/programar manual
        $secret = (string) env('WP_WEBHOOK_SECRET');
        if ($secret === '') {
            Log::error("WP JOB: WP_WEBHOOK_SECRET no configurado en .env dominio={$this->idDominio}");
            $dom->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dom, now());
            $dom->save();
            return;
        }

        $limit = max(1, (int)($dom->wp_tareas_por_ejecucion ?? 3));

        $q = DB::table('dominios_contenido_detalles')
            ->where('id_dominio', $this->idDominio)
            ->where('estatus', 'generado')
            ->where(function ($w) {
                // pendientes de WP: no tienen wp_id aún
                $w->whereNull('wp_id')->orWhere('wp_id', 0);
            })
            ->orderBy('id_dominio_contenido_detalle', 'asc')
            ->limit($limit);

        // si modo=programar, evita reprogramar los que ya tienen scheduled_at
        if (($dom->wp_auto_modo ?? 'manual') === 'programar') {
            $q->whereNull('scheduled_at');
        }

        $items = $q->get();

        Log::info("WP JOB: encontrados={$items->count()} dominio={$this->idDominio} modo={$dom->wp_auto_modo} regla={$dom->wp_regla_tipo}");

        if ($items->isEmpty()) {
            $dom->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dom, now());
            $dom->save();
            Log::info("WP JOB: sin contenido, next={$dom->wp_siguiente_ejecucion}");
            return;
        }

        foreach ($items as $it) {
            Log::info("WP JOB ITEM id_detalle={$it->id_dominio_contenido_detalle} title=" . substr((string)($it->title ?? ''), 0, 40));

            // Marcar en proceso
            DB::table('dominios_contenido_detalles')
                ->where('id_dominio_contenido_detalle', $it->id_dominio_contenido_detalle)
                ->update([
                    'estatus'    => 'en_proceso',
                    'error'      => null,
                    'updated_at' => now(),
                ]);

            try {
                $modo = (string)($dom->wp_auto_modo ?? 'manual');
                if (!in_array($modo, ['publicar', 'programar'], true)) {
                    throw new \RuntimeException("Modo WP inválido: {$modo}");
                }

                // si programar: calcula schedule_at según tu política
                $dtUtc = null;
                $scheduleAtUtcWp = null;

                if ($modo === 'programar') {
                    // puedes ajustar esta política:
                    // - si quieres usar regla cada N días, etc. es PARA EJECUCIÓN del job,
                    //   pero el schedule de WP lo seguimos como antes: ahora+10min y escalonado.
                    $scheduleLocal = now()->addMinutes(10);

                    $dtUtc = $scheduleLocal->copy()->setTimezone('UTC');

                    // ✅ WP SAFE: "Y-m-d H:i:s" (igual que tu programar manual)
                    $scheduleAtUtcWp = $dtUtc->format('Y-m-d H:i:s');
                }

                $json = $this->lwsUpsert(
                    dom: $dom,
                    it: $it,
                    secret: $secret,
                    modo: $modo,
                    scheduleAtUtcWp: $scheduleAtUtcWp
                );

                // ✅ OK: aplica exactamente tu lógica
                $wpStatus = (string)($json['status'] ?? '');

                $update = [
                    'wp_id'      => ((int)($json['wp_id'] ?? 0) ?: ($it->wp_id ?? null)),
                    'wp_link'    => (string)($json['link'] ?? ''),
                    'error'      => null,
                    'updated_at' => now(),
                    'fecha_publicado'=> now(),
                ];

                if ($wpStatus === 'future') {
                    $update['estatus'] = 'programado';

                    if (!empty($json['scheduled_gmt'])) {
                        $update['scheduled_at'] = Carbon::parse($json['scheduled_gmt'], 'UTC')
                            ->setTimezone(config('app.timezone'));
                    } else {
                        // fallback: el schedule calculado
                        $update['scheduled_at'] = $dtUtc
                            ? $dtUtc->copy()->setTimezone(config('app.timezone'))
                            : null;
                    }
                } elseif ($wpStatus === 'publish') {
                    $update['estatus'] = 'publicado';
                    $update['scheduled_at'] = null;
                } else {
                    // WP devolvió ok, pero status raro => lo dejamos en generado
                    $update['estatus'] = 'generado';
                    $update['scheduled_at'] = null;
                }

                DB::table('dominios_contenido_detalles')
                    ->where('id_dominio_contenido_detalle', $it->id_dominio_contenido_detalle)
                    ->update($update);

                Log::info("WP OK id_detalle={$it->id_dominio_contenido_detalle} wp_id=" . ($update['wp_id'] ?? 0) . " status={$wpStatus}");

            } catch (\Throwable $e) {
                DB::table('dominios_contenido_detalles')
                    ->where('id_dominio_contenido_detalle', $it->id_dominio_contenido_detalle)
                    ->update([
                        'estatus'    => 'error',
                        'error'      => $e->getMessage(),
                        'updated_at' => now(),
                    ]);

                Log::error("WP FAIL id_detalle={$it->id_dominio_contenido_detalle} msg={$e->getMessage()}");
            }
        }

        $dom->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dom, now());
        $dom->save();

        Log::info("WP JOB END dominio={$this->idDominio} next={$dom->wp_siguiente_ejecucion}");
    }

    /**
     * ✅ Misma lógica de tu publicar/programar manual:
     * - construye payload
     * - firma HMAC
     * - POST /wp-json/lws/v1/upsert y fallback admin-post
     * - valida ok + json
     */
    private function lwsUpsert($dom, $it, string $secret, string $modo, ?string $scheduleAtUtcWp): array
    {
        $wpBase      = rtrim((string)$dom->url, '/');
        $urlRest     = $wpBase . '/wp-json/lws/v1/upsert';
        $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

        $tipoNorm = strtolower(trim((string)$it->tipo));
        $type = ($tipoNorm === 'page') ? 'page' : 'post';

        if (empty($it->contenido_html)) {
            throw new \RuntimeException('contenido_html está vacío (no hay nada que enviar).');
        }

        $templatePath = trim((string)($dom->elementor_template_path ?? ''));
        $useElementor = ($templatePath !== '');

        $canvas = ($type === 'page') ? 'elementor_canvas' : '';

        $contentToSend = $this->normalizarContenidoParaWp(
            contenidoHtml: (string)$it->contenido_html,
            title: (string)($it->title ?: ($it->keyword ?: '')),
            useElementor: $useElementor
        );

        $payload = [
            'type'    => $type,
            'wp_id'   => $it->wp_id ?: null,
            'title'   => $it->title ?: ($it->keyword ?: 'Sin título'),
            'content' => $contentToSend,

            'builder' => $useElementor ? 'elementor' : 'html',

            'wp_page_template' => $useElementor ? $canvas : '',
            'template'         => $useElementor ? $canvas : '',
        ];

        if ($modo === 'programar') {
            $payload['status'] = 'future';
            $payload['schedule_at'] = $scheduleAtUtcWp; // ✅ WP SAFE: "Y-m-d H:i:s"
        } else {
            $payload['status'] = 'publish';
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('No se pudo serializar payload JSON');
        }

        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Timestamp'  => (string)$ts,
            'X-Signature'  => $sig,
        ];

        $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlRest, ['body' => $body]);

        // fallback si REST no existe / bloqueado
        if (in_array($resp->status(), [404, 405], true)) {
            $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlFallback, ['body' => $body]);
        }

        $json = $resp->json();

        if (!$resp->ok() || !is_array($json) || empty($json['ok'])) {
            $msg = is_array($json) ? ($json['message'] ?? 'Error desconocido') : ('HTTP ' . $resp->status());
            throw new \RuntimeException($msg);
        }

        return $json;
    }

    /**
     * ✅ Copia la “higiene” de tu manual:
     * - si no usa Elementor y parece JSON, intenta extraer HTML
     * - asegura que sea HTML
     */
    private function normalizarContenidoParaWp(string $contenidoHtml, string $title, bool $useElementor): string
    {
        $contentToSend = $contenidoHtml;

        if (!$useElementor) {
            $trim = ltrim($contentToSend);
            $looksLikeJson = ($trim !== '' && in_array($trim[0], ['{', '['], true));

            if ($looksLikeJson) {
                $decoded = json_decode($contentToSend, true);
                if (is_array($decoded)) {
                    $candidate = null;

                    $walk = function ($node) use (&$walk, &$candidate) {
                        if ($candidate) return;
                        if (is_array($node)) {
                            foreach ($node as $k => $v) {
                                if (
                                    is_string($k) &&
                                    in_array($k, ['editor', 'content', 'text'], true) &&
                                    is_string($v) &&
                                    str_contains($v, '<')
                                ) {
                                    $candidate = $v;
                                    return;
                                }
                                $walk($v);
                            }
                        }
                    };

                    $walk($decoded);

                    if (is_string($candidate) && trim($candidate) !== '') {
                        $contentToSend = $candidate;
                    } else {
                        $contentToSend = '<div>' . e($title) . '</div>';
                    }
                }
            }

            if (!str_contains($contentToSend, '<')) {
                $contentToSend = '<div>' . e($contentToSend) . '</div>';
            }
        }

        return $contentToSend;
    }

    // =========================================================
    // Próxima ejecución WP (reglas) - igual que antes
    // =========================================================
    private function calcularProximaEjecucionWp($dominio, Carbon $now): Carbon
    {
        $tipo = $dominio->wp_regla_tipo ?? 'manual';

        return match ($tipo) {
            'manual' => $now->copy()->addYears(5),

            'cada_x_minutos' => $now->copy()->addMinutes(
                max(1, (int)($dominio->wp_cada_minutos ?? 60))
            ),

            'cada_n_dias' => ((int)($dominio->wp_excluir_fines_semana ?? 0) === 1)
                ? $this->addBusinessDays($now, max(1, (int)($dominio->wp_cada_dias ?? 1)))
                : $now->copy()->addDays(max(1, (int)($dominio->wp_cada_dias ?? 1))),

            'diario' => $this->proximaDiaria($now, (string)($dominio->wp_hora_del_dia ?? '09:00')),

            'semanal' => $this->proximaSemanal(
                $now,
                (array)($dominio->wp_dias_semana ?? []),
                (string)($dominio->wp_hora_del_dia ?? '09:00')
            ),

            default => $now->copy()->addMinutes(60),
        };
    }

    private function addBusinessDays(Carbon $date, int $days): Carbon
    {
        $d = $date->copy();
        $added = 0;
        while ($added < $days) {
            $d->addDay();
            if ($d->isoWeekday() <= 5) $added++;
        }
        return $d;
    }

    private function proximaDiaria(Carbon $now, string $hora): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        $candidate = $now->copy()->setTime($h, $m, 0);
        if ($candidate->lessThanOrEqualTo($now)) $candidate->addDay();
        return $candidate;
    }

    private function proximaSemanal(Carbon $now, array $diasIso, string $hora): Carbon
    {
        $diasIso = array_values(array_unique(array_map('intval', $diasIso)));
        sort($diasIso);
        if (empty($diasIso)) return $this->proximaDiaria($now, $hora);

        [$h, $m] = array_map('intval', explode(':', $hora));

        for ($add = 0; $add < 7; $add++) {
            $d = $now->copy()->addDays($add);
            if (in_array((int)$d->isoWeekday(), $diasIso, true)) {
                $candidate = $d->copy()->setTime($h, $m, 0);
                if ($candidate->greaterThan($now)) return $candidate;
            }
        }

        $d = $now->copy()->addWeek();
        $first = $diasIso[0];
        while ((int)$d->isoWeekday() !== $first) $d->addDay();
        return $d->setTime($h, $m, 0);
    }
}
