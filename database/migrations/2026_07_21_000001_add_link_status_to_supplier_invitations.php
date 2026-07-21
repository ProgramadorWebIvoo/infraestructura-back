<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invitations', function (Blueprint $table) {
            $table->timestamp('used_at')->nullable()->after('created_at');
            $table->char('replaced_by', 36)->nullable()->after('used_at');

            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invitations', function (Blueprint $table) {
            $table->dropColumn(['used_at', 'replaced_by']);
        });
    }
};
