<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['projects', 'marketing_projects'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('storage_folder', 160)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['projects', 'marketing_projects'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('storage_folder');
            });
        }
    }
};
