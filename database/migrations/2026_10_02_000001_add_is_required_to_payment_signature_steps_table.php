<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Un paso no obligatorio queda registrado si alguien lo firma, pero
        // nunca bloquea las transiciones del circuito (F4 Bloque C, ajuste
        // post-lanzamiento: antes solo existía "sin pasos = sin firmas",
        // faltaba el matiz "paso configurado mas no bloqueante").
        Schema::table('payment_signature_steps', function (Blueprint $table) {
            $table->boolean('is_required')->default(true)->after('label');
        });
    }

    public function down()
    {
        Schema::table('payment_signature_steps', function (Blueprint $table) {
            $table->dropColumn('is_required');
        });
    }
};
