<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Tests\TestCase;

/**
 * config('app.timezone') es 'UTC' (sin 'app.schedule_timezone'), así que
 * cualquier tarea programada sin ->timezone() explícito corre en UTC, no en
 * hora Venezuela. Bug real detectado: el sync de tasas BCV corría a las
 * 6:00 AM VE (10:00 UTC) en vez de las 10:00 AM VE pretendidas, antes de que
 * el BCV publique la tasa oficial del día — y, peor, vivía en
 * app/Console/Kernel.php, que en este proyecto (bootstrap vía
 * Application::configure(), sin bindear App\Console\Kernel) nunca se
 * invocaba: el schedule real vive en routes/console.php vía el facade
 * Schedule::, igual que las otras tareas (sanctum:clear-expired-tokens,
 * notifications:prune, etc.) — el sync automático nunca corrió.
 */
class SyncExchangeRatesScheduleTest extends TestCase
{
    public function test_sync_exchange_rates_is_scheduled_in_caracas_timezone(): void
    {
        // Fuerza el bootstrap del kernel de consola para que routes/console.php
        // (donde vive el Schedule:: real) se cargue antes de leer el singleton.
        app(ConsoleKernelContract::class)->bootstrap();
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'sync:exchange-rates'));

        $this->assertNotNull($event, 'sync:exchange-rates no está programado (routes/console.php).');
        $this->assertSame('America/Caracas', $event->timezone);
        // dailyAt('10:00') + weekdays() → 10:00 lunes a viernes
        $this->assertSame('0 10 * * 1-5', $event->expression);
    }
}
