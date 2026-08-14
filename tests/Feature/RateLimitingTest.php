<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión de la corrección de 429 en cascada: el frontend disparaba
 * decenas de requests al navegar entre vistas de configuración (CONFIG APP,
 * config de IA), agotando el bucket `api` compartido de 60/min y devolviendo
 * 429 hasta en endpoints no relacionados (ej. /notifications). Ver
 * RouteServiceProvider::configureRateLimiting() y routes/api.php.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_api_limit_is_at_least_180_per_minute(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        // 61 requests habría bastado para tumbar el límite viejo (60/min).
        // Con el nuevo límite (180/min) todas deben pasar sin 429.
        for ($i = 0; $i < 61; $i++) {
            $this->actingAs($admin)->getJson('/api/settings')->assertStatus(200);
        }
    }

    public function test_read_heavy_config_endpoints_use_the_catalog_bucket_not_the_general_bucket(): void
    {
        // /settings, /currencies, /notification-rules, /config-audit-logs,
        // /ai/config* (GET) están en el bucket `catalog` (200/min) — deben
        // poder recibir muchas más requests que el bucket general (180/min)
        // sin devolver 429, ya que ambos límites son independientes.
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        for ($i = 0; $i < 190; $i++) {
            $this->actingAs($admin)->getJson('/api/settings')->assertStatus(200);
        }
    }

    public function test_preexisting_catalog_routes_are_not_double_throttled_by_the_general_bucket(): void
    {
        // Bug real descubierto al escribir este test: `withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class])`
        // NO removía el `throttle:api` aplicado dentro del grupo de
        // middleware `api` (Kernel.php) — Laravel compara por el string
        // alias exacto ("throttle:api"), no por la clase resuelta sin
        // parámetros. El resultado: /contractors, /materials, /modules,
        // /audit-logs y /projects/{id}/documents nunca lograron desactivar
        // el throttle general pese a documentar "throttle:catalog (200/min)"
        // — en la práctica siempre estuvieron limitados por el bucket
        // general (180/min, antes 60/min). Corregido pasando el string
        // 'throttle:api' a withoutMiddleware() en vez de la clase.
        $user = User::factory()->create();

        for ($i = 0; $i < 190; $i++) {
            $this->actingAs($user)->getJson('/api/contractors')->assertStatus(200);
        }
    }
}
