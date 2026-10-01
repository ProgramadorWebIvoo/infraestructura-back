<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defensa final contra duplicados (las Form Requests dan el 422 legible; esto
 * cubre las carreras entre dos peticiones simultáneas).
 *
 * - supplier_material_proposals.invitation_token: un enlace de proveedor de un
 *   solo uso genera como máximo una propuesta (NULL permitido, la importación manual no lo usa).
 * - contractor_document_types.label: sin tipos de documento homónimos.
 * - catalog_categories(name, parent_id): sin categorías hermanas homónimas.
 * - project_materials(project_id, material_catalog_id): un material del catálogo una sola vez por obra.
 *
 * Requiere BD sin duplicados previos (migrate:fresh o limpieza manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->dropIndex(['invitation_token']);
            $table->unique('invitation_token', 'uq_supplier_proposals_invitation_token');
        });

        Schema::table('contractor_document_types', function (Blueprint $table) {
            $table->unique('label', 'uq_contractor_document_types_label');
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->unique(['name', 'parent_id'], 'uq_catalog_categories_name_parent');
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->unique(['project_id', 'material_catalog_id'], 'uq_project_materials_project_catalog');
        });
    }

    public function down(): void
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropUnique('uq_project_materials_project_catalog');
        });

        Schema::table('catalog_categories', function (Blueprint $table) {
            $table->dropUnique('uq_catalog_categories_name_parent');
        });

        Schema::table('contractor_document_types', function (Blueprint $table) {
            $table->dropUnique('uq_contractor_document_types_label');
        });

        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->dropUnique('uq_supplier_proposals_invitation_token');
            $table->index('invitation_token');
        });
    }
};
