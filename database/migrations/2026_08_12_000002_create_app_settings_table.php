<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 40); // moneda, presupuesto, notificaciones, fiscal, alertas, app
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string'); // string|integer|float|boolean|json
            $table->string('label', 150); // etiqueta legible para el panel de administración
            $table->text('description')->nullable();
            $table->timestamps();
        });

        $this->seedDefaults();
    }

    public function down()
    {
        Schema::dropIfExists('app_settings');
    }

    /**
     * Defaults de arranque para que la app funcione con los mismos valores
     * que hoy están hardcodeados en el código (anticipo 100%, semáforo
     * 80/95/100) — CONFIG APP los hace editables sin deploy, no cambia el
     * comportamiento actual al desplegar esta migración.
     */
    private function seedDefaults(): void
    {
        $now = now();

        $defaults = [
            // Moneda
            ['group' => 'moneda', 'key' => 'moneda_base', 'value' => 'USD', 'type' => 'string', 'label' => 'Moneda base', 'description' => 'Moneda en la que se registran los montos por defecto.'],

            // Presupuesto / anticipo
            ['group' => 'presupuesto', 'key' => 'anticipo_maximo_porcentaje', 'value' => '100', 'type' => 'integer', 'label' => 'Anticipo máximo (%)', 'description' => 'Porcentaje máximo de anticipo permitido en una oferta/propuesta.'],
            ['group' => 'presupuesto', 'key' => 'semaforo_umbral_verde', 'value' => '80', 'type' => 'integer', 'label' => 'Semáforo — hasta (%) verde', 'description' => '% de ejecución presupuestaria hasta el cual el semáforo se muestra verde.'],
            ['group' => 'presupuesto', 'key' => 'semaforo_umbral_amarillo', 'value' => '95', 'type' => 'integer', 'label' => 'Semáforo — hasta (%) amarillo', 'description' => '% de ejecución presupuestaria hasta el cual el semáforo se muestra amarillo (por encima del umbral verde).'],
            ['group' => 'presupuesto', 'key' => 'semaforo_umbral_naranja', 'value' => '100', 'type' => 'integer', 'label' => 'Semáforo — hasta (%) naranja', 'description' => '% de ejecución presupuestaria hasta el cual el semáforo se muestra naranja (por encima del umbral amarillo); superado esto pasa a rojo.'],

            // Ratings
            ['group' => 'ratings', 'key' => 'rating_minimo_proveedor', 'value' => '1', 'type' => 'integer', 'label' => 'Rating mínimo — proveedor', 'description' => 'Escala mínima de calificación para proveedores.'],
            ['group' => 'ratings', 'key' => 'rating_maximo_proveedor', 'value' => '5', 'type' => 'integer', 'label' => 'Rating máximo — proveedor', 'description' => 'Escala máxima de calificación para proveedores.'],

            // Notificaciones / correos por departamento
            ['group' => 'notificaciones', 'key' => 'correo_procura', 'value' => null, 'type' => 'string', 'label' => 'Correo — Procura', 'description' => 'Correo de contacto del departamento de Procura para notificaciones automáticas.'],
            ['group' => 'notificaciones', 'key' => 'correo_finanzas', 'value' => null, 'type' => 'string', 'label' => 'Correo — Finanzas', 'description' => 'Correo de contacto del departamento de Finanzas para notificaciones automáticas.'],
            ['group' => 'notificaciones', 'key' => 'correo_cierre_obra', 'value' => null, 'type' => 'string', 'label' => 'Correo — Cierre de Obra', 'description' => 'Correo de contacto del departamento de Cierre de Obra para notificaciones automáticas.'],
            ['group' => 'notificaciones', 'key' => 'correo_auditoria', 'value' => null, 'type' => 'string', 'label' => 'Correo — Auditoría externa', 'description' => 'Correo de auditoría externa (opcional) para copiar en notificaciones de pago.'],

            // Datos fiscales
            ['group' => 'fiscal', 'key' => 'razon_social', 'value' => null, 'type' => 'string', 'label' => 'Razón social', 'description' => 'Razón social de la empresa, usada en comprobantes de pago.'],
            ['group' => 'fiscal', 'key' => 'rif', 'value' => null, 'type' => 'string', 'label' => 'RIF / número fiscal', 'description' => 'Número de identificación fiscal de la empresa.'],
            ['group' => 'fiscal', 'key' => 'direccion_fiscal', 'value' => null, 'type' => 'string', 'label' => 'Dirección fiscal', 'description' => 'Dirección fiscal registrada de la empresa.'],

            // Alertas de precio
            ['group' => 'alertas', 'key' => 'alerta_precio_umbral_porcentaje', 'value' => '25', 'type' => 'integer', 'label' => 'Alerta de precio — umbral (%)', 'description' => 'Porcentaje sobre el promedio histórico a partir del cual un precio se marca como fuera de rango.'],

            // Inflación
            ['group' => 'inflacion', 'key' => 'inflacion_referencia_anual_porcentaje', 'value' => '0', 'type' => 'float', 'label' => 'Inflación de referencia anual (%)', 'description' => 'Tasa de inflación anual de referencia usada en el análisis de precios.'],

            // Bloqueo de cambios de app
            ['group' => 'app', 'key' => 'cambios_bloqueados', 'value' => 'false', 'type' => 'boolean', 'label' => 'Bloquear cambios de aplicación', 'description' => 'Cuando está activo, evita despliegues/cambios no planificados (uso administrativo).'],

            // Notificaciones — acciones que disparan correo (antes hardcodeado
            // en NotificationDispatcher::MAIL_ACTIONS, Fase 1.1/1.2)
            [
                'group' => 'notificaciones',
                'key' => 'acciones_con_correo',
                'value' => json_encode([
                    'Rechazo de cuadro comparativo',
                    'Confirmacion de contratacion',
                    'Liberacion de anticipo',
                    'Liberacion total de fondos',
                ]),
                'type' => 'json',
                'label' => 'Acciones que envían correo',
                'description' => 'Lista de acciones auditadas que además de push y bandeja interna disparan un correo (para no generar spam con cada acción).',
            ],
        ];

        foreach ($defaults as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('app_settings')->insert($defaults);
    }
};
