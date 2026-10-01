<?php

use App\Services\NotificationRuleResolver;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La aprobación de Presidencia deja a Procura con una tarea (enviar a Finanzas
 * para liberar el anticipo): además de la bandeja interna, avisa por correo.
 */
return new class extends Migration
{
    private const KEY = 'Aprobacion de adjudicacion por Presidencia';

    public function up(): void
    {
        $now = now();

        DB::table('notification_actions')->where('key', self::KEY)->update(['mail_enabled' => true, 'updated_at' => $now]);

        DB::table('notification_rules')->upsert(
            [['action' => self::KEY, 'role' => 'PROCURA', 'channel' => 'mail', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]],
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );

        // Reactiva el canal app por si la fila quedó apagada en la matriz.
        DB::table('notification_rules')
            ->where(['action' => self::KEY, 'role' => 'PROCURA', 'channel' => 'app'])
            ->update(['enabled' => true, 'updated_at' => $now]);

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->where(['action' => self::KEY, 'role' => 'PROCURA', 'channel' => 'mail'])
            ->delete();

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();
    }
};
