<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('endpoint', 100)->default('evaluate-proposals');
            $table->integer('prompt_tokens')->unsigned()->nullable();
            $table->integer('completion_tokens')->unsigned()->nullable();
            $table->integer('total_tokens')->unsigned()->nullable();
            $table->decimal('cost_estimate', 10, 6)->nullable();
            $table->integer('response_time_ms')->unsigned()->nullable();
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
