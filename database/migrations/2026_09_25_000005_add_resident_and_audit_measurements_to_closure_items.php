<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medición independiente por partida: el residente registra lo que verificó en
 * obra y Auditoría fija la cantidad final. Se conserva la del contratista
 * (executed_quantity) para poder compararlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_closure_report_items', function (Blueprint $table) {
            $table->decimal('resident_quantity', 14, 2)->nullable()->after('executed_quantity');
            $table->text('resident_note')->nullable()->after('note');
            $table->decimal('audit_quantity', 14, 2)->nullable()->after('resident_quantity');
            $table->text('audit_note')->nullable()->after('resident_note');
        });
    }

    public function down(): void
    {
        Schema::table('project_closure_report_items', function (Blueprint $table) {
            $table->dropColumn(['resident_quantity', 'resident_note', 'audit_quantity', 'audit_note']);
        });
    }
};
