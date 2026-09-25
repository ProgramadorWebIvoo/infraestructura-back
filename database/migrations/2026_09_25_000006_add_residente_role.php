<?php

use App\Support\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rol RESIDENTE (F2-R, R1): ingeniero residente / coordinador de mantenimiento
 * que corrobora en campo. Solo accede a su módulo '/residente' ("Mis obras").
 * Roles::valid() cachea 300 s, por eso se invalida al terminar.
 */
return new class extends Migration
{
    private const ROLE = 'RESIDENTE';
    private const VIEW = '/residente';

    public function up(): void
    {
        $now = now();

        DB::table('roles')->upsert(
            [[
                'key' => self::ROLE,
                'label' => 'Residente',
                'is_active' => true,
                'sort_order' => (int) DB::table('roles')->max('sort_order') + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['key'],
            ['label', 'is_active', 'updated_at'],
        );

        DB::table('view_definitions')->upsert(
            [['key' => self::VIEW, 'label' => 'Residente', 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['label', 'updated_at'],
        );

        $viewId = DB::table('view_definitions')->where('key', self::VIEW)->value('id');

        DB::table('role_view_access')->upsert(
            [['role' => self::ROLE, 'view_definition_id' => $viewId, 'created_at' => $now, 'updated_at' => $now]],
            ['role', 'view_definition_id'],
            ['updated_at'],
        );

        DB::table('tab_definitions')->upsert(
            [['view_key' => self::VIEW, 'tab_key' => 'obras', 'label' => 'Mis obras', 'default_active' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['view_key', 'tab_key'],
            ['label', 'default_active', 'updated_at'],
        );

        Roles::forget();
    }

    public function down(): void
    {
        $viewId = DB::table('view_definitions')->where('key', self::VIEW)->value('id');

        if ($viewId) {
            DB::table('role_view_access')->where('view_definition_id', $viewId)->delete();
            DB::table('user_view_access')->where('view_definition_id', $viewId)->delete();
            DB::table('view_definitions')->where('id', $viewId)->delete();
        }

        DB::table('tab_definitions')->where('view_key', self::VIEW)->delete();
        DB::table('roles')->where('key', self::ROLE)->delete();

        Roles::forget();
    }
};
