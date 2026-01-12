<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/**
 * Secret
 */
$getSecret = function (): ?string {
    $secret = config('services.wp_webhook.secret');
    if (!is_string($secret) || trim($secret) === '') {
        $secret = env('WP_WEBHOOK_SECRET');
    }
    $secret = is_string($secret) ? trim($secret) : null;
    return ($secret !== '') ? $secret : null;
};

/**
 * Validate HMAC(ts.body)
 */
$validate = function (Request $r, ?string $secret) : array {
    $raw = (string) $r->getContent();
    $sig = $r->header('X-Signature');
    $tsHeader = $r->header('X-Timestamp');
    $ts = $tsHeader !== null ? (int) $tsHeader : null;

    if (!$secret) return [false, 'missing_secret'];
    if (!$sig)    return [false, 'missing_signature'];
    if ($tsHeader === null) return [false, 'missing_timestamp'];
    if ($ts === null || $ts <= 0) return [false, 'invalid_timestamp'];

    $window = (int) env('WP_WEBHOOK_WINDOW', 300);
    if (abs(time() - $ts) > $window) return [false, 'timestamp_out_of_window'];

    $calc = hash_hmac('sha256', $ts . '.' . $raw, $secret);
    if (!hash_equals($calc, (string)$sig)) return [false, 'bad_signature'];

    return [true, 'ok'];
};

/**
 * TTL para TMP (corrida en progreso) -> corto
 */
$tmpTtl = function () {
    $mins = (int) env('WP_INV_TMP_TTL_MIN', 60); // default 60 min
    if ($mins <= 0) $mins = 60;
    return now()->addMinutes($mins);
};

/**
 * Rejected TTL (debug) -> corto/medio
 */
$rejTtl = function () {
    $mins = (int) env('WP_INV_REJ_TTL_MIN', 60); // 60 min
    if ($mins <= 0) $mins = 60;
    return now()->addMinutes($mins);
};

$rememberRejected = function (string $endpointKey, Request $r, array $payload, string $reason) use ($rejTtl): void {
    Cache::put($endpointKey . '_rejected', [
        'at' => now()->toDateTimeString(),
        'ip' => $r->ip(),
        'reason' => $reason,
        'has_sig' => (bool)$r->header('X-Signature'),
        'has_ts'  => $r->header('X-Timestamp') !== null,
        'ts' => (string)$r->header('X-Timestamp'),
        'site' => $payload['site'] ?? ($payload['_site'] ?? null),
        'type' => $payload['type'] ?? null,
        'post_type' => $payload['post_type'] ?? null,
        'wp_id' => $payload['wp_id'] ?? null,
    ], $rejTtl());
};

/**
 * Recalcula counts por status
 */
$recalcCounts = function(array $items): array {
    $counts = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $st = (string)($it['status'] ?? 'unknown');
        if ($st === '') $st = 'unknown';
        $counts[$st] = ($counts[$st] ?? 0) + 1;
    }
    foreach (['publish','draft','future','pending','private'] as $st) {
        $counts[$st] = (int)($counts[$st] ?? 0);
    }
    return $counts;
};

/**
 * Helpers snapshot keys
 */
$keysFor = function(string $site, string $type): array {
    $siteKey = md5(rtrim($site, '/'));
    return [
        'siteKey' => $siteKey,
        'invKey'  => "inv:{$siteKey}:{$type}",
        'cntKey'  => "inv_counts:{$siteKey}:{$type}",
        'metaKey' => "inv_meta:{$siteKey}:{$type}",
    ];
};

/* =========================================================
 *  WEBHOOK: eventos (upsert/status/delete)
 *  ✅ parchea snapshot FOREVER
 * ========================================================= */
Route::post('/wp/webhook', function (Request $r) use ($getSecret, $validate, $rememberRejected, $recalcCounts, $keysFor) {
    $payload = $r->json()->all();
    if (!is_array($payload)) $payload = [];

    $secret = $getSecret();
    [$ok, $reason] = $validate($r, $secret);

    if (!$ok) {
        $rememberRejected('wp_webhook_last', $r, $payload, $reason);
        Log::warning('WP WEBHOOK UNAUTHORIZED', [
            'ip'=>$r->ip(),
            'reason'=>$reason,
            'site'=>$payload['site'] ?? ($payload['_site'] ?? null)
        ]);
        return response()->json(['ok'=>false,'error'=>'unauthorized','reason'=>$reason], 401);
    }

    // debug last webhook
    Cache::put('wp_webhook_last', [
        'at'   => now()->toDateTimeString(),
        'ip'   => $r->ip(),
        'mode' => 'ts_body',
        'data' => $payload,
    ], now()->addMinutes(60));

    $site     = rtrim((string)($payload['site'] ?? ($payload['_site'] ?? '')), '/');
    $postType = (string)($payload['post_type'] ?? '');
    $eventType= (string)($payload['type'] ?? '');
    $wpId     = isset($payload['wp_id']) ? (int)$payload['wp_id'] : 0;

    if ($site === '' || !in_array($postType, ['post','page'], true) || $wpId <= 0) {
        return response()->json(['ok'=>true, 'note'=>'no_patch']);
    }

    $k = $keysFor($site, $postType);
    $invKey  = $k['invKey'];
    $cntKey  = $k['cntKey'];
    $metaKey = $k['metaKey'];

    $items = Cache::get($invKey, []);
    if (!is_array($items)) $items = [];

    $byId = [];
    foreach ($items as $it) {
        if (is_array($it) && isset($it['wp_id'])) $byId[(int)$it['wp_id']] = $it;
    }

    if ($eventType === 'delete') {
        unset($byId[$wpId]);
    } elseif (in_array($eventType, ['upsert','status'], true)) {
        $byId[$wpId] = [
            'type'            => 'inventory_item',
            'wp_id'           => $wpId,
            'post_type'       => $postType,
            'status'          => (string)($payload['status'] ?? 'draft'),
            'title'           => (string)($payload['title'] ?? ''),
            'slug'            => (string)($payload['slug'] ?? ''),
            'link'            => (string)($payload['link'] ?? ''),
            'date'            => $payload['date'] ?? null,
            'modified'        => $payload['modified'] ?? null,
            'scheduled_gmt'   => $payload['scheduled_gmt'] ?? null,
            'scheduled_local' => $payload['scheduled_local'] ?? null,
            'builder'         => $payload['builder'] ?? null,
            'wp_page_template'=> $payload['wp_page_template'] ?? null,
        ];
    }

    $merged = array_values($byId);
    usort($merged, fn($a,$b) => strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? '')));

    // ✅ SNAPSHOT NO EXPIRA
    Cache::forever($invKey, $merged);
    Cache::forever($cntKey, $recalcCounts($merged));

    $meta = Cache::get($metaKey, []);
    if (!is_array($meta)) $meta = [];
    $meta['updated_at'] = now()->toDateTimeString();
    $meta['last_event'] = $eventType;
    $meta['is_complete'] = $meta['is_complete'] ?? false;
    Cache::forever($metaKey, $meta);

    return response()->json(['ok'=>true]);
});

Route::get('/wp/webhook/last', function (Request $r) {
    return response()->json([
        'last_ok' => Cache::get('wp_webhook_last', ['ok'=>false,'message'=>'Aún no ha llegado nada']),
        'last_rejected' => $r->query('debug') ? Cache::get('wp_webhook_last_rejected', ['message'=>'No hay rechazados']) : null,
    ]);
});

/* =========================================================
 *  INVENTORY: batches desde WP
 *  ✅ snapshot FOREVER
 *  ✅ tmp TTL corto
 *  ✅ NO resetear a 0 en Laravel nunca
 * ========================================================= */
Route::post('/wp/inventory', function (Request $r) use ($getSecret, $validate, $rememberRejected, $tmpTtl, $recalcCounts, $keysFor) {
    $payload = $r->json()->all();
    if (!is_array($payload)) $payload = [];

    $secret = $getSecret();
    [$ok, $reason] = $validate($r, $secret);

    if (!$ok) {
        $rememberRejected('wp_inventory_last', $r, $payload, $reason);
        return response()->json(['ok'=>false,'error'=>'unauthorized','reason'=>$reason], 401);
    }

    $site  = rtrim((string)($payload['site'] ?? ($payload['_site'] ?? '')), '/');
    $type  = (string)($payload['type'] ?? '');
    $runId = (string)($payload['run_id'] ?? '');
    $isLast= (bool)($payload['is_last'] ?? false);
    $items = $payload['items'] ?? [];
    if (!is_array($items)) $items = [];

    if ($site === '' || !in_array($type, ['post','page'], true) || $runId === '') {
        return response()->json(['ok'=>false,'error'=>'bad_payload'], 422);
    }

    $k = $keysFor($site, $type);
    $siteKey = $k['siteKey'];
    $invKey  = $k['invKey'];
    $cntKey  = $k['cntKey'];
    $metaKey = $k['metaKey'];

    $tmpKey = "inv_tmp:{$siteKey}:{$type}:{$runId}";

    $current = Cache::get($tmpKey, []);
    if (!is_array($current)) $current = [];

    // dedupe por wp_id
    $byId = [];
    foreach ($current as $it) {
        if (is_array($it) && isset($it['wp_id'])) $byId[(int)$it['wp_id']] = $it;
    }
    foreach ($items as $it) {
        if (is_array($it) && isset($it['wp_id'])) $byId[(int)$it['wp_id']] = $it;
    }

    $merged = array_values($byId);

    // TMP corto (para no llenar cache)
    Cache::put($tmpKey, $merged, $tmpTtl());

    // snapshot ordenado
    usort($merged, fn($a,$b) => strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? '')));

    // ✅ SNAPSHOT NO EXPIRA
    Cache::forever($invKey, $merged);
    Cache::forever($cntKey, $recalcCounts($merged));
    Cache::forever($metaKey, [
        'run_id'      => $runId,
        'is_complete' => $isLast,
        'updated_at'  => now()->toDateTimeString(),
    ]);

    // si terminó, limpiamos tmp
    if ($isLast) {
        Cache::forget($tmpKey);
    }

    Cache::put('wp_inventory_last_' . $siteKey, [
        'at' => now()->toDateTimeString(),
        'ip' => $r->ip(),
        'site' => $site,
        'type' => $type,
        'page' => $payload['page'] ?? null,
        'per_page' => $payload['per_page'] ?? null,
        'received' => count($items),
        'run_id' => $runId,
        'is_last' => $isLast,
    ], now()->addMinutes(60));

    return response()->json(['ok'=>true,'site'=>$site,'type'=>$type,'received'=>count($items),'is_last'=>$isLast]);
});

Route::get('/wp/inventory/snapshot', function (Request $r) {
    $site = rtrim((string)$r->query('site', ''), '/');
    if ($site === '') return response()->json(['ok'=>false,'error'=>'missing_site'], 400);

    $siteKey = md5($site);

    return response()->json([
        'site' => $site,
        'pages' => Cache::get("inv:{$siteKey}:page", []),
        'posts' => Cache::get("inv:{$siteKey}:post", []),
        'countPages' => Cache::get("inv_counts:{$siteKey}:page", []),
        'countPosts' => Cache::get("inv_counts:{$siteKey}:post", []),
        'metaPages' => Cache::get("inv_meta:{$siteKey}:page", []),
        'metaPosts' => Cache::get("inv_meta:{$siteKey}:post", []),
    ]);
});

Route::get('/wp/inventory/last', function (Request $r) {
    $site = (string)$r->query('site', '');
    if ($site === '') return response()->json(['ok'=>false,'error'=>'missing_site'], 400);

    $key = 'wp_inventory_last_' . md5(rtrim($site,'/'));

    return response()->json([
        'last_ok' => Cache::get($key, ['ok'=>false,'message'=>'Aún no ha llegado inventario']),
        'last_rejected' => $r->query('debug') ? Cache::get('wp_inventory_last_rejected', ['message'=>'No hay rechazados']) : null,
    ]);
});
