<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50);               // 'openai', 'anthropic', 'gemini'
            $table->string('model', 100);                  // 'gpt-4o', 'claude-3-5-sonnet', etc.
            $table->text('api_key');                       // encrypted with Crypt::encryptString
            $table->string('base_url', 255)->nullable();
            $table->integer('max_tokens')->unsigned()->nullable()->default(4096);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_fallback')->default(false);
            $table->integer('sort_order')->unsigned()->default(0);
            $table->timestamps();

            $table->unique(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_configurations');
    }
};
