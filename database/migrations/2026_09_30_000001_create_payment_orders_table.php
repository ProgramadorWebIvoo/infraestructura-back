<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // `current_key` es NULL en las órdenes anuladas y único
        // "project_id:payment_type" en la vigente — MySQL no soporta índices
        // únicos parciales, así que la unicidad de "una vigente por obra y
        // tipo" se logra con esta columna calculada en PHP (ver
        // PaymentOrderService) en vez de una constraint declarativa.
        Schema::create('payment_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number');
            $table->string('project_id', 40);
            $table->string('proposal_id', 40);
            $table->string('contractor_code', 30);
            $table->enum('payment_type', ['ADVANCE', 'FINAL']);
            $table->decimal('amount', 14, 2);
            $table->string('currency', 10);
            $table->decimal('exchange_rate', 14, 6)->nullable();
            $table->json('snapshot');
            $table->enum('status', ['EN_FIRMA', 'FIRMADA', 'PAGADA', 'ANULADA'])->default('EN_FIRMA');
            $table->char('content_hash', 64);
            $table->string('current_key', 80)->nullable()->unique();
            $table->string('void_reason', 500)->nullable();
            $table->unsignedBigInteger('elaborated_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('number');
            $table->index(['project_id', 'payment_type']);

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('proposal_id')->references('id')->on('project_proposals');
            $table->foreign('contractor_code')->references('code')->on('contractors');
            $table->foreign('elaborated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('project_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_order_id')->nullable()->after('proposal_id');
            $table->foreign('payment_order_id')->references('id')->on('payment_orders')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('project_payments', function (Blueprint $table) {
            $table->dropForeign(['payment_order_id']);
            $table->dropColumn('payment_order_id');
        });

        Schema::dropIfExists('payment_orders');
    }
};
