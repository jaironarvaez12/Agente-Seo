<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

$getSecret = function (): ?string {
    $secret = config('services.wp_webhook.secret');
    if (!is_string($secret) || trim($secret) === '') {
        $secret = env('WP_WEBHOOK_SECRET');
    }
    $secret = is_string($secret) ? trim($secret) : null;
    return ($secret !== '') ? $secret : null;
};

$validate = function (Request $r, ?string $secret) : array {
    $raw = (string) $r->getContent();
    $sig = $r->header('X-Signature');
    $tsHeader = $r->header('X-Timestamp');
    $ts = $tsHeader !== null ? (int) $tsHeader : null;

    if (!$secret) return [false, 'missing_secret'];
    if (!$sig)    return [false, 'missing_signature'];
    if ($tsHeader === null) return [false, 'missing_timestamp'];
    if ($ts === null || $ts <= 0) return [false, 'invalid_timestamp'];

    $window = 300;
    if (abs(time() - $ts) > $window) return [false, 'timestamp_out_of_window'];

    $calc = hash_hmac('sha256', $ts . '.' . $raw, $secret);
    if (!hash_equals($calc, (string)$sig)) return [false, 'bad_signature'];

    return [true, 'ok'];
};

$ttl = function () {
    $days = (int) env('WP_INV_TTL_DAYS', 30); // ✅ default 30 días
    if ($days <= 0) $days = 30;
    return now()->addDays($days);
};

$rememberRejected = function (string $endpointKey, Request $r, array $payload, string $reason) use ($ttl): void {
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
    ], $ttl());
};

/**
 * Recalcula counts por status a partir del snapshot
 */
$recalcCounts = function(array $items): array {
    $counts = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $st = (string)($it['status'] ?? 'unknown');
        if ($st === '') $st = 'unknown';
        $counts[$st] = ($counts[$st] ?? 0) + 1;
    }
    // normaliza estados esperados
    foreach (['publish','draft','future','pending','private'] as $st) {
        $counts[$st] = (int)($counts[$st] ?? 0);
    }
    return $counts;
};

/* =========================================================
 *  WEBHOOK: eventos (upsert/status/delete)
 *  ✅ AHORA TAMBIÉN PARCHEA EL INVENTARIO
 * ========================================================= */
Route::post('/wp/webhook', function (Request $r) use ($getSecret, $validate, $rememberRejected, $ttl, $recalcCounts) {
    $payload = $r->json()->all();
    if (!is_array($payload)) $payload = [];

    $secret = $getSecret();
    [$ok, $reason] = $validate($r, $secret);

    if (!$ok) {
        $rememberRejected('wp_webhook_last', $r, $payload, $reason);
        Log::warning('WP WEBHOOK UNAUTHORIZED', [
            'ip'=>$r->ip(), 'reason'=>$reason, 'site'=>$payload['site'] ?? ($payload['_site'] ?? null)
        ]);
        return response()->json(['ok'=>false,'error'=>'unauthorized','reason'=>$reason], 401);
    }

    // ✅ site puede venir como "site" o "_site" (según tu plugin)
    $site = rtrim((string)($payload['site'] ?? ($payload['_site'] ?? '')), '/');
    $postType = (string)($payload['post_type'] ?? ''); // post|page
    $eventType = (string)($payload['type'] ?? '');     // upsert|status|delete

    Cache::put('wp_webhook_last', [
        'at'   => now()->toDateTimeString(),
        'ip'   => $r->ip(),
        'mode' => 'ts_body',
        'data' => $payload,
    ], $ttl());

    // Si no tenemos site o post_type, igual respondemos ok (pero no parchamos)
    if ($site === '' || !in_array($postType, ['post','page'], true)) {
        return response()->json(['ok'=>true, 'note'=>'no_site_or_post_type']);
    }

    $siteKey = md5($site);
    $invKey = "inv:{$siteKey}:{$postType}";
    $cntKey = "inv_counts:{$siteKey}:{$postType}";
    $metaKey = "inv_meta:{$siteKey}:{$postType}";

    $items = Cache::get($invKey, []);
    if (!is_array($items)) $items = [];

    // index por wp_id
    $byId = [];
    foreach ($items as $it) {
        if (is_array($it) && isset($it['wp_id'])) {
            $byId[(int)$it['wp_id']] = $it;
        }
    }

    $wpId = isset($payload['wp_id']) ? (int)$payload['wp_id'] : 0;

    if ($eventType === 'delete' && $wpId > 0) {
        unset($byId[$wpId]);
    } elseif (in_array($eventType, ['upsert','status'], true) && $wpId > 0) {
        // ✅ Guardamos SOLO campos necesarios para inventario
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

    // ordenar por modified desc (como en tu controlador)
    usort($merged, fn($a,$b) => strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? '')));

    Cache::put($invKey, $merged, $ttl());
    Cache::put($cntKey, $recalcCounts($merged), $ttl());

    // meta: lo dejamos marcado como “no necesariamente completo” si nunca corrió inventario,
    // pero siempre “updated”
    $meta = Cache::get($metaKey, []);
    if (!is_array($meta)) $meta = [];
    $meta['updated_at'] = now()->toDateTimeString();
    $meta['last_event'] = $eventType;
    Cache::put($metaKey, $meta, $ttl());

    return response()->json(['ok'=>true]);
});

/* =========================================================
 *  WEBHOOK DEBUG
 * ========================================================= */
Route::get('/wp/webhook/last', function (Request $r) {
    return response()->json([
        'last_ok' => Cache::get('wp_webhook_last', ['ok'=>false,'message'=>'Aún no ha llegado nada']),
        'last_rejected' => $r->query('debug') ? Cache::get('wp_webhook_last_rejected', ['message'=>'No hay rechazados']) : null,
    ]);
});

/* =========================================================
 *  INVENTORY: batches desde WP
 *  ✅ TTL largo + snapshot parcial por batch + NO borrar final
 * ========================================================= */
Route::post('/wp/inventory', function (Request $r) use ($getSecret, $validate, $rememberRejected, $ttl, $recalcCounts) {
    $payload = $r->json()->all();
    if (!is_array($payload)) $payload = [];

    $secret = $getSecret();
    [$ok, $reason] = $validate($r, $secret);

    if (!$ok) {
        $rememberRejected('wp_inventory_last', $r, $payload, $reason);
        return response()->json(['ok'=>false,'error'=>'unauthorized','reason'=>$reason], 401);
    }

    $site = rtrim((string)($payload['site'] ?? ($payload['_site'] ?? '')), '/');
    $type = (string)($payload['type'] ?? '');
    $runId = (string)($payload['run_id'] ?? '');
    $isLast = (bool)($payload['is_last'] ?? false);
    $items = $payload['items'] ?? [];
    if (!is_array($items)) $items = [];

    if ($site === '' || !in_array($type, ['post','page'], true) || $runId === '') {
        return response()->json(['ok'=>false,'error'=>'bad_payload'], 422);
    }

    $siteKey = md5($site);

    $tmpKey  = "inv_tmp:{$siteKey}:{$type}:{$runId}";
    $invKey  = "inv:{$siteKey}:{$type}";
    $cntKey  = "inv_counts:{$siteKey}:{$type}";
    $metaKey = "inv_meta:{$siteKey}:{$type}";

    $current = Cache::get($tmpKey, []);
    if (!is_array($current)) $current = [];

    // merge con dedupe por wp_id
    $byId = [];
    foreach ($current as $it) {
        if (is_array($it) && isset($it['wp_id'])) $byId[(int)$it['wp_id']] = $it;
    }
    foreach ($items as $it) {
        if (is_array($it) && isset($it['wp_id'])) $byId[(int)$it['wp_id']] = $it;
    }

    $merged = array_values($byId);

    // guardar tmp y snapshot parcial (TTL largo)
    Cache::put($tmpKey, $merged, $ttl());

    usort($merged, fn($a,$b) => strcmp((string)($b['modified'] ?? ''), (string)($a['modified'] ?? '')));

    Cache::put($invKey, $merged, $ttl());
    Cache::put($cntKey, $recalcCounts($merged), $ttl());

    Cache::put($metaKey, [
        'run_id'       => $runId,
        'is_complete'  => $isLast,
        'updated_at'   => now()->toDateTimeString(),
    ], $ttl());

    // si terminó, limpiamos tmp (opcional)
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
    ], $ttl());

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
    if ($site === '') {
        return response()->json(['ok'=>false,'error'=>'missing_site'], 400);
    }
    $key = 'wp_inventory_last_' . md5(rtrim($site,'/'));
    return response()->json([
        'last_ok' => Cache::get($key, ['ok'=>false,'message'=>'Aún no ha llegado inventario']),
        'last_rejected' => $r->query('debug') ? Cache::get('wp_inventory_last_rejected', ['message'=>'No hay rechazados']) : null,
    ]);
});
