<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Punto único de lectura de CONFIG APP (app_settings). Cachea todos los
 * settings por 5 minutos — se invalida explícitamente al escribir vía
 * AppSettingController::update(), así que un cambio desde el panel de
 * administración se refleja de inmediato, no hay que esperar el TTL.
 */
class SettingsService
{
    private const CACHE_KEY = 'app_settings.all';
    private const CACHE_TTL_SECONDS = 300;

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = static::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** @return array<string, mixed> key => valor ya casteado por tipo */
    public static function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return AppSetting::all()
                ->mapWithKeys(fn (AppSetting $setting) => [$setting->key => $setting->cast_value])
                ->toArray();
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
