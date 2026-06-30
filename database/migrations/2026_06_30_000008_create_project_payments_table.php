<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('project_payments', function (Blueprint $table) {
            $table->id();
            $table->string('project_id', 40);
            $table->string('proposal_id', 40)->nullable();
            $table->enum('payment_type', ['ADVANCE', 'FINAL']);
            $table->decimal('amount', 14, 2)->default(0.00);
            $table->date('paid_date');
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['project_id', 'payment_type'], 'uk_project_payment_type');
            $table->index('proposal_id', 'idx_project_payments_proposal');

            $table->foreign('project_id', 'fk_project_payments_project')
                ->references('id')->on('projects')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('proposal_id', 'fk_project_payments_proposal')
                ->references('id')->on('project_proposals')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('project_payments');
    }
};
