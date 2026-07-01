<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('role');
            $table->string('user_name_snapshot', 180)->nullable()->after('user_id');

            $table->foreign('user_id', 'fk_audit_logs_user')
                ->references('id')->on('users')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign('fk_audit_logs_user');
            $table->dropColumn(['user_id', 'user_name_snapshot']);
        });
    }
};
