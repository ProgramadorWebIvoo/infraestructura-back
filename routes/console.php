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
