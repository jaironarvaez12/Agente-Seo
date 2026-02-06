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

        // ✅ Secret para HMAC (AJUSTA el nombre si tu columna se llama distinto)
        $secret = (string)($dom->wp_secret ?? '');
        if ($secret === '') {
            Log::error("WP JOB: falta wp_secret dominio={$this->idDominio}");
            $dom->wp_siguiente_ejecucion = $this->calcularProximaEjecucionWp($dom, now());
            $dom->save();
            return;
        }

        $limit = max(1, (int)($dom->wp_tareas_por_ejecucion ?? 3));

        $q = DB::table('dominios_contenido_detalles')
            ->where('id_dominio', $this->idDominio)
            ->where('estatus', 'generado')
            ->where(function ($w) {
                // pendientes: aún no tienen wp_id
                $w->whereNull('wp_id')->orWhere('wp_id', 0);
            })
            ->orderBy('id_dominio_contenido_detalle', 'asc')
            ->limit($limit);

        // Si vamos a programar, evita reprogramar los que ya tienen scheduled_at
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
            try {
                $resp = $this->upsertToWp($dom, $it, $secret);

                // ✅ SOLO si ok=true aplicamos cambios
                $wpId = (int)($resp['wp_id'] ?? 0);
                $link = (string)($resp['link'] ?? '');
                $wpStatus = (string)($resp['status'] ?? '');

                $update = [
                    'wp_id'      => $wpId > 0 ? $wpId : null,
                    'wp_link'    => $link !== '' ? $link : null,
                    'error'      => null,
                    'updated_at' => now(),
                ];

                // ✅ Estatus EXACTO como tu lógica original
                if ($wpStatus === 'future') {
                    $update['estatus'] = 'programado';

                    // scheduled_gmt si viene, si no usar lo calculado local
                    if (!empty($resp['scheduled_gmt'])) {
                        $update['scheduled_at'] = Carbon::parse($resp['scheduled_gmt'], 'UTC')
                            ->setTimezone(config('app.timezone'));
                    } else {
                        // fallback: si enviamos schedule_at, ya lo tenemos calculado en upsertToWp()
                        $update['scheduled_at'] = $this->lastScheduleAtLocal ?? null;
                    }
                } elseif ($wpStatus === 'publish') {
                    $update['estatus'] = 'publicado';
                    $update['scheduled_at'] = null;
                } else {
                    $update['estatus'] = 'generado';
                    $update['scheduled_at'] = null;
                }

                DB::table('dominios_contenido_detalles')
                    ->where('id_dominio_contenido_detalle', $it->id_dominio_contenido_detalle)
                    ->update($update);

                Log::info("WP OK id_detalle={$it->id_dominio_contenido_detalle} wp_id={$wpId} status={$wpStatus}");

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

    // =========================================================
    // ✅ TU LÓGICA DE UPSERT (REST + fallback + HMAC)
    // =========================================================

    private ?Carbon $lastScheduleAtLocal = null;

    private function upsertToWp($dom, $it, string $secret): array
    {
        $wpBase      = rtrim((string) $dom->url, '/');
        $urlRest     = $wpBase . '/wp-json/lws/v1/upsert';
        $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

        $tipoNorm = strtolower(trim((string) $it->tipo));
        $type = ($tipoNorm === 'page') ? 'page' : 'post';

        if (empty($it->contenido_html)) {
            throw new \RuntimeException('contenido_html está vacío (no hay nada que publicar/programar).');
        }

        $templatePath = trim((string) ($dom->elementor_template_path ?? ''));
        $useElementor = ($templatePath !== '');

        $canvas = ($type === 'page') ? 'elementor_canvas' : '';

        $contentToSend = (string) $it->contenido_html;

        // Si NO usamos Elementor, asegúrate de enviar HTML (no JSON)
        if (!$useElementor) {
            $contentToSendTrim = ltrim($contentToSend);
            $looksLikeJson = ($contentToSendTrim !== '' && in_array($contentToSendTrim[0], ['{', '['], true));

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
                        $contentToSend = '<div>' . e($it->title ?: ($it->keyword ?: '')) . '</div>';
                    }
                }
            }

            if (!str_contains($contentToSend, '<')) {
                $contentToSend = '<div>' . e($contentToSend) . '</div>';
            }
        }

        // status según modo del dominio
        $modo = (string)($dom->wp_auto_modo ?? 'manual');
        if (!in_array($modo, ['publicar', 'programar'], true)) {
            throw new \RuntimeException("Modo WP inválido: {$modo}");
        }

        $scheduleAtUtcWp = null;
        $dtUtc = null;

        if ($modo === 'programar') {
            // si no viene scheduled_at, programamos "ahora +10" o respetamos tu lógica externa
            $local = now()->addMinutes(10);

            // Guardamos por si WP no devuelve scheduled_gmt
            $this->lastScheduleAtLocal = $local->copy();

            $dtUtc = $local->copy()->setTimezone('UTC');

            // WP-safe: Y-m-d\TH:i:s (sin offset)
            $scheduleAtUtcWp = $dtUtc->format('Y-m-d\TH:i:s');
        }

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
            $payload['schedule_at'] = $scheduleAtUtcWp;
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
            'X-Timestamp'  => (string) $ts,
            'X-Signature'  => $sig,
        ];

        $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlRest, ['body' => $body]);

        if (in_array($resp->status(), [404, 405], true)) {
            $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlFallback, ['body' => $body]);
        }

        $json = $resp->json();

        if (!$resp->ok() || !is_array($json) || empty($json['ok'])) {
            $msg = is_array($json) ? ($json['message'] ?? 'Error desconocido') : ('HTTP ' . $resp->status());
            throw new \RuntimeException('No se pudo upsert en WP: ' . $msg);
        }

        return $json;
    }

    // =========================================================
    // Próxima ejecución WP (reglas)
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
