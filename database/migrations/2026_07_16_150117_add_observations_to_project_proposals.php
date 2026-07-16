<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->text('observations')
                ->nullable()
                ->after('description')
                ->comment('Contexto adicional: tasa dólar, tipo de divisa, garantías, disponibilidad de materiales, etc.');
        });
    }

    public function down()
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn('observations');
        });
    }
};
