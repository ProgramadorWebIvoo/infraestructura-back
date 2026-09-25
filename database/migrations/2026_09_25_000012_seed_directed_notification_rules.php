<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Avisos dirigidos (F2-R R5): SOLICITANTE (creador de la obra) y
 * RESIDENTE_ASIGNADO (residente efectivo) sustituyen a INFRAESTRUCTURA como rol
 * en las acciones de una obra concreta, porque un aviso por rol llegaría a quien
 * ya no ve la obra (D7).
 */
return new class extends Migration
{
    private const S = 'SOLICITANTE';
    private const R = 'RESIDENTE_ASIGNADO';

    /** acción => canales; `new` = acción nueva del catálogo. */
    private const ACTIONS = [
        'Envio de informe de cierre del contratista'      => ['app' => [self::R, self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => [self::R]],
        'Visto bueno de residente al informe de cierre'   => ['app' => ['AUDITORIA', self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => ['AUDITORIA']],
        'Rechazo de informe de cierre por residente'      => ['app' => [self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => []],
        'Rechazo de informe de cierre por Auditoria'      => ['app' => [self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => [self::S]],
        'Devolucion de informe de cierre al residente'    => ['app' => [self::R, self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => [self::R], 'new' => true],
        'Verificacion de finalizacion por Auditoria'      => ['app' => ['PROCURA', self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => ['PROCURA']],
        'Solicitud de pago de finiquito'                  => ['app' => ['FINANZAS', self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => ['FINANZAS']],
        'Asignacion de residente'                         => ['app' => [self::R, 'AUDITORIA', 'SUPERADMIN', 'ADMIN'], 'mail' => []],
        'Cambio de residente de obra'                     => ['app' => [self::R, 'AUDITORIA', self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => [], 'new' => true],
    ];

    /** Acciones previas de una obra concreta donde INFRAESTRUCTURA pasa a SOLICITANTE. */
    private const SWAP = ['Confirmacion de contratacion', 'Liberacion de anticipo', 'Liberacion total de fondos', 'Rechazo de petición de obra'];

    private const NEW_ACTIONS = ['Devolucion de informe de cierre al residente', 'Cambio de residente de obra'];

    public function up(): void
    {
        $now = now();

        foreach (self::ACTIONS as $action => $cfg) {
            if ($cfg['new'] ?? false) {
                DB::table('notification_actions')->updateOrInsert(
                    ['key' => $action],
                    ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                );
            }
            DB::table('notification_rules')->where('action', $action)->delete();
            $this->insertRules($action, $cfg, $now);
        }

        foreach (self::SWAP as $action) {
            DB::table('notification_rules')->where('action', $action)->where('role', 'INFRAESTRUCTURA')->update(['role' => self::S, 'updated_at' => $now]);
        }

        $this->addToWhitelist('acciones_con_notificacion_app', self::NEW_ACTIONS);
        $this->addToWhitelist('acciones_con_correo', ['Devolucion de informe de cierre al residente']);

        $this->flush();
    }

    public function down(): void
    {
        $now = now();
        foreach (self::SWAP as $action) {
            DB::table('notification_rules')->where('action', $action)->where('role', self::S)->update(['role' => 'INFRAESTRUCTURA', 'updated_at' => $now]);
        }
        DB::table('notification_rules')->whereIn('role', [self::S, self::R])->delete();
        DB::table('notification_rules')->whereIn('action', self::NEW_ACTIONS)->delete();
        DB::table('notification_actions')->whereIn('key', self::NEW_ACTIONS)->delete();
        $this->flush();
    }

    private function insertRules(string $action, array $cfg, $now): void
    {
        $rows = [];
        foreach (['app', 'mail'] as $channel) {
            foreach ($cfg[$channel] as $role) {
                $rows[] = ['action' => $action, 'role' => $role, 'channel' => $channel, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($rows) {
            DB::table('notification_rules')->insert($rows);
        }
    }

    private function addToWhitelist(string $settingKey, array $actions): void
    {
        $setting = DB::table('app_settings')->where('key', $settingKey)->first();
        if ($setting === null) {
            return;
        }
        $current = json_decode($setting->value, true) ?? [];
        $missing = array_values(array_diff($actions, $current));
        if ($missing) {
            DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode([...$current, ...$missing]), 'updated_at' => now()]);
        }
    }

    private function flush(): void
    {
        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }
};
