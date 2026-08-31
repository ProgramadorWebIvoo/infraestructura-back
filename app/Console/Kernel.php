<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sync de tasas de cambio BCV: Lunes-Viernes a las 10:00 AM VE
        $schedule->command('sync:exchange-rates')
            ->dailyAt('10:00')
            ->weekdays()
            ->name('sync_exchange_rates')
            ->onSuccess(function () {
                \Log::info('✅ Exchange rates synced successfully');
            })
            ->onFailure(function () {
                \Log::error('❌ Exchange rates sync failed');
            });
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
