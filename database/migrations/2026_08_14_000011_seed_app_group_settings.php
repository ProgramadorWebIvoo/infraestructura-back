<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Puebla el grupo "app" (Aplicación) de CONFIG APP — hasta ahora
     * prácticamente vacío. Reemplaza umbrales/límites hardcodeados por
     * settings editables sin deploy: DashboardSummaryService::STALLED_THRESHOLD_DAYS,
     * el límite de tamaño/cantidad de archivos de StoreProjectDocumentRequest,
     * SupplierInvitation::DEFAULT_VALIDITY_DAYS, y el timeout de sesión por
     * inactividad de useAuth.ts (frontend).
     *
     * documento_tamano_maximo_mb usa default 25 (no 50, el valor hardcodeado
     * previo): el servidor PHP real tiene upload_max_filesize/post_max_size
     * en 40MB, así que el límite de 50MB nunca fue alcanzable — el request
     * fallaba a nivel de PHP con un error críptico antes de llegar a la
     * validación de Laravel. max_value=40 evita recrear esa inconsistencia
     * desde la UI.
     */
    public function up(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            [
                'group' => 'app',
                'key' => 'proyecto_estancado_umbral_dias',
                'value' => '14',
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 90,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'app',
                'key' => 'documento_tamano_maximo_mb',
                'value' => '25',
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'app',
                'key' => 'documento_cantidad_maxima_archivos',
                'value' => '10',
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'app',
                'key' => 'invitacion_proveedor_vigencia_dias',
                'value' => '7',
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'app',
                'key' => 'sesion_inactividad_minutos',
                'value' => '30',
                'type' => 'integer',
                'min_value' => 5,
                'max_value' => 120,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'proyecto_estancado_umbral_dias',
            'documento_tamano_maximo_mb',
            'documento_cantidad_maxima_archivos',
            'invitacion_proveedor_vigencia_dias',
            'sesion_inactividad_minutos',
        ])->delete();
    }
};
