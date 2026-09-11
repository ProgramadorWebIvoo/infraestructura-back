<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Congelación de tasa de cambio por proyecto: snapshot inmutable (write-once)
 * de la tasa BCV vigente en el momento de un trigger de negocio (adjudicación
 * de contratista, pago de anticipo, pago de finiquito), para que los montos
 * en Bs. ya contratados/pagados dejen de recalcularse con la tasa del día —
 * requisito fiscal/contable frente a la volatilidad inflacionaria.
 *
 * Nunca se actualiza ni se borra una fila existente (sin `updated_at`, ver
 * modelo): una corrección se registra como una fila nueva con
 * `source = 'MANUAL'` que "supersede" a la anterior vía `superseded_by_id`,
 * preservando el historial completo para auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_rate_freezes', function (Blueprint $table) {
            $table->id();
            $table->string('project_id', 40);
            // CONTRATADO | PAGO_ANTICIPO | PAGO_FINIQUITO
            $table->string('trigger', 20);
            // Moneda base vigente al momento de congelar (Currency::is_base) —
            // no asumida fija a USD, ver ProjectRateFreeze::TRIGGER_*.
            $table->string('base_currency', 3);
            // Bs. por unidad de base_currency (ExchangeRate::bcvRateFor) —
            // nullable: si no hay tasa BCV cargada todavía, se registra igual
            // la intención de congelar en vez de bloquear el trigger de negocio.
            $table->decimal('frozen_rate', 18, 6)->nullable();
            // Monto en moneda base al momento del trigger (ej. total_cost de
            // la propuesta adjudicada, o el monto del pago) — junto con
            // frozen_rate permite reconstruir el monto en Bs. histórico exacto.
            $table->decimal('frozen_amount_base', 18, 4)->nullable();
            $table->foreignId('exchange_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->string('source', 10); // AUTO | MANUAL
            $table->string('reason', 500)->nullable(); // obligatorio si source=MANUAL
            $table->timestamp('frozen_at');
            $table->foreignId('frozen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()->constrained('project_rate_freezes')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->foreign('project_id')->references('id')->on('projects');
            $table->index(['project_id', 'trigger']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_rate_freezes');
    }
};
