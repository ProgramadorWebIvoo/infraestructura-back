<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega al setting `acciones_con_notificacion_app` las acciones nuevas
     * introducidas en la Fase A del plan de notificaciones configurables por
     * rol (usuarios, proveedores/materiales del panel admin, config de IA,
     * evaluación IA con nombre de acción fijo, envío de invitación a
     * proveedor) — sin esto, el interruptor maestro las excluiría por
     * omisión aunque ya estén siendo auditadas/notificadas por el código.
     * No se sobreescribe el valor completo (evita pisar acciones que el
     * SUPERADMIN ya haya desactivado manualmente) — solo se agregan las
     * claves nuevas que falten.
     */
    public function up(): void
    {
        $setting = DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')->first();

        if ($setting === null) {
            return;
        }

        // No usa NotificationCatalog::keys(): esa clase pasó a leer de
        // `notification_actions` (vía Cache) en un commit posterior a esta
        // migración — ninguna de esas dos tablas existe todavía en este
        // punto de un install fresco. Se fija acá el snapshot exacto de
        // claves de NotificationCatalog::ACTIONS vigente cuando esta
        // migración se escribió (commit 6f64147), que es lo que
        // originalmente calculaba `$missing` en cualquier entorno.
        $catalogKeysAtAuthoringTime = [
            'Creacion de peticion de obra',
            'Revision tecnica de calculos y planos',
            'Confirmacion de presupuesto y envio a licitacion',
            'Carga de propuesta',
            'Carga de cuadro comparativo',
            'Importación automática de propuestas de proveedores',
            'Eliminacion de propuesta',
            'Rechazo de cuadro comparativo',
            'Confirmacion de contratacion',
            'Liberacion de anticipo',
            'Liberacion total de fondos',
            'Reporte de obra finalizada',
            'Verificacion de finalizacion y calidad de obra',
            'Evaluacion inteligente de propuestas',
            'Envio de invitacion a proveedor',
            'Carga de hojas de calculo/cubicaciones',
            'Carga de planos de ingenieria',
            'Eliminacion de documento adjunto',
            'contractor.register',
            'invitation.view',
            'proposal.submit',
            'Solicitud de restablecimiento de contrasena',
            'Creacion de usuario',
            'Modificacion de usuario',
            'Cambio de rol de usuario',
            'Activacion/desactivacion de usuario',
            'Alta de proveedor',
            'Modificacion de proveedor',
            'Activacion/desactivacion de proveedor',
            'Calificacion de proveedor',
            'Alta de material',
            'Modificacion de material',
            'Activacion/desactivacion de material',
            'Alta de configuracion de IA',
            'Modificacion de configuracion de IA',
            'Eliminacion de configuracion de IA',
        ];

        $current = json_decode($setting->value, true) ?? [];
        $missing = array_diff($catalogKeysAtAuthoringTime, $current);

        if (empty($missing)) {
            return;
        }

        DB::table('app_settings')
            ->where('key', 'acciones_con_notificacion_app')
            ->update([
                'value' => json_encode([...$current, ...array_values($missing)]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No reversible de forma segura sin conocer qué acciones tenía el
        // SUPERADMIN desactivadas manualmente antes de este deploy — el
        // rollback de este setting, si hace falta, se hace manualmente
        // desde CONFIG APP.
    }
};
