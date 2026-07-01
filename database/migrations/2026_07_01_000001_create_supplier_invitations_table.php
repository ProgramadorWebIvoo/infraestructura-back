<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invitations', function (Blueprint $table) {
            $table->char('id', 36)->primary(); // UUID token
            $table->string('project_id', 40);
            $table->string('supplier_name', 180);
            $table->string('supplier_company', 180)->nullable();
            $table->string('supplier_contact', 180);
            $table->timestamp('created_at')->useCurrent();

            $table->index('project_id');

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invitations');
    }
};
