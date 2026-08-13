<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rango numérico opcional para settings integer/float que representan
     * porcentajes u otros valores acotados — el panel de administración usa
     * estos límites para validar en el input, y el backend los reaplica en
     * AppSettingController::update() para no confiar solo en el frontend.
     */
    public function up()
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->decimal('min_value', 10, 2)->nullable()->after('type');
            $table->decimal('max_value', 10, 2)->nullable()->after('min_value');
        });

        $ranges = [
            'anticipo_maximo_porcentaje' => [0, 100],
            'semaforo_umbral_verde' => [0, 100],
            'semaforo_umbral_amarillo' => [0, 100],
            'semaforo_umbral_naranja' => [0, 100],
            'alerta_precio_umbral_porcentaje' => [0, 100],
        ];

        foreach ($ranges as $key => [$min, $max]) {
            DB::table('app_settings')->where('key', $key)->update([
                'min_value' => $min,
                'max_value' => $max,
            ]);
        }
    }

    public function down()
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['min_value', 'max_value']);
        });
    }
};
