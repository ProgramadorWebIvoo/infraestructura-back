<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renegotiation_invitations', function (Blueprint $table) {
            $table->char('id', 36)->primary(); // UUID token
            $table->string('project_id', 40);
            $table->string('proposal_id', 40);
            $table->string('contractor_code', 30);
            $table->string('contractor_email', 180);
            $table->timestamp('used_at')->nullable();
            $table->char('replaced_by', 36)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('project_id');
            $table->index('proposal_id');

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');

            $table->foreign('proposal_id')
                ->references('id')
                ->on('project_proposals')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renegotiation_invitations');
    }
};
