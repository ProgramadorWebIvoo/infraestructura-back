<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Moved to 2026_07_23_000001 to avoid FK constraint issues during fresh migration
    }

    public function down(): void
    {
        Schema::dropIfExists('project_documents');
    }
};
