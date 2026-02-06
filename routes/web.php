<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use App\Services\LicenseService;
use App\Models\Dominios_UsuariosModel;
use App\Models\DominiosModel;
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

 Route::delete('eliminardominio/{id}',[App\Http\Controllers\UsuariosController::class, 'EliminarDominio'])->name('eliminardominio');


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
    Route::get('dominiosidentidad', [App\Http\Controllers\DominiosController::class, 'IdentidadDominios'])->name('dominiosidentidad');
    Route::post('dominiosactualizaridentidad', [App\Http\Controllers\DominiosController::class, 'ActulizarIdentidadDominios'])->name('dominiosactualizaridentidad');
    

    Route::get('/licencia/resumen', function (LicenseService $licenses) {
    $user = auth()->user();
    abort_unless($user, 401);

    $key = $user->getLicenseKeyPlain();
    abort_unless($key, 403, 'No tienes licencia guardada.');

    // Para obtener plan, valida sobre un dominio activo si existe,
    // si no existe, asumimos pro por ahora o lo guardas en users.
    $plan = 'pro';

    $max = (int) config("licenses.max_by_plan.$plan", 0);

    $activos = App\Models\LicenciaDominiosActivacionModel::where('user_id', $user->id)
        ->where('license_key', sha1($key))
        ->where('estatus', 'activo')
        ->pluck('dominio')
        ->toArray();

    $used = count($activos);
    $remaining = max(0, $max - $used);

    return response()->json([
        'plan' => $plan,
        'max' => $max,
        'usados' => $used,
        'restantes' => $remaining,
        'dominios_activos' => $activos,
    ]);
})->middleware('auth');
    




Route::get('/debug/desactivar-todo-bd', function (LicenseService $licenses) {
    $u = auth()->user();
    abort_unless($u, 401);

    $key = $u->getLicenseKeyPlain();
    abort_unless($key, 403);

    $hash = sha1($key);

    $activos = App\Models\LicenciaDominiosActivacionModel::where('user_id', $u->id)
        ->where('license_key', $hash)
        ->where('estatus', 'activo')
        ->pluck('dominio')
        ->toArray();

    $out = [];
    foreach ($activos as $dom) {
        $host = trim(preg_replace('#^https?://#i', '', rtrim($dom, '/')));

        $resp = $licenses->deactivate($key, $host);
        $out[] = ['domain' => $host, 'response' => $resp];

        // marcar localmente como inactivo
        App\Models\LicenciaDominiosActivacionModel::where('user_id', $u->id)
            ->where('license_key', $hash)
            ->where('dominio', $host)
            ->update([
                'estatus' => 'inactivo',
                'desactivado_at' => now(),
            ]);
    }

    return response()->json([
        'count' => count($activos),
        'results' => $out,
        'note' => 'Desactiva los dominios activos según tu registro local.',
    ]);
})->middleware('auth');


Route::get('/debug/desactivar-lista', function (LicenseService $licenses) {
    $u = auth()->user();
    abort_unless($u, 401);

    $key = $u->getLicenseKeyPlain();
    abort_unless($key, 403);

    $domains = request()->query('d', []);
    if (!is_array($domains) || count($domains) === 0) {
        return response()->json([
            'success' => false,
            'message' => 'Usa: /debug/desactivar-lista?d[]=dom1.com&d[]=dom2.com'
        ], 422);
    }

    $out = [];
    foreach ($domains as $d) {
        $host = trim(preg_replace('#^https?://#i', '', rtrim($d, '/')));

        try {
            $resp = $licenses->deactivate($key, $host);
            $out[] = ['domain' => $host, 'response' => $resp];
        } catch (\Throwable $e) {
            $out[] = ['domain' => $host, 'error' => $e->getMessage()];
        }
    }

    return response()->json([
        'count' => count($domains),
        'results' => $out,
        'note' => 'Desactiva exactamente los dominios que le pases.',
    ]);
})->middleware('auth');

    Route::middleware(['auth'])->group(function () {
        Route::post('/dominios/{id}/licencia/activar', [App\Http\Controllers\DominiosController::class, 'activarLicencia'])
            ->name('dominios.licencia.activar');

        Route::post('/dominios/{id}/licencia/desactivar', [App\Http\Controllers\DominiosController::class, 'desactivarLicencia'])
            ->name('dominios.licencia.desactivar');
    });





    // Formulario para crear usuario dependiente
    Route::get('/usuarios/dependientes/crear', [App\Http\Controllers\UsuariosController::class, 'crearDependiente'])
        ->name('usuarios.dependientes.crear');

    // Guardar usuario dependiente
    Route::post('/usuarios/dependientes/guardar', [App\Http\Controllers\UsuariosController::class, 'guardarDependiente'])
        ->name('usuarios.dependientes.guardar');
     //autogenerar
     Route::get('/dominios/{idDominio}/auto-generacion', [App\Http\Controllers\DominiosAutoGeneracionController::class, 'editar'])
        ->name('dominios.auto_generacion.editar');

    Route::post('/dominios/{idDominio}/auto-generacion', [App\Http\Controllers\DominiosAutoGeneracionController::class, 'actualizar'])
        ->name('dominios.auto_generacion.actualizar');

    Route::post('/dominios/{idDominio}/auto-generacion/ejecutar-ahora', [App\Http\Controllers\DominiosAutoGeneracionController::class, 'ejecutarAhora'])
        ->name('dominios.auto_generacion.ejecutar_ahora');
    //DASHBAORD
    //dashboard Seo

    //rutas backlinks
    Route::post('/dominios/{dominio}/contenido/{detalle}/generar-backlinks',[App\Http\Controllers\DominiosController::class, 'generarBacklinks'])->name('dominios.contenido.generar_backlinks');




    Route::get('dashboardseo', [App\Http\Controllers\DashboardController::class, 'DashboardSeo'])->name('dashboardseo');

    //modificar prompt
    Route::get('configuracionprompt', [App\Http\Controllers\PromptGlobalController::class, 'editar'])->name('configuracionprompt');
    Route::post('guardarprompt', [App\Http\Controllers\PromptGlobalController::class, 'guardar'])->name('guardarprompt');

  //nuevo programacion
 Route::post('/dominios/{idDominio}/wp/ejecutar-ahora', [App\Http\Controllers\DominiosAutoGeneracionController::class, 'wpEjecutarAhora'])
  ->name('dominios.wp.ejecutar_ahora');



    });

   


    Route::view('/politica-de-privacidad', 'legal.politica-privacidad')->name('politica.privacidad');
    Route::resource('auth','App\Http\Controllers\AuthController');
    Route::get('login', [App\Http\Controllers\AuthController::class, 'index'])->name('login');
    Route::post('logout', [App\Http\Controllers\AuthController::class, 'logout'])->name('logout');