<?php

namespace App\Services;

use App\Models\DominiosModel;
use App\Models\Dominios_Contenido_DetallesModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class ServicioWordPressContenido
{
    public function publicarDetalle(int $idDominio, int $idDetalle): array
    {
        $dom = DominiosModel::findOrFail($idDominio);
        $it  = Dominios_Contenido_DetallesModel::findOrFail($idDetalle);

        $it->estatus = 'en_proceso';
        $it->error = null;
        $it->save();

        try {
            $secret = (string) env('WP_WEBHOOK_SECRET');
            if ($secret === '') {
                throw new \RuntimeException('WP_WEBHOOK_SECRET no configurado en .env');
            }

            $wpBase      = rtrim((string)$dom->url, '/');
            $urlRest     = $wpBase . '/wp-json/lws/v1/upsert';
            $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

            $tipoNorm = strtolower(trim((string) $it->tipo));
            $type = ($tipoNorm === 'page') ? 'page' : 'post';

            if (empty($it->contenido_html)) {
                throw new \RuntimeException('contenido_html está vacío (no hay nada que publicar).');
            }

            $templatePath = trim((string) ($dom->elementor_template_path ?? ''));
            $usarElementor = ($templatePath !== '');

            $canvas = ($type === 'page') ? 'elementor_canvas' : '';

            $contentToSend = (string) $it->contenido_html;

            if (!$usarElementor) {
                $contentToSendTrim = ltrim($contentToSend);
                $pareceJson = ($contentToSendTrim !== '' && in_array($contentToSendTrim[0], ['{', '['], true));

                if ($pareceJson) {
                    $decoded = json_decode($contentToSend, true);
                    if (is_array($decoded)) {
                        $candidate = null;

                        $walk = function ($node) use (&$walk, &$candidate) {
                            if ($candidate) return;
                            if (is_array($node)) {
                                foreach ($node as $k => $v) {
                                    if (is_string($k) && in_array($k, ['editor','content','text'], true) && is_string($v) && str_contains($v, '<')) {
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

            $payload = [
                'type'  => $type,
                'wp_id' => $it->wp_id ?: null,

                'title'   => $it->title ?: ($it->keyword ?: 'Sin título'),
                'content' => $contentToSend,

                'builder' => $usarElementor ? 'elementor' : 'html',

                'wp_page_template' => $usarElementor ? $canvas : '',
                'template'         => $usarElementor ? $canvas : '',

                'status' => 'publish',
            ];

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) throw new \RuntimeException('No se pudo serializar payload JSON');

            $ts  = time();
            $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);

            $headers = [
                'Content-Type' => 'application/json',
                'X-Timestamp'  => (string)$ts,
                'X-Signature'  => $sig,
            ];

            $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlRest, ['body' => $body]);

            if (in_array($resp->status(), [404, 405], true)) {
                $resp = Http::timeout(25)->withHeaders($headers)->send('POST', $urlFallback, ['body' => $body]);
            }

            $json = $resp->json();

            if (!$resp->ok() || !is_array($json) || empty($json['ok'])) {
                $msg = is_array($json) ? ($json['message'] ?? 'Error desconocido') : ('HTTP ' . $resp->status());
                $it->estatus = 'error';
                $it->error = $msg;
                $it->save();
                return [false, $msg];
            }

            $it->estatus = (($json['status'] ?? '') === 'publish') ? 'publicado' : 'generado';
            $it->wp_id   = (int)($json['wp_id'] ?? 0) ?: $it->wp_id;
            $it->wp_link = (string)($json['link'] ?? '');
            $it->save();

            return [true, 'Publicado en WordPress'];
        } catch (\Throwable $e) {
            $it->estatus = 'error';
            $it->error = $e->getMessage();
            $it->save();
            return [false, $e->getMessage()];
        }
    }

    public function programarDetalle(int $idDominio, int $idDetalle, Carbon $fechaAppTz): array
    {
        $dom = DominiosModel::findOrFail($idDominio);
        $it  = Dominios_Contenido_DetallesModel::findOrFail($idDetalle);

        $it->estatus = 'en_proceso';
        $it->error = null;
        $it->save();

        try {
            $secret = (string) env('WP_WEBHOOK_SECRET');
            if ($secret === '') {
                throw new \RuntimeException('WP_WEBHOOK_SECRET no configurado en .env');
            }

            $dtUtc = $fechaAppTz->copy()->setTimezone('UTC');
            $scheduleAtUtcWp = $dtUtc->format('Y-m-d H:i:s');

            $wpBase      = rtrim((string) $dom->url, '/');
            $urlRest     = $wpBase . '/wp-json/lws/v1/upsert';
            $urlFallback = $wpBase . '/wp-admin/admin-post.php?action=lws_upsert';

            $tipoNorm = strtolower(trim((string) $it->tipo));
            $type = ($tipoNorm === 'page') ? 'page' : 'post';

            if (empty($it->contenido_html)) {
                throw new \RuntimeException('contenido_html está vacío (no hay nada que programar).');
            }

            $templatePath = trim((string) ($dom->elementor_template_path ?? ''));
            $usarElementor = ($templatePath !== '');

            $canvas = ($type === 'page') ? 'elementor_canvas' : '';

            $contentToSend = (string) $it->contenido_html;

            if (!$usarElementor) {
                $contentToSendTrim = ltrim($contentToSend);
                $pareceJson = ($contentToSendTrim !== '' && in_array($contentToSendTrim[0], ['{', '['], true));

                if ($pareceJson) {
                    $decoded = json_decode($contentToSend, true);
                    if (is_array($decoded)) {
                        $candidate = null;

                        $walk = function ($node) use (&$walk, &$candidate) {
                            if ($candidate) return;
                            if (is_array($node)) {
                                foreach ($node as $k => $v) {
                                    if (is_string($k) && in_array($k, ['editor','content','text'], true) && is_string($v) && str_contains($v, '<')) {
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

            $payload = [
                'type'    => $type,
                'wp_id'   => $it->wp_id ?: null,
                'title'   => $it->title ?: ($it->keyword ?: 'Sin título'),
                'content' => $contentToSend,

                'builder' => $usarElementor ? 'elementor' : 'html',

                'wp_page_template' => $usarElementor ? $canvas : '',
                'template'         => $usarElementor ? $canvas : '',

                'status'      => 'future',
                'schedule_at' => $scheduleAtUtcWp,
            ];

            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) throw new \RuntimeException('No se pudo serializar payload JSON');

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
                $it->estatus = 'error';
                $it->error = $msg;
                $it->save();
                return [false, $msg];
            }

            $it->wp_id   = (int) ($json['wp_id'] ?? 0) ?: $it->wp_id;
            $it->wp_link = (string) ($json['link'] ?? '');

            $wpStatus = (string) ($json['status'] ?? '');

            if ($wpStatus === 'future') {
                $it->estatus = 'programado';
                $it->scheduled_at = $fechaAppTz->copy();
            } elseif ($wpStatus === 'publish') {
                $it->estatus = 'publicado';
                $it->scheduled_at = null;
            } else {
                $it->estatus = 'generado';
                $it->scheduled_at = null;
            }

            $it->save();

            return [true, 'Programado en WordPress'];
        } catch (\Throwable $e) {
            $it->estatus = 'error';
            $it->error = $e->getMessage();
            $it->save();
            return [false, $e->getMessage()];
        }
    }
}
