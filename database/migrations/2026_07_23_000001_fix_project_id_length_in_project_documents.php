<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Crear la tabla si no existe (porque la migración original fue empty)
        if (!Schema::hasTable('project_documents')) {
            if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
                // Usar raw SQL para evitar que Laravel agregue FKs automáticamente
                DB::statement(<<<SQL
                    CREATE TABLE `project_documents` (
                        `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        `project_id` varchar(40) NOT NULL,
                        `document_type` enum('CALC','PLANO') NOT NULL,
                        `original_name` varchar(255) NOT NULL,
                        `stored_path` varchar(500) NOT NULL,
                        `mime_type` varchar(120) NULL,
                        `size_bytes` bigint unsigned NOT NULL DEFAULT 0,
                        `uploaded_by` bigint unsigned NULL,
                        `created_at` timestamp NULL,
                        `updated_at` timestamp NULL,
                        KEY `project_documents_project_id_index` (`project_id`),
                        KEY `project_documents_uploaded_by_index` (`uploaded_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                SQL);
            } else {
                // SQLite
                Schema::create('project_documents', function (Blueprint $table) {
                    $table->id();
                    $table->string('project_id', 40);
                    $table->enum('document_type', ['CALC', 'PLANO']);
                    $table->string('original_name', 255);
                    $table->string('stored_path', 500);
                    $table->string('mime_type', 120)->nullable();
                    $table->unsignedBigInteger('size_bytes')->default(0);
                    $table->unsignedBigInteger('uploaded_by')->nullable();
                    $table->timestamps();
                });
            }
        }

        // Agregar FKs si no existen (idempotente para MySQL/MariaDB)
        if (DB::getDriverName() === 'mysql' || DB::getDriverName() === 'mariadb') {
            $projectFkExists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'project_documents')
                ->where('CONSTRAINT_NAME', 'project_documents_project_id_foreign')
                ->exists();

            if (!$projectFkExists) {
                DB::statement('ALTER TABLE `project_documents` ADD CONSTRAINT `project_documents_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE');
            }

            $uploadedByExists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'project_documents')
                ->where('CONSTRAINT_NAME', 'project_documents_uploaded_by_foreign')
                ->exists();

            if (!$uploadedByExists) {
                DB::statement('ALTER TABLE `project_documents` ADD CONSTRAINT `project_documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL');
            }
        }
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
