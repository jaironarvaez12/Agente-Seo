<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::post('/wp/webhook', function (Request $r) {
    $secret = env('WP_WEBHOOK_SECRET');

    $raw = $r->getContent();
    $sig = (string) $r->header('X-Signature');
    $ts  = (int) $r->header('X-Timestamp');

    // ✅ ventana de 5 minutos para evitar replay
    if (!$secret || !$sig || $ts <= 0 || abs(time() - $ts) > 300) {
        return response()->json(['ok'=>false,'error'=>'unauthorized'], 401);
    }

    $calc = hash_hmac('sha256', $ts . '.' . $raw, $secret);

    if (!hash_equals($calc, $sig)) {
        return response()->json(['ok'=>false,'error'=>'bad_signature'], 401);
    }

    $data = $r->json()->all();

    // SOLO prueba: guardamos lo último recibido
    Cache::put('wp_webhook_last', [
        'at'   => now()->toDateTimeString(),
        'ip'   => $r->ip(),
        'data' => $data,
    ], now()->addMinutes(10));

    return response()->json(['ok'=>true]);
});

Route::get('/wp/webhook/last', function () {
    return Cache::get('wp_webhook_last', ['ok'=>false,'message'=>'Aún no ha llegado nada']);
});