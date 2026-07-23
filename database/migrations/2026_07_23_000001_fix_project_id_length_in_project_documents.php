<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Esta migration corrige el type mismatch en MySQL/MariaDB (producción).
        // En SQLite (tests) la migration original ya tiene string(40) y no necesita alter.
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        // La FK project_documents_project_id_foreign puede no existir si la migración original
        // falló al crearla por el mismatch de tipos. Se modifica la columna y se recrea la FK.
        DB::statement('ALTER TABLE `project_documents` MODIFY `project_id` VARCHAR(40) NOT NULL');
        DB::statement('ALTER TABLE `project_documents` ADD CONSTRAINT `project_documents_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' && DB::getDriverName() !== 'mariadb') {
            return;
        }

        DB::statement('ALTER TABLE `project_documents` DROP FOREIGN KEY `project_documents_project_id_foreign`');
        DB::statement('ALTER TABLE `project_documents` MODIFY `project_id` VARCHAR(20) NOT NULL');
    }
};
