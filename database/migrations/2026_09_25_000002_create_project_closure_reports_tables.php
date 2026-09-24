<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informe de cierre del contratista (partidas ejecutadas + fotos). Un informe
 * por obra; su id es el token del enlace público (sin caducidad, reutilizable
 * mientras la obra esté EN_EJECUCION).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_closure_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('project_id', 40)->unique();
            $table->string('contractor_code', 40)->nullable();
            $table->string('contractor_email')->nullable();
            $table->string('status', 30)->default('ABIERTO');
            $table->unsignedInteger('revision')->default(1);
            $table->text('contractor_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('resident_user_id')->nullable();
            $table->text('resident_notes')->nullable();
            $table->timestamp('resident_verified_at')->nullable();
            $table->unsignedBigInteger('audit_user_id')->nullable();
            $table->text('audit_notes')->nullable();
            $table->timestamp('audit_verified_at')->nullable();
            $table->decimal('finiquito_amount', 14, 2)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->string('rejected_by_role', 30)->nullable();
            $table->timestamps();

            $table->foreign('project_id', 'fk_closure_reports_project')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('resident_user_id', 'fk_closure_reports_resident')->references('id')->on('users')->nullOnDelete();
            $table->foreign('audit_user_id', 'fk_closure_reports_audit')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('project_closure_report_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_id');
            $table->string('project_material_id', 40)->nullable();
            $table->string('name', 180);
            $table->string('unit', 80);
            $table->decimal('contracted_quantity', 14, 2);
            $table->decimal('executed_quantity', 14, 2)->default(0);
            $table->decimal('unit_price_usd', 14, 4)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('report_id', 'fk_closure_items_report')->references('id')->on('project_closure_reports')->cascadeOnDelete();
        });

        Schema::create('project_closure_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('report_id');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->string('uploaded_by_type', 20);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->foreign('report_id', 'fk_closure_photos_report')->references('id')->on('project_closure_reports')->cascadeOnDelete();
            $table->foreign('item_id', 'fk_closure_photos_item')->references('id')->on('project_closure_report_items')->nullOnDelete();
            $table->foreign('uploaded_by_user_id', 'fk_closure_photos_user')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_closure_photos');
        Schema::dropIfExists('project_closure_report_items');
        Schema::dropIfExists('project_closure_reports');
    }
};
