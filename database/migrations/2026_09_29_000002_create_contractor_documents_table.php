<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // `document_group_id` autorreferenciado, igual que project_documents:
        // cada reemplazo crea una versión nueva y conserva las anteriores.
        Schema::create('contractor_documents', function (Blueprint $table) {
            $table->id();
            $table->string('contractor_code', 30);
            $table->unsignedBigInteger('document_type_id');
            $table->unsignedBigInteger('document_group_id')->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->enum('source', ['PUBLIC_PORTAL', 'INTERNAL'])->default('INTERNAL');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('contractor_code')->references('code')->on('contractors')->cascadeOnDelete();
            $table->foreign('document_type_id')->references('id')->on('contractor_document_types');
            $table->foreign('document_group_id')->references('id')->on('contractor_documents')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['contractor_code', 'document_type_id']);
            $table->index('document_group_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('contractor_documents');
    }
};
