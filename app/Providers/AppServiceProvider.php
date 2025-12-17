<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
       // Fuerza https en todas las URLs generadas (asset(), route(), etc.)
        if (app()->environment(['production', 'staging'])) {
            URL::forceScheme('https');
        }
    }
}
