<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

// Sync de tasas de cambio BCV: Lunes-Viernes a las 10:00 AM VE.
// ->timezone() explícito porque config('app.timezone') es 'UTC' (sin
// 'app.schedule_timezone' configurado) — sin esto, dailyAt() corre a las
// 10:00 UTC = 6:00 AM VE, antes de que el BCV publique la tasa oficial del
// día. Antes vivía en app/Console/Kernel.php, que en Laravel 11+ (bootstrap
// vía Application::configure(), sin binding de App\Console\Kernel) nunca se
// invoca — el sync automático nunca corrió, solo el disparo manual desde el
// panel (POST /exchange-rates/sync) funcionaba.
Schedule::command('sync:exchange-rates')
    ->timezone('America/Caracas')
    ->dailyAt('10:00')
    ->weekdays()
    ->name('sync_exchange_rates')
    ->onSuccess(function () {
        \Log::info('✅ Exchange rates synced successfully');
    })
    ->onFailure(function () {
        \Log::error('❌ Exchange rates sync failed');
    });
