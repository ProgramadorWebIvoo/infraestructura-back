<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activación/desactivación de funciones IA en dos niveles, por
     * departamento (App\Support\Roles::VALID) — mismo patrón que
     * notification_rules (catálogo en código, tabla solo guarda el estado):
     *
     *   - `action` NULL   → interruptor MAESTRO del departamento (apaga toda
     *     la IA de ese departamento de un golpe).
     *   - `action` = clave de App\Support\AiFeatureCatalog → interruptor
     *     ESPECÍFICO de esa acción dentro del departamento.
     *
     * Sin fila configurada = habilitado por defecto (así Procura/Cierre de
     * Obra, que ya usan IA hoy, no pierden la función al desplegar esto).
     * Las escrituras van siempre por App\Services\AiFeatureGate::setEnabled(),
     * que hace updateOrCreate — evita duplicados sin depender de un índice
     * único con NULL (MySQL no los deduplica).
     */
    public function up(): void
    {
        Schema::create('ai_feature_toggles', function (Blueprint $table) {
            $table->id();
            $table->string('department', 40);
            $table->string('action', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['department', 'action'], 'idx_ai_feature_toggles_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feature_toggles');
    }
};
