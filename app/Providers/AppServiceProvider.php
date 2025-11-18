<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Console\Commands\CreateDirectoryStructure;

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
        // Forzar HTTPS en producción
        if (config('app.env') === 'production') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateDirectoryStructure::class,
            ]);
        }
    }
}
