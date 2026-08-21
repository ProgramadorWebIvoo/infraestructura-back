<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Nullable a nivel de columna: el flujo de creación de un documento
        // nuevo (upload sin new_version_of) inserta la fila primero y recién
        // ahí conoce su propio id para autoasignarlo como document_group_id
        // en un segundo update() — un instante transitoriamente nulo entre
        // ambos pasos que un NOT NULL estricto no permitiría. La invariante
        // "nunca queda nulo tras el request" la garantiza el controller, no
        // la BD.
        Schema::table('project_documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_group_id')->nullable()->after('project_id');
            $table->unsignedInteger('version_number')->default(1)->after('document_group_id');
            $table->foreign('document_group_id')->references('id')->on('project_documents')->onDelete('cascade');
            $table->index(['project_id', 'document_group_id']);
        });

        // Backfill: cada fila existente pasa a ser V1 de su propio grupo.
        DB::statement('UPDATE project_documents SET document_group_id = id, version_number = 1 WHERE document_group_id IS NULL');
    }

    public function down()
    {
        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropForeign(['document_group_id']);
        });

        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'document_group_id']);
        });

        Schema::table('project_documents', function (Blueprint $table) {
            $table->dropColumn(['document_group_id', 'version_number']);
        });
    }
};
