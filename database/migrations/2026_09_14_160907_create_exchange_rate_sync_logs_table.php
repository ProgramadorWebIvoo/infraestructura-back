<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exchange_rate_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['SUCCESS', 'FAILURE'])->default('SUCCESS');
            $table->string('source')->nullable(); // DOLARVZLA_API, BCV_SCRAPING
            $table->integer('rates_synced')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_sync_logs');
    }
};
