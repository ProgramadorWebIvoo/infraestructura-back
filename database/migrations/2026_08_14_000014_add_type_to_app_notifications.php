<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomía de 6 tipos pedida por el plan de 90 días (1.1): información,
 * éxito, advertencia, error, acción_requerida, prioritario. Antes
 * `app_notifications` no tenía ningún campo de tipo — el único dato binario
 * era `NotificationCatalog::isCritical()`, que ahora alimenta el default de
 * esta columna (ver NotificationDispatcher::typeFor()).
 *
 * Default 'informacion' para filas existentes (backfill): son notificaciones
 * ya generadas antes de esta migración, sin forma de reconstruir su
 * severidad real retroactivamente — 'informacion' es el tipo neutro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->string('type', 20)->default('informacion')->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
