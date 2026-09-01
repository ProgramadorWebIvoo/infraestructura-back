<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            // Snapshot auditable completo: fecha/hora exacta de fijación de tasas
            $table->timestamp('rate_snapshot_at')->nullable()->after('base_currency_at_import');

            // Fuente de la tasa (BCV, MANUAL, SISTEMA, etc.)
            $table->string('rate_source', 50)->nullable()->after('rate_snapshot_at');

            // Tasas específicas EUR/Bs y USD/Bs vigentes al momento del snapshot
            $table->decimal('rate_eur_to_bs', 12, 6)->nullable()->after('rate_source');
            $table->decimal('rate_usd_to_bs', 12, 6)->nullable()->after('rate_eur_to_bs');

            // Monto calculado en Bs (para auditoría de conversión)
            $table->decimal('amount_in_bs', 16, 2)->nullable()->after('rate_usd_to_bs');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn([
                'rate_snapshot_at',
                'rate_source',
                'rate_eur_to_bs',
                'rate_usd_to_bs',
                'amount_in_bs',
            ]);
        });
    }
};
