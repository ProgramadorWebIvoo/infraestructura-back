<?php

namespace App\Services;

use App\Models\SystemKeyConfig;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Pusher\Pusher;
use Pusher\PusherException;

/**
 * Credenciales de infraestructura (SMTP, Pusher) editables desde Configuración >
 * Configuración de Keys, sin tocar .env ni redeployar. Cada grupo es una fila de
 * `system_key_configs` con el payload completo cifrado en `data`.
 *
 * `applyRuntimeConfig()` sobreescribe `config('mail.*')`/`config('broadcasting.*')`
 * en memoria para el request/proceso actual — se llama en el boot de
 * AppServiceProvider (arranque de cada worker) y de nuevo tras cada `update()`
 * para que el propio request que guarda ya use la config nueva (ej. al probar
 * conexión inmediatamente después de guardar).
 */
class SystemKeyConfigService
{
    /** Campos por grupo — fuente única para validar, guardar y aplicar al runtime. */
    public const SCHEMAS = [
        'smtp' => ['host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'],
        'pusher' => ['app_id', 'key', 'secret', 'cluster'],
    ];

    /** Campos que nunca se devuelven completos a la API — solo hasValue + últimos 4 chars. */
    public const SECRET_FIELDS = [
        'smtp' => ['password'],
        'pusher' => ['secret', 'key'],
    ];

    public function getRaw(string $group): ?SystemKeyConfig
    {
        return SystemKeyConfig::group($group)->first();
    }

    public function getData(string $group): array
    {
        return $this->getRaw($group)?->data ?? [];
    }

    public function upsert(string $group, array $data, bool $isActive): SystemKeyConfig
    {
        $existing = $this->getRaw($group);
        $merged = array_merge($existing?->data ?? [], $data);

        $config = SystemKeyConfig::updateOrCreate(
            ['group' => $group],
            ['data' => $merged, 'is_active' => $isActive],
        );

        $this->applyRuntimeConfig();

        return $config;
    }

    /**
     * Payload masked para la API: nunca expone secretos completos, solo si
     * están configurados (`hasValue`) y los últimos 4 caracteres, igual
     * criterio que AiConfiguration::toArray().
     */
    public function toApiPayload(string $group): array
    {
        $record = $this->getRaw($group);
        $data = $record?->data ?? [];
        $secretFields = self::SECRET_FIELDS[$group] ?? [];

        $fields = [];
        foreach (self::SCHEMAS[$group] ?? [] as $field) {
            $value = $data[$field] ?? null;
            if (in_array($field, $secretFields, true)) {
                $hasValue = !empty($value);
                $fields[$field] = [
                    'hasValue' => $hasValue,
                    'value' => $hasValue ? '••••' . substr((string) $value, -4) : '',
                ];
            } else {
                $fields[$field] = ['hasValue' => !empty($value), 'value' => $value ?? ''];
            }
        }

        return [
            'group' => $group,
            'isActive' => (bool) ($record?->is_active ?? false),
            'fields' => $fields,
            'updatedAt' => optional($record?->updated_at)->format('Y-m-d H:i'),
        ];
    }

    /**
     * Sobreescribe config('mail.*') y config('broadcasting.connections.pusher.*')
     * con lo guardado en BD, solo para los grupos activos — si un grupo no está
     * activo (o no existe fila), se deja el .env/config file tal cual.
     */
    public function applyRuntimeConfig(): void
    {
        $smtp = $this->getRaw('smtp');
        if ($smtp?->is_active) {
            $data = $smtp->data ?? [];
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $data['host'] ?? config('mail.mailers.smtp.host'));
            Config::set('mail.mailers.smtp.port', $data['port'] ?? config('mail.mailers.smtp.port'));
            Config::set('mail.mailers.smtp.encryption', $data['encryption'] ?? config('mail.mailers.smtp.encryption'));
            Config::set('mail.mailers.smtp.username', $data['username'] ?? config('mail.mailers.smtp.username'));
            Config::set('mail.mailers.smtp.password', $data['password'] ?? config('mail.mailers.smtp.password'));
            Config::set('mail.from.address', $data['from_address'] ?? config('mail.from.address'));
            Config::set('mail.from.name', $data['from_name'] ?? config('mail.from.name'));
        }

        $pusher = $this->getRaw('pusher');
        if ($pusher?->is_active) {
            $data = $pusher->data ?? [];
            Config::set('broadcasting.connections.pusher.app_id', $data['app_id'] ?? config('broadcasting.connections.pusher.app_id'));
            Config::set('broadcasting.connections.pusher.key', $data['key'] ?? config('broadcasting.connections.pusher.key'));
            Config::set('broadcasting.connections.pusher.secret', $data['secret'] ?? config('broadcasting.connections.pusher.secret'));
            Config::set('broadcasting.connections.pusher.options.cluster', $data['cluster'] ?? config('broadcasting.connections.pusher.options.cluster'));
        }
    }

    /** Envía un correo de prueba al email indicado usando la config recién guardada. */
    public function testSmtp(string $toEmail): array
    {
        try {
            $this->applyRuntimeConfig();
            Mail::raw('Este es un correo de prueba de configuración SMTP de IVOO Gestión.', function ($message) use ($toEmail) {
                $message->to($toEmail)->subject('IVOO Gestión — Prueba de configuración SMTP');
            });

            return ['success' => true, 'message' => "Correo de prueba enviado a {$toEmail}."];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo enviar el correo de prueba: ' . $e->getMessage()];
        }
    }

    /** Health check contra la API de Pusher con las credenciales recién guardadas. */
    public function testPusher(): array
    {
        $data = $this->getData('pusher');

        if (empty($data['app_id']) || empty($data['key']) || empty($data['secret']) || empty($data['cluster'])) {
            return ['success' => false, 'message' => 'Faltan campos requeridos (App ID, Key, Secret, Cluster).'];
        }

        try {
            $pusher = new Pusher($data['key'], $data['secret'], $data['app_id'], [
                'cluster' => $data['cluster'],
                'useTLS' => true,
            ]);
            $pusher->get('/channels');

            return ['success' => true, 'message' => 'Conexión con Pusher verificada correctamente.'];
        } catch (PusherException|\Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo conectar con Pusher: ' . $e->getMessage()];
        }
    }
}
