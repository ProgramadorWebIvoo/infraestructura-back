<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de tabs por vista — `view_key` referencia view_definitions.key
 * (sin FK: una vista puede existir sin tabs, y el catálogo de tabs se siembra
 * aparte). `tab_key` debe coincidir con el TabDefinition.key hardcodeado en
 * el componente <Tabs> de esa vista (ver Tabs.tsx).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tab_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('view_key');
            $table->string('tab_key');
            $table->string('label');
            $table->boolean('default_active')->default(true);
            $table->timestamps();
            $table->unique(['view_key', 'tab_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tab_definitions');
    }
};
