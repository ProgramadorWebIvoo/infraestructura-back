<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige un bug de arrastre: `acciones_con_notificacion_app` es una
 * whitelist explícita (ver NotificationDispatcher::isAppNotificationAllowed)
 * que solo se actualizaba a mano, acción por acción, cada vez que se
 * agregaba una nueva (ver 2026_08_14_000002_add_new_actions_to_notification_settings.php).
 * Varias acciones agregadas después de esa migración nunca se sumaron a la
 * whitelist — quedaron auditándose y con reglas en `notification_rules`,
 * pero sin disparar jamás push/bandeja interna, silenciosamente: 'Rechazo de
 * petición de obra', 'Reenvío de petición corregida', 'Solicitud de
 * reevaluación a Cierre de Obra', 'Reevaluación resuelta, reenviado a
 * Procura', 'Obra sin actividad reciente', 'Invitacion a proveedor proxima a
 * vencer', 'Racha de rechazos detectada', toda la familia de marketing, las
 * de moneda, y las de administración de la propia matriz de notificaciones.
 *
 * En vez de listar cada una a mano otra vez (mismo error que ya pasó una
 * vez), esta migración sincroniza automáticamente CUALQUIER acción presente
 * en `notification_actions` (el catálogo real) que falte en el setting —
 * a partir de ahora, agregar una fila nueva a `notification_actions` basta
 * para que quede habilitada por defecto, sin un paso manual adicional que
 * se pueda volver a olvidar.
 */
return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')->first();

        if ($setting === null) {
            return;
        }

        $catalogKeys = DB::table('notification_actions')->pluck('key')->all();
        $current = json_decode($setting->value, true) ?? [];
        $missing = array_values(array_diff($catalogKeys, $current));

        if (empty($missing)) {
            return;
        }

        DB::table('app_settings')
            ->where('key', 'acciones_con_notificacion_app')
            ->update([
                'value' => json_encode([...$current, ...$missing]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No reversible de forma segura sin conocer qué acciones tenía el
        // SUPERADMIN desactivadas manualmente antes de este deploy — igual
        // criterio que 2026_08_14_000002_add_new_actions_to_notification_settings.php.
    }
};
