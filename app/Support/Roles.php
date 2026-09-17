<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\Cache;

/**
 * Catálogo único de roles válidos — antes era un `const VALID` hardcodeado
 * (agregar un rol requería editar este archivo + redeploy). Ahora lee de la
 * tabla `roles` con cache (mismo patrón que SettingsService), administrable
 * desde ConfigAppPanel sin deploy. `users.role`, `notification_rules.role` y
 * `role_view_access.role` siguen siendo strings libres sin FK — se validan
 * en capa de aplicación contra Roles::valid(), igual criterio que antes.
 */
class Roles
{
    private const CACHE_KEY = 'roles.valid';
    private const CACHE_TTL_SECONDS = 300;

    /** @return string[] keys de los roles activos, en orden de sort_order */
    public static function valid(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return Role::where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('key')
                ->all();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
