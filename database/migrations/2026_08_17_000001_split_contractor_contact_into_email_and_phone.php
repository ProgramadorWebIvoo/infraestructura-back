<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->renameColumn('contact', 'email');
        });

        Schema::table('contractors', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
        });

        // El contacto pasa a ser "email o teléfono, al menos uno" — email ya
        // no puede seguir siendo NOT NULL a nivel de columna (la regla de
        // "al menos uno" se aplica en UpdateContractorRequest::withValidator,
        // no puede expresarse como constraint de una sola columna).
        Schema::table('contractors', function (Blueprint $table) {
            $table->string('email', 180)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->string('email', 180)->nullable(false)->change();
        });

        Schema::table('contractors', function (Blueprint $table) {
            $table->dropColumn('phone');
        });

        Schema::table('contractors', function (Blueprint $table) {
            $table->renameColumn('email', 'contact');
        });
    }
};
