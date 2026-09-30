<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina el interruptor maestro (`acciones_con_notificacion_app` /
 * `acciones_con_correo`), que duplicaba a la matriz de roles y fue el origen
 * de acciones que nunca notificaban (p. ej. el correo de reset de contraseña).
 * Su función pasa a dos columnas por acción, editables en la propia fila de
 * la matriz: `app_enabled` / `mail_enabled`. `recipient_type` distingue las
 * acciones dirigidas a roles internos de las de destinatario externo
 * (proveedores, usuario que pide el reset), que no tienen matriz de roles.
 *
 * Preservación de comportamiento: `app_enabled` refleja la pertenencia al
 * maestro de app (setting ausente = todas activas, como antes). `mail_enabled`
 * queda en true salvo que la acción tuviera reglas de correo y estuviera
 * fuera del maestro de correo (hoy silenciadas): esas quedan en false.
 */
return new class extends Migration
{
    private const APP_KEY = 'acciones_con_notificacion_app';
    private const MAIL_KEY = 'acciones_con_correo';

    public function up(): void
    {
        Schema::table('notification_actions', function (Blueprint $table) {
            $table->boolean('app_enabled')->default(true)->after('critical');
            $table->boolean('mail_enabled')->default(true)->after('app_enabled');
            $table->string('recipient_type', 20)->default('roles')->after('mail_enabled');
        });

        $appList = $this->readList(self::APP_KEY);
        $mailList = $this->readList(self::MAIL_KEY);
        $mailRuleActions = DB::table('notification_rules')->where('channel', 'mail')->pluck('action')->unique()->all();

        foreach (DB::table('notification_actions')->get(['id', 'key']) as $action) {
            DB::table('notification_actions')->where('id', $action->id)->update([
                'app_enabled' => $appList === null || in_array($action->key, $appList, true),
                'mail_enabled' => !(in_array($action->key, $mailRuleActions, true) && $mailList !== null && !in_array($action->key, $mailList, true)),
            ]);
        }

        DB::table('app_settings')->whereIn('key', [self::APP_KEY, self::MAIL_KEY])->delete();
    }

    public function down(): void
    {
        $now = now();
        $actions = DB::table('notification_actions')->get(['key', 'app_enabled', 'mail_enabled']);

        $rows = [
            self::APP_KEY => $actions->where('app_enabled', true)->pluck('key')->values()->all(),
            self::MAIL_KEY => $actions->where('mail_enabled', true)->pluck('key')->values()->all(),
        ];
        foreach ($rows as $key => $value) {
            DB::table('app_settings')->insert([
                'group' => 'notificaciones',
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
                'type' => 'json',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('notification_actions', function (Blueprint $table) {
            $table->dropColumn(['app_enabled', 'mail_enabled', 'recipient_type']);
        });
    }

    private function readList(string $key): ?array
    {
        $raw = DB::table('app_settings')->where('key', $key)->value('value');
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
};
