<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('material_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('unit', 80);
            $table->decimal('estimated_unit_price', 14, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['name', 'unit'], 'uk_material_catalog_name_unit');
            $table->index('is_active', 'idx_material_catalog_active');
        });
    }

    public function down()
    {
        Schema::dropIfExists('material_catalog');
    }
};
