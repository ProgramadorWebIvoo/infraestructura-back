<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los correos de 'Seleccion de contratista pendiente de Presidencia' y
 * 'Rechazo de adjudicacion por Presidencia' tienen reglas en
 * notification_rules pero NotificationDispatcher::isMailActionAllowed()
 * solo envía las acciones listadas en `acciones_con_correo`. Además limpia
 * los cachés (reglas y ajustes, TTL 5 min): las migraciones 130000 y esta
 * escriben directo en BD, y sin invalidar, las reglas nuevas caerían al
 * fallback administrativo hasta que expire el caché.
 */
return new class extends Migration
{
    private const MAIL_ACTIONS = [
        'Seleccion de contratista pendiente de Presidencia',
        'Rechazo de adjudicacion por Presidencia',
    ];

    public function up(): void
    {
        $setting = DB::table('app_settings')->where('key', 'acciones_con_correo')->first();

        if ($setting !== null) {
            $current = json_decode($setting->value, true) ?? [];
            $missing = array_values(array_diff(self::MAIL_ACTIONS, $current));

            if ($missing) {
                DB::table('app_settings')->where('key', 'acciones_con_correo')
                    ->update(['value' => json_encode([...$current, ...$missing]), 'updated_at' => now()]);
            }
        }

        SettingsService::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        $setting = DB::table('app_settings')->where('key', 'acciones_con_correo')->first();

        if ($setting !== null) {
            $remaining = array_values(array_diff(json_decode($setting->value, true) ?? [], self::MAIL_ACTIONS));
            DB::table('app_settings')->where('key', 'acciones_con_correo')
                ->update(['value' => json_encode($remaining), 'updated_at' => now()]);
        }

        SettingsService::forget();
    }
};
