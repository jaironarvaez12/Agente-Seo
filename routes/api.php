<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// (Opcional) Ruta default de Laravel si usas Sanctum
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/**
 * Devuelve el secret para validar el webhook.
 * Por ahora usa .env (WP_WEBHOOK_SECRET).
 * (Más adelante, para multi-dominio, puedes resolver por $payload['site'].)
 */
function wpWebhookSecret(array $payload): ?string
{
    $secret = env('WP_WEBHOOK_SECRET');
    $secret = is_string($secret) ? trim($secret) : null;
    return $secret !== '' ? $secret : null;
}

/**
 * Valida firma HMAC.
 * - Nuevo modo: firma de "timestamp.body" (X-Timestamp obligatorio)
 * - Modo compat: firma solo del body (si no llega X-Timestamp)
 */
function wpWebhookValid(string $rawBody, ?string $secret, ?string $sig, ?int $ts): array
{
    if (!$secret || !$sig) {
        return [false, 'missing_secret_or_signature'];
    }

    // Si llega timestamp => validamos ventana anti-replay
    if ($ts !== null) {
        // Ventana (segundos). Si tu server tiene hora rara, sube temporalmente a 900.
        $window = 300;

        if ($ts <= 0) {
            return [false, 'invalid_timestamp'];
        }
        if (abs(time() - $ts) > $window) {
            return [false, 'timestamp_out_of_window'];
        }

        $calc = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
        return [hash_equals($calc, $sig), 'ts_body'];
    }

    // Compat: sin timestamp, firmamos body completo
    $calc = hash_hmac('sha256', $rawBody, $secret);
    return [hash_equals($calc, $sig), 'body_only'];
}

/**
 * Webhook receptor
 */
Route::post('/wp/webhook', function (Request $r) {
    $raw = (string) $r->getContent();

    // headers
    $sig = $r->header('X-Signature');
    $tsHeader = $r->header('X-Timestamp');
    $ts = $tsHeader !== null ? (int) $tsHeader : null;

    // Intenta parsear JSON (sin reventar)
    $payload = $r->json()->all();
    if (!is_array($payload)) $payload = [];

    // Log de que sí llegó al endpoint
    Log::info('WP webhook HIT', [
        'ip' => $r->ip(),
        'has_sig' => (bool)$sig,
        'has_ts'  => $tsHeader !== null,
        'site'    => $payload['site'] ?? null,
        'event'   => $payload['event'] ?? null,
        'type'    => $payload['type'] ?? null,
        'wp_id'   => $payload['wp_id'] ?? null,
    ]);

    $secret = wpWebhookSecret($payload);

    // Validación
    [$ok, $modeOrReason] = wpWebhookValid($raw, $secret, $sig ? (string)$sig : null, $ts);

    if (!$ok) {
        // Guarda el último rechazado para debug
        Cache::put('wp_webhook_last_rejected', [
            'at' => now()->toDateTimeString(),
            'ip' => $r->ip(),
            'reason' => $modeOrReason,
            'has_sig' => (bool)$sig,
            'has_ts' => $tsHeader !== null,
            'ts' => $tsHeader,
            'site' => $payload['site'] ?? null,
            'event' => $payload['event'] ?? null,
        ], now()->addMinutes(30));

        Log::warning('WP webhook UNAUTHORIZED', [
            'ip' => $r->ip(),
            'reason' => $modeOrReason,
            'ts' => $tsHeader,
            'site' => $payload['site'] ?? null,
        ]);

        return response()->json(['ok' => false, 'error' => 'unauthorized', 'reason' => $modeOrReason], 401);
    }

    // ✅ Guarda el último válido
    Cache::put('wp_webhook_last', [
        'at'   => now()->toDateTimeString(),
        'ip'   => $r->ip(),
        'mode' => $modeOrReason, // ts_body | body_only
        'data' => $payload,
    ], now()->addMinutes(30));

    return response()->json(['ok' => true]);
});

/**
 * Ver el último webhook válido.
 * (Tip) si llamas ?debug=1 también te muestra el último rechazado.
 */
Route::get('/wp/webhook/last', function (Request $r) {
    $out = [
        'last_ok' => Cache::get('wp_webhook_last', ['ok' => false, 'message' => 'Aún no ha llegado nada']),
    ];

    if ($r->query('debug')) {
        $out['last_rejected'] = Cache::get('wp_webhook_last_rejected', ['message' => 'No hay rechazados registrados']);
    }

    return response()->json($out);
});
