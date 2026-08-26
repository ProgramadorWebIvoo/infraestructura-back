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

// Procesar cola de notificaciones cada minuto (pausa si no hay jobs)
Schedule::command('queue:work --queue=default --max-time=60 --max-jobs=50 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
