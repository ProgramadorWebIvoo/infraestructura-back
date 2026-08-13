<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reajusta el catálogo de settings de notificaciones (Fase 1.4 CONFIG APP):
     *  - Elimina los 4 "correos por departamento" (correo_procura/finanzas/
     *    cierre_obra/auditoria): nunca tuvieron consumidor — ProjectActionMail
     *    envía siempre al usuario destinatario real (mismo que recibe la
     *    notificación in-app, vía NotificationDispatcher::recipientsFor()),
     *    jamás a una dirección fija de departamento. Eran configuración muerta.
     *  - Agrega `acciones_con_notificacion_app`: mismo patrón que
     *    `acciones_con_correo` pero para push + bandeja interna. Hoy TODAS las
     *    acciones auditadas notifican sin excepción; este setting permite
     *    silenciar acciones de bajo valor sin tocar el correo.
     *  - Agrega `retencion_notificaciones_dias`: días que se conservan las
     *    notificaciones en `app_notifications` antes de purgarse (comando
     *    programado `notifications:prune`, separado de esta migración).
     *  - Agrega `polling_notificaciones_segundos` y
     *    `polling_dashboard_segundos`: intervalos de refresco configurables,
     *    con rango min/max para evitar que un valor absurdo sature el backend.
     */
    public function up(): void
    {
        DB::table('app_settings')
            ->whereIn('key', ['correo_procura', 'correo_finanzas', 'correo_cierre_obra', 'correo_auditoria'])
            ->delete();

        $now = now();

        $allActions = [
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
            'Carga de hojas de calculo/cubicaciones',
            'Carga de planos de ingenieria',
            'Eliminacion de documento adjunto',
        ];

        DB::table('app_settings')->insert([
            [
                'group' => 'notificaciones',
                'key' => 'acciones_con_notificacion_app',
                'value' => json_encode($allActions),
                'type' => 'json',
                'min_value' => null,
                'max_value' => null,
                'label' => 'Acciones que envían notificación (app)',
                'description' => 'Lista de acciones auditadas que generan notificación push y bandeja interna. Por defecto, todas — quite las que no ameriten aviso para no generar ruido.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'notificaciones',
                'key' => 'retencion_notificaciones_dias',
                'value' => '90',
                'type' => 'integer',
                'min_value' => 7,
                'max_value' => 365,
                'label' => 'Retención de notificaciones (días)',
                'description' => 'Días que se conservan las notificaciones en la bandeja interna antes de purgarse automáticamente.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'notificaciones',
                'key' => 'polling_notificaciones_segundos',
                'value' => '8',
                'type' => 'integer',
                'min_value' => 5,
                'max_value' => 120,
                'label' => 'Frecuencia de consulta de notificaciones (segundos)',
                'description' => 'Cada cuánto la app consulta el servidor por notificaciones nuevas.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'notificaciones',
                'key' => 'polling_dashboard_segundos',
                'value' => '25',
                'type' => 'integer',
                'min_value' => 10,
                'max_value' => 300,
                'label' => 'Frecuencia de actualización del dashboard (segundos)',
                'description' => 'Cada cuánto se refresca el resumen ejecutivo del dashboard de Presidencia.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'acciones_con_notificacion_app',
            'retencion_notificaciones_dias',
            'polling_notificaciones_segundos',
            'polling_dashboard_segundos',
        ])->delete();

        $now = now();

        DB::table('app_settings')->insert([
            ['group' => 'notificaciones', 'key' => 'correo_procura', 'value' => null, 'type' => 'string', 'label' => 'Correo — Procura', 'description' => 'Correo de contacto del departamento de Procura para notificaciones automáticas.', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'notificaciones', 'key' => 'correo_finanzas', 'value' => null, 'type' => 'string', 'label' => 'Correo — Finanzas', 'description' => 'Correo de contacto del departamento de Finanzas para notificaciones automáticas.', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'notificaciones', 'key' => 'correo_cierre_obra', 'value' => null, 'type' => 'string', 'label' => 'Correo — Cierre de Obra', 'description' => 'Correo de contacto del departamento de Cierre de Obra para notificaciones automáticas.', 'created_at' => $now, 'updated_at' => $now],
            ['group' => 'notificaciones', 'key' => 'correo_auditoria', 'value' => null, 'type' => 'string', 'label' => 'Correo — Auditoría externa', 'description' => 'Correo de auditoría externa (opcional) para copiar en notificaciones de pago.', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
};
