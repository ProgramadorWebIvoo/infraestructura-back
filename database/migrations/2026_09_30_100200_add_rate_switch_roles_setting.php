<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roles que ven el switch BCV/USDT junto a los montos en Bs. Por defecto
 * todos los roles activos (el modo por defecto de cada usuario sigue siendo
 * BCV: el switch solo permite cambiarlo). Lista JSON editable desde CONFIG APP.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $roles = DB::table('roles')->where('is_active', true)->orderBy('sort_order')->pluck('key')->all();

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'tasa_switch_roles'],
            ['group' => 'sincronizacion_tasa', 'value' => json_encode($roles), 'type' => 'json', 'min_value' => null, 'max_value' => null, 'created_at' => $now, 'updated_at' => $now],
        );

        SettingsService::forget();
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'tasa_switch_roles')->delete();

        SettingsService::forget();
    }
};
