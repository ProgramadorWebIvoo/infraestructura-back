<?php

use App\Services\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:clear-expired-tokens')->daily();
Schedule::command('notifications:prune')->daily();

// Cada 6h (no diario): las invitaciones a proveedor vencen en horas, no días
// — un chequeo diario podría avisar demasiado tarde dentro de la ventana de
// 48h. La detección de "sin re-notificar el mismo día" (ver el comando) hace
// que correr más seguido no duplique avisos.
Schedule::command('alertas:vencimientos')->everySixHours();

// Procesar cola de notificaciones cada minuto (pausa si no hay jobs)
Schedule::command('queue:work --queue=default --max-time=60 --max-jobs=50 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Sync de tasas de cambio BCV: Lunes-Viernes, hora y activación configurables
// desde CONFIG APP → Monedas (tasa_cambio_cron_hora / tasa_cambio_cron_habilitado,
// ver AppSettingCatalog). Este archivo se re-evalúa en cada invocación de
// artisan (cada tick de schedule:run), así que SettingsService::get() siempre
// lee el valor vigente — no hace falta cachear ni reiniciar nada al cambiarlo.
// ->timezone() explícito porque config('app.timezone') es 'UTC' (sin
// 'app.schedule_timezone' configurado) — sin esto, dailyAt() corre en UTC,
// potencialmente antes de que el BCV publique la tasa oficial del día. Antes
// vivía en app/Console/Kernel.php, que en Laravel 11+ (bootstrap vía
// Application::configure(), sin binding de App\Console\Kernel) nunca se
// invoca — el sync automático nunca corrió, solo el disparo manual desde el
// panel (POST /exchange-rates/sync) funcionaba.
//
// Schema::hasTable() antes de leer SettingsService: este archivo se carga en
// TODA invocación de `artisan` (incluyendo `migrate` en una BD recién creada
// y el bootstrap de `artisan test`, donde la tabla `app_settings` todavía no
// existe) — sin el guard, la migración inicial del proyecto o el test suite
// completo fallan con "table app_settings not found" antes de poder crearla.
$cronHour = Schema::hasTable('app_settings') ? SettingsService::get('tasa_cambio_cron_hora', '10:00') : '10:00';

Schedule::command('sync:exchange-rates')
    ->timezone('America/Caracas')
    ->dailyAt($cronHour)
    ->weekdays()
    ->when(fn () => !Schema::hasTable('app_settings') || (bool) SettingsService::get('tasa_cambio_cron_habilitado', true))
    ->name('sync_exchange_rates')
    ->onSuccess(function () {
        \Log::info('✅ Exchange rates synced successfully');
    })
    ->onFailure(function () {
        \Log::error('❌ Exchange rates sync failed');
    });
