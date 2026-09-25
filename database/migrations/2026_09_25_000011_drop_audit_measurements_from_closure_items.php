<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auditoría deja de ajustar cantidades (F2-R R4b): rige la medición del residente. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_closure_report_items', function (Blueprint $table) {
            $table->dropColumn(['audit_quantity', 'audit_note']);
        });
    }

    public function down(): void
    {
        Schema::table('project_closure_report_items', function (Blueprint $table) {
            $table->decimal('audit_quantity', 14, 2)->nullable()->after('resident_quantity');
            $table->text('audit_note')->nullable()->after('resident_note');
        });
    }
};
