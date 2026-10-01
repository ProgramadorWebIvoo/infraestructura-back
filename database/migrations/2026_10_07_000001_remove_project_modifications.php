<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Se retira por completo la funcionalidad de modificaciones de obra (F3): tablas,
 * `original_quantity` del informe de cierre, ajustes de roles, acciones/reglas de
 * notificación y pestañas. Irreversible: los datos de las solicitudes se pierden.
 */
return new class extends Migration
{
    private const SETTINGS = ['modificaciones_roles_solicitantes', 'modificaciones_roles_aprobadores'];

    private const ACTIONS = [
        'Solicitud de modificacion de obra',
        'Aprobacion de modificacion de obra',
        'Rechazo de modificacion de obra',
    ];

    private const WHITELISTS = ['acciones_con_notificacion_app', 'acciones_con_correo'];

    public function up(): void
    {
        Schema::dropIfExists('project_modification_items');
        Schema::dropIfExists('project_modification_requests');

        if (Schema::hasColumn('project_closure_report_items', 'original_quantity')) {
            Schema::table('project_closure_report_items', fn (Blueprint $table) => $table->dropColumn('original_quantity'));
        }

        DB::table('app_settings')->whereIn('key', self::SETTINGS)->delete();
        DB::table('notification_rules')->whereIn('action', self::ACTIONS)->delete();
        DB::table('notification_actions')->whereIn('key', self::ACTIONS)->delete();

        foreach (self::WHITELISTS as $settingKey) {
            $setting = DB::table('app_settings')->where('key', $settingKey)->first();
            if ($setting !== null) {
                $remaining = array_values(array_diff(json_decode($setting->value, true) ?? [], self::ACTIONS));
                DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode($remaining), 'updated_at' => now()]);
            }
        }

        DB::table('tab_definitions')->whereIn('view_key', ['/infraestructura', '/auditoria'])->where('tab_key', 'modificaciones')->delete();

        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        // Irreversible: la funcionalidad fue retirada del producto.
    }
};
