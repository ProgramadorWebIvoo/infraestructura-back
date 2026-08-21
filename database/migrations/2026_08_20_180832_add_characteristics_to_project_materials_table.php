<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->enum('condition', ['NUEVO', 'USADO'])->nullable()->after('estimated_unit_price');
            $table->string('warranty', 120)->nullable()->after('condition');
            $table->string('brand', 120)->nullable()->after('warranty');
            $table->string('model', 120)->nullable()->after('brand');
            $table->text('specifications')->nullable()->after('model');
            $table->text('observations')->nullable()->after('specifications');
        });
    }

    public function down()
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropColumn(['condition', 'warranty', 'brand', 'model', 'specifications', 'observations']);
        });
    }
};
