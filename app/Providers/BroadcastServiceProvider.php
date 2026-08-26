<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Broadcast::routes() por defecto registra /broadcasting/auth bajo el
        // grupo 'web' — esta app es una SPA API-only donde la sesión Sanctum
        // (EnsureFrontendRequestsAreStateful) vive en el grupo 'api', no en
        // 'web'. Sin este override, la autenticación del canal privado
        // fallaría para el frontend real.
        Broadcast::routes(['middleware' => ['api']]);

        require base_path('routes/channels.php');
    }
}
