<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Cadena 100% configurable (F4 D2): sin roles fijos en código. Un
        // paso apunta a `role` (cualquier miembro de ese rol puede firmar) o
        // a `user_id` (persona específica) — nunca ambos. Sin pasos
        // configurados para un tipo = ese tipo de pago no exige firmas.
        Schema::create('payment_signature_steps', function (Blueprint $table) {
            $table->id();
            $table->enum('payment_type', ['ADVANCE', 'FINAL']);
            $table->unsignedSmallInteger('step_order');
            $table->string('role', 60)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('label', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['payment_type', 'step_order']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_signature_steps');
    }
};
