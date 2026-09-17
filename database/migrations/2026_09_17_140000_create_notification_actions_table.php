<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de acciones auditables/notificables — reemplaza el `const
 * ACTIONS` hardcodeado en App\Support\NotificationCatalog. Sin FK a
 * `notification_rules.action` (mismo criterio que roles/notification_rules:
 * catálogo administrable en BD, referencia por string validado en capa de
 * aplicación, no en el motor de BD).
 *
 * `label` nullable: si es null, NotificationCatalog::label() devuelve la
 * propia key tal cual (mismo comportamiento que antes, donde 'label' => null
 * significaba "usar el nombre de la acción como label").
 *
 * El DISPARO real de cada acción (dónde en el código se llama
 * AuditLog::record()) sigue siendo código — este catálogo es solo el
 * metadato (label/agrupación/criticidad), no crea acciones nuevas por sí
 * solo. Por eso no hay endpoint de alta en el panel de administración,
 * solo edición de metadatos y activo/inactivo.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('notification_actions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 180)->unique();
            $table->string('label', 180)->nullable();
            $table->string('group', 40);
            $table->string('scope', 20);
            $table->boolean('critical')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();

        $actions = [
            // Flujo regular de proyectos (AuditLog / visible para Presidencia)
            ['key' => 'Creacion de peticion de obra', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Revision tecnica de calculos y planos', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Confirmacion de presupuesto y envio a licitacion', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de propuesta', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de cuadro comparativo', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Importación automática de propuestas de proveedores', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Eliminacion de propuesta', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Rechazo de cuadro comparativo', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Confirmacion de contratacion', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Liberacion de anticipo', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Liberacion total de fondos', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Reporte de obra finalizada', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Verificacion de finalizacion y calidad de obra', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Congelación manual de tasa de cambio', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Evaluacion inteligente de propuestas', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Envio de invitacion a proveedor', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Rechazo de petición de obra', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Reenvío de petición corregida', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Solicitud de reevaluación a Cierre de Obra', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Reevaluación resuelta, reenviado a Procura', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Obra sin actividad reciente', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Invitacion a proveedor proxima a vencer', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],
            ['key' => 'Racha de rechazos detectada', 'label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true],

            // Documentos de proyecto
            ['key' => 'Carga de hojas de calculo/cubicaciones', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de planos de ingenieria', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de fotografias del sitio de obra', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de correcciones de peticion rechazada', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de nueva version de documento', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Eliminacion de documento adjunto', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de comprobante de pago de anticipo', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],
            ['key' => 'Carga de comprobante de liquidacion final', 'label' => null, 'group' => 'documentos', 'scope' => 'project', 'critical' => false],

            // Accesos públicos (proveedor, sin autenticar)
            ['key' => 'contractor.register', 'label' => 'Registro público de proveedor', 'group' => 'proveedores', 'scope' => 'global', 'critical' => false],
            ['key' => 'invitation.view', 'label' => 'Visualización de invitación (proveedor)', 'group' => 'proveedores', 'scope' => 'global', 'critical' => false],
            ['key' => 'proposal.submit', 'label' => 'Envío de propuesta pública (proveedor)', 'group' => 'proveedores', 'scope' => 'global', 'critical' => false],

            // Sistema / cuenta
            ['key' => 'Solicitud de restablecimiento de contrasena', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => false],

            // Administración: usuarios
            ['key' => 'Creacion de usuario', 'label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => false],
            ['key' => 'Modificacion de usuario', 'label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => false],
            ['key' => 'Cambio de rol de usuario', 'label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => true],
            ['key' => 'Activacion/desactivacion de usuario', 'label' => null, 'group' => 'usuarios', 'scope' => 'global', 'critical' => false],

            // Administración: proveedores (panel admin, distinto del registro público)
            ['key' => 'Alta de proveedor', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Modificacion de proveedor', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Activacion/desactivacion de proveedor', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Calificacion de proveedor', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],

            // Administración: materiales
            ['key' => 'Alta de material', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Modificacion de material', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Activacion/desactivacion de material', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],

            // Administración: configuración de IA (credenciales)
            ['key' => 'Alta de configuracion de IA', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],
            ['key' => 'Modificacion de configuracion de IA', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],
            ['key' => 'Eliminacion de configuracion de IA', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],

            // Administración: CONFIG APP (settings genéricos)
            ['key' => 'Modificacion de configuracion', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => false],

            // Administración: monedas
            ['key' => 'Alta de moneda', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Modificación de moneda', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],
            ['key' => 'Cambio de moneda base', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => true],
            ['key' => 'Eliminación de moneda', 'label' => null, 'group' => 'catalogos', 'scope' => 'global', 'critical' => false],

            // Administración: matriz de notificaciones
            ['key' => 'Modificacion de reglas de notificacion', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],

            // Administración: control de IA por departamento
            ['key' => 'Modificacion de disponibilidad de IA por departamento', 'label' => null, 'group' => 'sistema', 'scope' => 'global', 'critical' => true],

            // Marketing (creacion y aprobacion de piezas publicitarias)
            ['key' => 'Creacion de propuesta de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
            ['key' => 'Modificacion de propuesta de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
            ['key' => 'Eliminacion de propuesta de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
            ['key' => 'Envio a revision de propuesta de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
            ['key' => 'Aprobacion de propuesta de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
            ['key' => 'Rechazo de propuesta de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => true],
            ['key' => 'Carga de adjunto de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
            ['key' => 'Eliminacion de adjunto de marketing', 'label' => null, 'group' => 'marketing', 'scope' => 'global', 'critical' => false],
        ];

        foreach ($actions as &$row) {
            $row['is_active'] = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        foreach (array_chunk($actions, 100) as $chunk) {
            DB::table('notification_actions')->insert($chunk);
        }
    }

    public function down()
    {
        Schema::dropIfExists('notification_actions');
    }
};
