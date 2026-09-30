<?php

use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Etiquetas legibles (con acentos y sin claves técnicas) para el catálogo de
 * acciones notificables. La `key` no cambia — es el string que se audita —;
 * solo se rellena `label` cuando está vacío, respetando lo que ya se haya
 * editado desde el panel.
 */
return new class extends Migration
{
    private const LABELS = [
        // Catálogos
        'Activacion/desactivacion de material' => 'Activación/desactivación de material',
        'Activacion/desactivacion de proveedor' => 'Activación/desactivación de proveedor',
        'Calificacion de proveedor' => 'Calificación de proveedor',
        'Modificacion de material' => 'Modificación de material',
        'Modificacion de proveedor' => 'Modificación de proveedor',
        'Reclasificación de producto personalizado' => 'Reclasificación de producto personalizado',
        'Carga de tasa de cambio' => 'Carga de tasa de cambio',
        'Sync automático de tasa' => 'Sincronización automática de tasa',
        'Sync automático falló' => 'Falla en la sincronización automática de tasas',

        // Documentos
        'Carga de comprobante de liquidacion final' => 'Carga de comprobante de liquidación final',
        'Carga de correcciones de peticion rechazada' => 'Carga de correcciones de petición rechazada',
        'Carga de fotografias del sitio de obra' => 'Carga de fotografías del sitio de obra',
        'Carga de hojas de calculo/cubicaciones' => 'Carga de hojas de cálculo/cubicaciones',
        'Carga de nueva version de documento' => 'Carga de nueva versión de documento',
        'Eliminacion de documento adjunto' => 'Eliminación de documento adjunto',
        'Carga de evidencia de solicitud de reevaluacion' => 'Carga de evidencia de solicitud de reevaluación',

        // Marketing
        'Aprobacion de propuesta de marketing' => 'Aprobación de propuesta de marketing',
        'Creacion de propuesta de marketing' => 'Creación de propuesta de marketing',
        'Eliminacion de adjunto de marketing' => 'Eliminación de adjunto de marketing',
        'Eliminacion de propuesta de marketing' => 'Eliminación de propuesta de marketing',
        'Envio a revision de propuesta de marketing' => 'Envío a revisión de propuesta de marketing',
        'Modificacion de propuesta de marketing' => 'Modificación de propuesta de marketing',

        // Proveedores (acceso público)
        'contractor.register' => 'Registro público de proveedor',
        'invitation.view' => 'Visualización de invitación (proveedor)',
        'proposal.submit' => 'Envío de propuesta pública (proveedor)',
        'Carga de documento de proveedor' => 'Carga de documento de proveedor',
        'Reemplazo de documento de proveedor' => 'Reemplazo de documento de proveedor',
        'Eliminacion de documento de proveedor' => 'Eliminación de documento de proveedor',
        'Descarga de documento de proveedor' => 'Descarga de documento de proveedor',

        // Flujo de proyectos
        'Aprobacion de adjudicacion por Presidencia' => 'Aprobación de adjudicación por Presidencia',
        'Aprobacion de modificacion de obra' => 'Aprobación de modificación de obra',
        'Asignacion de residente' => 'Asignación de residente',
        'Confirmacion de contratacion' => 'Confirmación de contratación',
        'Confirmacion de presupuesto y envio a licitacion' => 'Confirmación de presupuesto y envío a licitación',
        'Creacion de peticion de obra' => 'Creación de petición de obra',
        'Devolucion de finiquito a Auditoria' => 'Devolución de finiquito a Auditoría',
        'Devolucion de informe de cierre al residente' => 'Devolución de informe de cierre al residente',
        'Eliminacion de propuesta' => 'Eliminación de propuesta',
        'Envio de informe de cierre del contratista' => 'Envío de informe de cierre del contratista',
        'Envio de invitacion a proveedor' => 'Envío de invitación a proveedor',
        'Envio de enlace publico de renegociacion' => 'Envío de enlace público de renegociación',
        'Evaluacion inteligente de propuestas' => 'Evaluación inteligente de propuestas',
        'Evaluacion inteligente de expediente' => 'Evaluación inteligente de expediente',
        'Informes de cierre listos para Auditoria' => 'Informes de cierre listos para Auditoría',
        'Invitacion a proveedor proxima a vencer' => 'Invitación a proveedor próxima a vencer',
        'Liberacion de anticipo' => 'Liberación de anticipo',
        'Liberacion total de fondos' => 'Liberación total de fondos',
        'Rechazo de adjudicacion por Presidencia' => 'Rechazo de adjudicación por Presidencia',
        'Rechazo de informe de cierre por Auditoria' => 'Rechazo de informe de cierre por Auditoría',
        'Rechazo de modificacion de obra' => 'Rechazo de modificación de obra',
        'Revision tecnica de calculos y planos' => 'Revisión técnica de cálculos y planos',
        'Seleccion de contratista pendiente de Presidencia' => 'Selección de contratista pendiente de Presidencia',
        'Sobre-ejecucion de presupuesto' => 'Sobre-ejecución de presupuesto',
        'Solicitud de modificacion de obra' => 'Solicitud de modificación de obra',
        'Verificacion de finalizacion por Auditoria' => 'Verificación de finalización por Auditoría',
        'Verificacion de finalizacion y calidad de obra' => 'Verificación de finalización y calidad de obra',
        'Visto bueno de residente al informe de cierre' => 'Visto bueno de residente al informe de cierre',
        'Congelación manual de tasa de cambio' => 'Congelación manual de tasa de cambio',
        'Generacion de orden de pago de anticipo' => 'Generación de orden de pago de anticipo',
        'Generacion de orden de pago de finiquito' => 'Generación de orden de pago de finiquito',
        'Anulacion de orden de pago' => 'Anulación de orden de pago',
        'Firma de orden de pago' => 'Firma de orden de pago',

        // Sistema y usuarios
        'Alta de configuracion de IA' => 'Alta de configuración de IA',
        'Eliminacion de configuracion de IA' => 'Eliminación de configuración de IA',
        'Modificacion de configuracion' => 'Modificación de configuración',
        'Modificacion de configuracion de IA' => 'Modificación de configuración de IA',
        'Modificacion de disponibilidad de IA por departamento' => 'Modificación de disponibilidad de IA por departamento',
        'Modificacion de reglas de notificacion' => 'Modificación de reglas de notificación',
        'Solicitud de restablecimiento de contrasena' => 'Solicitud de restablecimiento de contraseña',
        'Activacion/desactivacion de usuario' => 'Activación/desactivación de usuario',
        'Creacion de usuario' => 'Creación de usuario',
        'Modificacion de usuario' => 'Modificación de usuario',

        // Configuración administrativa
        'Alta de tipo de documento de proveedor' => 'Alta de tipo de documento de proveedor',
        'Modificacion de tipo de documento de proveedor' => 'Modificación de tipo de documento de proveedor',
        'Activacion/desactivacion de tipo de documento de proveedor' => 'Activación/desactivación de tipo de documento de proveedor',
        'Baja de tipo de documento de proveedor' => 'Baja de tipo de documento de proveedor',
        'Alta de ubicacion' => 'Alta de ubicación',
        'Modificacion de ubicacion' => 'Modificación de ubicación',
        'Activacion/desactivacion de ubicacion' => 'Activación/desactivación de ubicación',
        'Baja de ubicacion' => 'Baja de ubicación',
        'Cambio de residente de ubicacion' => 'Cambio de residente de ubicación',
        'Modificacion de paso de firma' => 'Modificación de paso de firma',
        'Modificacion de rol' => 'Modificación de rol',
        'Activacion/desactivacion de rol' => 'Activación/desactivación de rol',
        'Modificacion de tipo de proyecto' => 'Modificación de tipo de proyecto',
        'Activacion/desactivacion de tipo de proyecto' => 'Activación/desactivación de tipo de proyecto',
        'Modificacion de accion notificable' => 'Modificación de acción notificable',
        'Activacion/desactivacion de accion notificable' => 'Activación/desactivación de acción notificable',
        'Modificacion de configuracion de smtp' => 'Modificación de configuración de correo (SMTP)',
        'Modificacion de configuracion de pusher' => 'Modificación de configuración de tiempo real (Pusher)',
        'Modificacion de configuracion de storage' => 'Modificación de configuración de almacenamiento',

        // Correos de destinatario externo
        'Correo de restablecimiento de contrasena' => 'Correo de restablecimiento de contraseña (al usuario)',
        'Correo de adjudicacion a proveedor' => 'Correo de adjudicación (al proveedor)',
        'Correo de enlace de informe de cierre a proveedor' => 'Correo de enlace de informe de cierre (al proveedor)',
        'Correo de invitacion de renegociacion a proveedor' => 'Correo de invitación de renegociación (al proveedor)',
    ];

    public function up(): void
    {
        foreach (self::LABELS as $key => $label) {
            if ($key === $label) {
                continue;
            }
            DB::table('notification_actions')->where('key', $key)->whereNull('label')->update(['label' => $label, 'updated_at' => now()]);
        }

        NotificationCatalog::forget();
    }

    public function down(): void
    {
        foreach (self::LABELS as $key => $label) {
            DB::table('notification_actions')->where('key', $key)->where('label', $label)->update(['label' => null]);
        }

        NotificationCatalog::forget();
    }
};
