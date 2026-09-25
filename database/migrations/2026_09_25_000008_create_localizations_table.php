<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicaciones registradas (F2-R R7a): cada ubicación (tienda, planta, oficina)
 * tiene exactamente un residente. Una obra con `localization_id` hereda ese
 * residente; sin él (ubicación personalizada) el residente lo elige Auditoría
 * y vive en `projects.resident_user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localizations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('address')->nullable();
            $table->string('city');
            $table->string('region')->nullable();
            $table->string('type', 20)->default('OTRO');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('resident_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['title', 'city']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('localization_id')->nullable()->after('location')
                ->constrained('localizations')->restrictOnDelete();
        });

        // El residente legado (F2) apuntaba a usuarios INFRAESTRUCTURA: ya no es válido.
        DB::table('projects')->update(['resident_user_id' => null]);
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('localization_id');
        });
        Schema::dropIfExists('localizations');
    }
};
