<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payment_order_signatures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_order_id');
            $table->unsignedBigInteger('step_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('role', 60);
            $table->timestamp('signed_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('document_hash', 64);
            $table->char('signature_hash', 64);
            $table->timestamp('revoked_at')->nullable();

            $table->foreign('payment_order_id')->references('id')->on('payment_orders')->cascadeOnDelete();
            // nullOnDelete: si se borra el paso de configuración, la firma ya
            // registrada queda como evidencia histórica, no se pierde.
            $table->foreign('step_id')->references('id')->on('payment_signature_steps')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['payment_order_id', 'step_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_order_signatures');
    }
};
