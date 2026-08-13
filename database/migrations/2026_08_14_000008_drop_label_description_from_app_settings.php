<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `label`/`description` dejan de ser columnas mutables en BD — pasan a
     * `App\Support\AppSettingCatalog` (código, versionado, revisable en PR),
     * expuestas vía accessors en `AppSetting` para no cambiar el shape de
     * la API. La tabla ahora guarda solo el dato realmente configurable.
     */
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['label', 'description']);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('label', 150)->default('');
            $table->text('description')->nullable();
        });
    }
};
