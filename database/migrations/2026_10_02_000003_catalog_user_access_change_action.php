<?php

use App\Services\NotificationRuleResolver;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cataloga 'Modificacion de accesos de usuario' (AccessAdminController::update):
 * cambiar las vistas/tabs de un usuario es escalación de privilegios, mismo
 * tratamiento que 'Cambio de rol de usuario' (crítica, notifica solo a SUPERADMIN).
 */
return new class extends Migration
{
    private const KEY = 'Modificacion de accesos de usuario';

    public function up(): void
    {
        $now = now();

        DB::table('notification_actions')->updateOrInsert(
            ['key' => self::KEY],
            ['label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => true, 'is_active' => true, 'app_enabled' => true, 'mail_enabled' => true, 'recipient_type' => 'roles', 'created_at' => $now, 'updated_at' => $now],
        );

        DB::table('notification_rules')->upsert(
            [['action' => self::KEY, 'role' => 'SUPERADMIN', 'channel' => 'app', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        DB::table('notification_rules')->where('action', self::KEY)->delete();
        DB::table('notification_actions')->where('key', self::KEY)->delete();

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();
    }
};
