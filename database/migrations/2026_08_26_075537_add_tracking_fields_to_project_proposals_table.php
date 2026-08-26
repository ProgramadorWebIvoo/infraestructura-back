<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de origen de cada oferta (MANUAL/RENEGOCIACION/PORTAL-PROV/
 * SEED-INSERT) para poder calcular ahorro por renegociación filtrando por
 * origen — habilita analítica futura. No agrega ninguna validación que
 * bloquee el registro por anticipo excedido: eso sigue siendo solo
 * advertencia, ahora respaldada por un motivo obligatorio capturado aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->string('origen', 20)->default('PORTAL-PROV')->after('description');
            $table->date('fecha_oferta')->nullable()->after('origen');
            $table->foreignId('created_by')->nullable()->after('fecha_oferta')->constrained('users')->nullOnDelete();
            $table->decimal('precio_anterior', 14, 2)->nullable()->after('created_by');
            $table->decimal('precio_nuevo', 14, 2)->nullable()->after('precio_anterior');
            $table->decimal('diferencia', 14, 2)->nullable()->after('precio_nuevo');
            $table->text('motivo')->nullable()->after('diferencia');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['origen', 'fecha_oferta', 'precio_anterior', 'precio_nuevo', 'diferencia', 'motivo']);
        });
    }
};
