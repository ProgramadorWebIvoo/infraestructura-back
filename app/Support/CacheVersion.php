<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Invalidación de caché por versión — funciona con CUALQUIER driver
 * (file, database, redis, etc.), a diferencia de Cache::tags() que solo
 * soporta redis/memcached. Cada "bump" incrementa un contador que forma
 * parte de la cache key; las entradas viejas simplemente quedan huérfanas
 * (expiran solas por TTL) en vez de borrarse activamente.
 */
class CacheVersion
{
    public static function get(string $namespace): int
    {
        return (int) Cache::get("cache_version:{$namespace}", 1);
    }

    public static function bump(string $namespace): void
    {
        Cache::forever("cache_version:{$namespace}", static::get($namespace) + 1);
    }
}
