<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('contractors', function (Blueprint $table) {
            $table->string('code', 30)->primary();
            $table->string('name', 180);
            $table->string('specialty', 180);
            $table->decimal('rating', 3, 1)->default(4.0);
            $table->string('contact', 180);
            $table->enum('registration_source', ['SEED', 'PUBLIC_PORTAL', 'INTERNAL'])->default('PUBLIC_PORTAL');
            $table->enum('status', ['PENDING_REVIEW', 'ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();

            $table->index('name', 'idx_contractors_name');
            $table->index('specialty', 'idx_contractors_specialty');
            $table->index('status', 'idx_contractors_status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('contractors');
    }
};
