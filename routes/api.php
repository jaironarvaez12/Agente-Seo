<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

Route::get('/wp/webhook/last', function () {
    return Cache::get('wp_webhook_last', ['ok'=>false,'message'=>'Aún no ha llegado nada']);
});

Route::post('/wp/webhook', function (Request $r) {
    Cache::put('wp_webhook_last', [
        'at' => now()->toDateTimeString(),
        'data' => $r->json()->all(),
    ], now()->addMinutes(10));

    return response()->json(['ok'=>true]);
});