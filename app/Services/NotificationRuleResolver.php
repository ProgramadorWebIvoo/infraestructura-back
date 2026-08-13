<?php

namespace App\Services;

use App\Models\NotificationRule;
use App\Models\User;
use App\Support\NotificationCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve destinatarios de una acción por rol×canal desde `notification_rules`
 * — reemplaza a NotificationDispatcher::recipientsFor(), que indexaba por
 * estado del proyecto en vez de por acción. Mismo patrón de caché que
 * SettingsService: una sola clave con toda la tabla, invalidada
 * explícitamente en cada escritura (no por TTL).
 */
class NotificationRuleResolver
{
    private const CACHE_KEY = 'notification_rules.all';
    private const CACHE_TTL_SECONDS = 300;

    /** Fallback cuando una acción no tiene ninguna fila configurada — nunca
     *  silencioso (log + expuesto en unconfiguredActions()), nunca spam a
     *  todos los roles. El correo jamás es automático por defecto. */
    private const DEFAULT_APP_ROLES = ['SUPERADMIN', 'ADMIN'];

    /** @return array<string, array{app: string[], mail: string[]}> */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            $map = [];
            foreach (NotificationRule::where('enabled', true)->get() as $rule) {
                $map[$rule->action][$rule->channel][] = $rule->role;
            }
            return $map;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return string[] */
    public static function rolesFor(string $action, string $channel): array
    {
        $rules = self::all();

        if (!isset($rules[$action])) {
            if ($channel === 'app') {
                Log::warning('notification_rules: acción sin configurar, usando fallback administrativo', ['action' => $action]);
                return self::DEFAULT_APP_ROLES;
            }
            return [];
        }

        return $rules[$action][$channel] ?? [];
    }

    public static function recipientsFor(string $action, string $channel): Collection
    {
        $roles = self::rolesFor($action, $channel);

        if (empty($roles)) {
            return collect();
        }

        return User::whereIn('role', $roles)->where('status', 'Active')->get();
    }

    /** Acciones del catálogo sin ninguna fila configurada — para el banner de la UI. */
    public static function unconfiguredActions(): array
    {
        $rules = self::all();

        return array_values(array_filter(
            NotificationCatalog::keys(),
            fn (string $action) => !isset($rules[$action]),
        ));
    }

    /**
     * Matriz completa para la API de configuración: acción => {app: [...], mail: [...]}
     * incluye acciones sin filas configuradas con arrays vacíos (no aplica
     * el fallback acá — el fallback es solo para runtime de notify(), la UI
     * debe mostrar "sin configurar" explícitamente, no roles inventados).
     */
    public static function matrix(): array
    {
        $rules = self::all();
        $matrix = [];

        foreach (NotificationCatalog::keys() as $action) {
            $matrix[$action] = [
                'app' => $rules[$action]['app'] ?? [],
                'mail' => $rules[$action]['mail'] ?? [],
            ];
        }

        return $matrix;
    }
}
