<?php

use App\Services\NotificationRuleResolver;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cataloga las acciones que el código ya auditaba pero que no existían en
 * `notification_actions` (invisibles en CONFIG APP y sin notificación, porque
 * el interruptor maestro solo dejaba pasar lo catalogado). Cada una nace con
 * reglas explícitas por rol; las ruidosas nacen inactivas.
 *
 * También: reglas explícitas para las acciones que solo se apoyaban en el
 * fallback administrativo (mismo resultado, ahora visible en la matriz),
 * retiro de las acciones sin emisor, y alta de los correos de destinatario
 * externo (proveedores / usuario que pide el reset), que no llevan roles.
 */
return new class extends Migration
{
    private const ADMIN = ['SUPERADMIN', 'ADMIN'];

    /** key => [group, scope, critical, active, app roles, mail roles] */
    private function catalog(): array
    {
        $a = self::ADMIN;

        return [
            // Configuración administrativa
            'Alta de categoría de catálogo' => ['configuracion', 'global', false, true, $a, []],
            'Modificación de categoría de catálogo' => ['configuracion', 'global', false, true, $a, []],
            'Eliminación de categoría de catálogo' => ['configuracion', 'global', false, true, $a, []],
            'Alta de tipo de documento de proveedor' => ['configuracion', 'global', false, true, $a, []],
            'Modificacion de tipo de documento de proveedor' => ['configuracion', 'global', false, true, $a, []],
            'Activacion/desactivacion de tipo de documento de proveedor' => ['configuracion', 'global', false, true, $a, []],
            'Baja de tipo de documento de proveedor' => ['configuracion', 'global', false, true, $a, []],
            'Alta de ubicacion' => ['configuracion', 'global', false, true, $a, []],
            'Modificacion de ubicacion' => ['configuracion', 'global', false, true, $a, []],
            'Activacion/desactivacion de ubicacion' => ['configuracion', 'global', false, true, $a, []],
            'Baja de ubicacion' => ['configuracion', 'global', false, true, $a, []],
            'Cambio de residente de ubicacion' => ['configuracion', 'global', true, true, $a, []],
            'Alta de paso de firma' => ['configuracion', 'global', false, true, $a, []],
            'Modificacion de paso de firma' => ['configuracion', 'global', false, true, $a, []],
            'Baja de paso de firma' => ['configuracion', 'global', false, true, $a, []],
            'Alta de rol' => ['configuracion', 'global', true, true, ['SUPERADMIN'], []],
            'Modificacion de rol' => ['configuracion', 'global', true, true, ['SUPERADMIN'], []],
            'Activacion/desactivacion de rol' => ['configuracion', 'global', true, true, ['SUPERADMIN'], []],
            'Alta de tipo de proyecto' => ['configuracion', 'global', false, true, $a, []],
            'Modificacion de tipo de proyecto' => ['configuracion', 'global', false, true, $a, []],
            'Activacion/desactivacion de tipo de proyecto' => ['configuracion', 'global', false, true, $a, []],
            'Modificacion de accion notificable' => ['configuracion', 'global', false, true, ['SUPERADMIN'], []],
            'Activacion/desactivacion de accion notificable' => ['configuracion', 'global', false, true, ['SUPERADMIN'], []],
            'Modificacion de configuracion de smtp' => ['configuracion', 'global', true, true, ['SUPERADMIN'], []],
            'Modificacion de configuracion de pusher' => ['configuracion', 'global', true, true, ['SUPERADMIN'], []],
            'Modificacion de configuracion de storage' => ['configuracion', 'global', true, true, ['SUPERADMIN'], []],

            // Tasas de cambio
            'Carga de tasa de cambio' => ['catalogos', 'global', false, true, $a, []],
            'Sync automático de tasa' => ['catalogos', 'global', false, false, $a, []],
            'Sync automático falló' => ['catalogos', 'global', true, true, $a, []],

            // Catálogo / proveedores
            'Reclasificación de producto personalizado' => ['catalogos', 'global', false, true, ['CATALOGOS', ...$a], []],
            'Carga de documento de proveedor' => ['proveedores', 'global', false, true, ['CATALOGOS', ...$a], []],
            'Reemplazo de documento de proveedor' => ['proveedores', 'global', false, true, ['CATALOGOS', ...$a], []],
            'Eliminacion de documento de proveedor' => ['proveedores', 'global', false, true, ['CATALOGOS', ...$a], []],
            'Descarga de documento de proveedor' => ['proveedores', 'global', false, false, $a, []],

            // Flujo de obra y pagos
            'Generacion de orden de pago de anticipo' => ['proyectos', 'project', false, true, ['FINANZAS', 'SOLICITANTE', ...$a], []],
            'Generacion de orden de pago de finiquito' => ['proyectos', 'project', false, true, ['FINANZAS', 'SOLICITANTE', ...$a], []],
            'Anulacion de orden de pago' => ['proyectos', 'project', true, true, ['FINANZAS', 'SOLICITANTE', ...$a], []],
            'Firma de orden de pago' => ['proyectos', 'project', false, true, ['FINANZAS', ...$a], []],
            'Evaluacion inteligente de expediente' => ['proyectos', 'project', false, true, ['AUDITORIA', ...$a], []],
            'Renegociación de propuesta' => ['proyectos', 'project', false, true, ['PROCURA', ...$a], []],
            'Renegociación de propuesta (enlace público)' => ['proyectos', 'project', false, true, ['PROCURA', ...$a], []],
            'Envio de enlace publico de renegociacion' => ['proyectos', 'project', false, true, ['PROCURA', ...$a], []],
            'Carga de evidencia de solicitud de reevaluacion' => ['documentos', 'project', false, true, ['AUDITORIA', ...$a], []],
        ];
    }

    /** Correos de destinatario externo: sin roles, solo activar/desactivar el canal correo. */
    private const EXTERNAL = [
        'Correo de restablecimiento de contrasena' => 'usuarios',
        'Correo de adjudicacion a proveedor' => 'proveedores',
        'Correo de enlace de informe de cierre a proveedor' => 'proveedores',
        'Correo de invitacion de renegociacion a proveedor' => 'proveedores',
    ];

    /** Acciones existentes que solo dependían del fallback (SUPERADMIN+ADMIN en app). */
    private const FALLBACK_ONLY = [
        'Solicitud de restablecimiento de contrasena',
        'Modificacion de configuracion',
        'Alta de moneda',
        'Modificación de moneda',
        'Cambio de moneda base',
        'Eliminación de moneda',
        'Modificacion de reglas de notificacion',
        'Modificacion de disponibilidad de IA por departamento',
    ];

    /** Sin ningún emisor en el código. */
    private const RETIRED = [
        'Reporte de obra finalizada',
        'Verificacion de finalizacion y calidad de obra',
    ];

    public function up(): void
    {
        $now = now();
        $rules = [];

        foreach ($this->catalog() as $key => [$group, $scope, $critical, $active, $app, $mail]) {
            DB::table('notification_actions')->updateOrInsert(
                ['key' => $key],
                ['label' => null, 'group' => $group, 'scope' => $scope, 'critical' => $critical, 'is_active' => $active, 'app_enabled' => true, 'mail_enabled' => true, 'recipient_type' => 'roles', 'created_at' => $now, 'updated_at' => $now],
            );
            $rules = [...$rules, ...$this->rows($key, $app, $mail, $now)];
        }

        foreach (self::EXTERNAL as $key => $group) {
            DB::table('notification_actions')->updateOrInsert(
                ['key' => $key],
                ['label' => null, 'group' => $group, 'scope' => 'global', 'critical' => false, 'is_active' => true, 'app_enabled' => false, 'mail_enabled' => true, 'recipient_type' => 'external', 'created_at' => $now, 'updated_at' => $now],
            );
        }

        foreach (self::FALLBACK_ONLY as $key) {
            if (!DB::table('notification_rules')->where('action', $key)->exists()) {
                $rules = [...$rules, ...$this->rows($key, self::ADMIN, [], $now)];
            }
        }

        DB::table('notification_rules')->upsert($rules, ['action', 'role', 'channel'], ['enabled', 'updated_at']);
        DB::table('notification_actions')->whereIn('key', self::RETIRED)->update(['is_active' => false, 'updated_at' => $now]);

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        $keys = [...array_keys($this->catalog()), ...array_keys(self::EXTERNAL)];

        DB::table('notification_rules')->whereIn('action', $keys)->delete();
        DB::table('notification_actions')->whereIn('key', $keys)->delete();
        DB::table('notification_rules')->whereIn('action', self::FALLBACK_ONLY)->delete();
        DB::table('notification_actions')->whereIn('key', self::RETIRED)->update(['is_active' => true]);

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();
    }

    private function rows(string $key, array $app, array $mail, $now): array
    {
        $rows = [];
        foreach (['app' => $app, 'mail' => $mail] as $channel => $roles) {
            foreach (array_unique($roles) as $role) {
                $rows[] = ['action' => $key, 'role' => $role, 'channel' => $channel, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        return $rows;
    }
};
