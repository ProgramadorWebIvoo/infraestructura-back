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
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->unsignedTinyInteger('advance_percent')->nullable()->after('duration_unit');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->dropColumn('advance_percent');
        });
    }
};
