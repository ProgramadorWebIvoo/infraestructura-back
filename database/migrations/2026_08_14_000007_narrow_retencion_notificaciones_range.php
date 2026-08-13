<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `retencion_notificaciones_dias` tenía un rango de 7-365 días, pensado
     * para un uso genérico de retención de datos — pero es un purgado
     * DESTRUCTIVO (`notifications:prune` elimina filas de `app_notifications`
     * sin posibilidad de recuperación), y un valor de meses/años deja
     * demasiado margen para configurarlo mal. Se acota a 1-7 días (24hrs a
     * una semana), rango razonable para una bandeja de notificaciones
     * operativas que no necesitan history de largo plazo.
     *
     * El valor guardado (90, fuera del rango nuevo) se ajusta al tope
     * superior (7) en vez de al mínimo — evita que la primera corrida del
     * comando después de este deploy purgue de golpe con el criterio más
     * agresivo posible sin que nadie lo haya elegido explícitamente.
     */
    public function up(): void
    {
        DB::table('app_settings')
            ->where('key', 'retencion_notificaciones_dias')
            ->update(['min_value' => 1, 'max_value' => 7, 'value' => '7']);
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'retencion_notificaciones_dias')
            ->update(['min_value' => 7, 'max_value' => 365, 'value' => '90']);
    }
};
