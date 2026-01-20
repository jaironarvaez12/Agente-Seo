<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;

Route::get('/debug/wp-cache', function () {
    $siteKey = request('site_key', '');
    if ($siteKey === '') return response()->json(['error'=>'missing site_key'], 400);

    return response()->json([
        'site_key' => $siteKey,
        'inv_post_count'  => is_array(Cache::get("inv:{$siteKey}:post")) ? count(Cache::get("inv:{$siteKey}:post")) : null,
        'inv_page_count'  => is_array(Cache::get("inv:{$siteKey}:page")) ? count(Cache::get("inv:{$siteKey}:page")) : null,
        'meta_post' => Cache::get("inv_meta:{$siteKey}:post"),
        'meta_page' => Cache::get("inv_meta:{$siteKey}:page"),
        'counts_post' => Cache::get("inv_counts:{$siteKey}:post"),
        'counts_page' => Cache::get("inv_counts:{$siteKey}:page"),
    ]);
});




Route::group(['middleware' => 'auth'], function(){
    Route::get('/',[App\Http\Controllers\DominiosController::class, 'index'])->name('inicio');

    //Usuarios
       Route::resource('usuarios','App\Http\Controllers\UsuariosController');
     //PERMISOS
      Route::resource('permisos','App\Http\Controllers\PermisosController');
      Route::resource('roles','App\Http\Controllers\RolesController');
    //DOMINIOS
     Route::resource('dominios','App\Http\Controllers\DominiosController');
     Route::get('dominioscrearcontenido/{id_dominio}',[App\Http\Controllers\DominiosController::class, 'CrearContenido']) ->name('dominioscrearcontenido');
     Route::post('dominiotipogenerador/{id_dominio}',[App\Http\Controllers\DominiosController::class, 'GeneradorContenido']) ->name('dominiotipogenerador');
    Route::get('/dominios/{id}/wp', [App\Http\Controllers\DominiosController::class, 'verWp'])->name('dominios.wp');
    Route::post('generador/{id_dominio}', [App\Http\Controllers\DominiosController::class, 'Generador'])->name('generador');
    Route::get('dominioscontenido-generado/{id_dominio}', [App\Http\Controllers\DominiosController::class, 'ContenidoGenerado'])->name('dominios.contenido_generado');


    Route::get('dominioeditartipogenerador/{id_dominio_contenido}', [App\Http\Controllers\DominiosController::class, 'EditarTipoGenerador'])->name('dominioeditartipogenerador');
    Route::post('dominioguardarediciontipo/{id_dominio_contenido}', [App\Http\Controllers\DominiosController::class, 'GuardarEditarTipoGenerador'])->name('dominioguardarediciontipo');




Route::post('/wp/webhook', [App\Http\Controllers\WordpressWebhookController::class, 'handle']);


Route::get('/debug/wp-cache', function () {
    $siteKey = request('site_key', '');
    if ($siteKey === '') return response()->json(['error'=>'missing site_key'], 400);

    return response()->json([
        'site_key' => $siteKey,
        'inv_post_count'  => is_array(Cache::get("inv:{$siteKey}:post")) ? count(Cache::get("inv:{$siteKey}:post")) : null,
        'inv_page_count'  => is_array(Cache::get("inv:{$siteKey}:page")) ? count(Cache::get("inv:{$siteKey}:page")) : null,
        'meta_post' => Cache::get("inv_meta:{$siteKey}:post"),
        'meta_page' => Cache::get("inv_meta:{$siteKey}:page"),
        'counts_post' => Cache::get("inv_counts:{$siteKey}:post"),
        'counts_page' => Cache::get("inv_counts:{$siteKey}:page"),
    ]);
});



Route::post('dominios/{dominio}/contenido/{detalle}/publicar', [App\Http\Controllers\DominiosController::class, 'publicar'])
  ->name('dominios.contenido.publicar');

  Route::post('dominios/{dominio}/contenido/{detalle}/programar', [App\Http\Controllers\DominiosController::class, 'programar'])
    ->name('dominios.contenido.programar');
    //PERFILES
    Route::resource('perfiles','App\Http\Controllers\PerfilesController');
  
    //reporte seo
  Route::prefix('dominios/{id_dominio}')->group(function () {
    Route::post('/reporte-seo', [App\Http\Controllers\SeoReportController::class, 'generar'])
        ->name('dominios.reporte_seo.generar');

    Route::get('/reporte-seo', [App\Http\Controllers\SeoReportController::class, 'ver'])
        ->name('dominios.reporte_seo.ver');
});

    Route::get('dominiosreportes/{id_dominio}', [App\Http\Controllers\DominiosController::class, 'ReportesDominio'])->name('dominiosreportes');
    Route::get('dominiosreportefecha/{id_dominio}/{id_reporte}', [App\Http\Controllers\SeoReportController::class, 'ReportesDominio'])->name('dominiosreportefecha');
   
    Route::get('/dominios/{id_dominio}/reporte-seo/pdf', [App\Http\Controllers\SeoReportController::class, 'pdf'])
    ->name('dominios.reporte_seo.pdf');

    Route::get('dominiosreportepdf/{id_dominio}/{id_reporte}', [App\Http\Controllers\SeoReportController::class, 'Reportepdf'])->name('dominiosreportepdf');
    
    });


    Route::view('/politica-de-privacidad', 'legal.politica-privacidad')->name('politica.privacidad');
    Route::resource('auth','App\Http\Controllers\AuthController');
    Route::get('login', [App\Http\Controllers\AuthController::class, 'index'])->name('login');
    Route::post('logout', [App\Http\Controllers\AuthController::class, 'logout'])->name('logout');