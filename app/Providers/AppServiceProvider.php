<?php

namespace App\Providers;

use App\Models\SupplierMaterialProposalLine;
use App\Notifications\Channels\ExpoChannel;
use App\Observers\PriceEstimationObserver;
use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        // Register model observers
        SupplierMaterialProposalLine::observe(PriceEstimationObserver::class);

        // Register Expo notification channel
        Notification::extend('expo', function ($app) {
            return $app->make(ExpoChannel::class);
        });

        // Todas las URLs generadas (ej. links de reset de contraseña) usan https
        // en producción, incluso si la request llega como http por un proxy
        // que no está en TRUSTED_PROXIES.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->configureRateLimiting();
        $this->invalidateCachesAfterMigrate();
    }

    /**
     * NotificationRuleResolver y SettingsService cachean su tabla completa
     * (`notification_rules`/`app_settings`) por 5 min, invalidando
     * explícitamente en cada escritura vía UI (CONFIG APP) — pero las
     * migraciones de seed insertan filas directamente con `DB::table(...)`,
     * sin pasar por esos servicios, así que nunca disparaban esa
     * invalidación. Resultado real (reportado por QA): una migración que
     * agrega una regla de notificación quedaba invisible hasta que el caché
     * expiraba solo, con la acción cayendo al fallback administrativo pese a
     * estar bien configurada en BD.
     *
     * `Illuminate\Database\Events\MigrationsEnded` no sirve: Laravel no lo
     * dispara cuando el runner determina "nothing to migrate" (el caso más
     * común en producción — la mayoría de los deploys no tienen migraciones
     * pendientes). `CommandFinished` sí se dispara siempre que el comando
     * corre, sin importar si movió algo — se filtra por nombre de comando
     * para no invalidar en cada comando artisan random.
     */
    protected function invalidateCachesAfterMigrate(): void
    {
        Event::listen(CommandFinished::class, function (CommandFinished $event) {
            if (!str_starts_with($event->command ?? '', 'migrate')) {
                return;
            }
            NotificationRuleResolver::forget();
            SettingsService::forget();
        });
    }

    /**
     * 60/min quedaba insuficiente para un SPA con polling en segundo plano:
     * NotificationsProvider ya consume ~15/min por sí solo (2 requests cada
     * 8s, sin pausa en background — necesario para la Notification API
     * nativa), más ~5/min de dashboard/proyectos, antes de que el usuario
     * navegue una sola vez. Con varias pestañas del mismo usuario (misma
     * key, el user_id) el presupuesto se multiplica aún más rápido. 180/min
     * deja margen real para navegación activa sin acercarse al límite solo
     * por el tráfico de fondo.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(180)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('catalog', function (Request $request) {
            return Limit::perMinute(200)->by($request->user()?->id ?: $request->ip());
        });
    }
}
