<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de pagos para el Histórico de Obras: moneda explícita (los
 * montos son en la moneda base USD), banco/referencia opcionales y FK al
 * comprobante que respalda el pago. Aditiva: no altera el flujo ADVANCE/FINAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_payments', function (Blueprint $table) {
            $table->string('currency', 3)->default('USD')->after('amount');
            $table->string('bank', 100)->nullable()->after('paid_date');
            $table->string('reference', 100)->nullable()->after('bank');
            $table->unsignedBigInteger('comprobante_document_id')->nullable()->after('reference');
            $table->foreign('comprobante_document_id', 'fk_project_payments_comprobante')
                ->references('id')->on('project_documents')->nullOnDelete();
        });

        $map = ['ADVANCE' => 'COMPROBANTE_ANTICIPO', 'FINAL' => 'COMPROBANTE_FINIQUITO'];
        foreach ($map as $paymentType => $documentType) {
            $payments = DB::table('project_payments')->where('payment_type', $paymentType)->get(['id', 'project_id']);
            foreach ($payments as $payment) {
                $documentId = DB::table('project_documents')
                    ->where('project_id', $payment->project_id)
                    ->where('document_type', $documentType)
                    ->whereNull('deleted_at')
                    ->max('id');
                if ($documentId) {
                    DB::table('project_payments')->where('id', $payment->id)->update(['comprobante_document_id' => $documentId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('project_payments', function (Blueprint $table) {
            $table->dropForeign('fk_project_payments_comprobante');
            $table->dropColumn(['currency', 'bank', 'reference', 'comprobante_document_id']);
        });
    }
};
