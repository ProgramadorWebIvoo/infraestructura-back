<?php

namespace Tests\Feature;

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Regresión: reportado por QA como "configuré una acción en CONFIG APP / la
 * migración sembró la regla, pero el destinatario nunca recibió la
 * notificación pese a estar bien configurado en BD". Causa real: las
 * migraciones de seed de notification_rules/app_settings escriben
 * directamente con DB::table(...), sin pasar por
 * NotificationRuleResolver::forget()/SettingsService::forget() — el caché
 * de 5 min (Cache::remember) quedaba con una versión vieja hasta que
 * expiraba solo. AppServiceProvider::invalidateCachesAfterMigrate() cubre
 * esto invalidando ambos cachés al final de cualquier comando `migrate*`.
 *
 * `Artisan::call('migrate', ...)` no sirve para probar esto: ejecuta el
 * comando in-process sin pasar por el ciclo de vida completo de consola de
 * Laravel, así que CommandFinished nunca se dispara ahí (confirmado
 * manualmente: un `php artisan migrate` real desde shell sí invalida el
 * caché correctamente). Se dispara el evento directamente — es la forma
 * correcta de probar un listener sin depender de mecánica interna del
 * test runner que no reproduce el entorno CLI real.
 */
class MigrationCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function fireCommandFinished(string $command = 'migrate'): void
    {
        Event::dispatch(new CommandFinished($command, new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput(), 0));
    }

    public function test_migrate_command_finished_invalidates_stale_notification_rules_cache(): void
    {
        // Simula el caché ya poblado ANTES de que la fila nueva exista en
        // BD — exactamente el estado real cuando una request llega justo
        // antes de que corra la migración de seed.
        NotificationRuleResolver::all();
        $this->assertNotNull(Cache::get('notification_rules.all'));

        // Simula lo que hace una migración de seed: escritura directa,
        // sin pasar por el servicio (por eso no invalida el caché por sí
        // sola). Un rol que la matriz sembrada por defecto NO tiene para
        // esta acción, para no chocar con el unique (action, role, channel).
        DB::table('notification_rules')->insert([
            'action' => 'Rechazo de petición de obra',
            'role' => 'FINANZAS',
            'channel' => 'app',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sin el fix, esta regla nueva sería invisible hasta que el TTL de
        // 5 min expire — recipientsFor() seguiría devolviendo el estado
        // previo a la escritura directa.
        $this->fireCommandFinished();

        $roles = NotificationRuleResolver::rolesFor('Rechazo de petición de obra', 'app');
        $this->assertContains('FINANZAS', $roles);
    }

    public function test_migrate_command_finished_invalidates_stale_app_settings_cache(): void
    {
        SettingsService::all();
        $this->assertNotNull(Cache::get('app_settings.all'));

        DB::table('app_settings')->where('key', 'home_anuncio')->update(['value' => 'Aviso sembrado directo en BD']);

        $this->fireCommandFinished();

        $this->assertSame('Aviso sembrado directo en BD', SettingsService::get('home_anuncio'));
    }

    public function test_command_finished_for_a_non_migrate_command_does_not_invalidate_cache(): void
    {
        Cache::put('notification_rules.all', ['stale' => 'data'], 300);

        $this->fireCommandFinished('cache:clear');

        $this->assertNotNull(Cache::get('notification_rules.all'));
    }

    public function test_migrate_fresh_and_migrate_rollback_also_invalidate_cache(): void
    {
        foreach (['migrate:fresh', 'migrate:rollback'] as $command) {
            Cache::put('notification_rules.all', ['stale' => 'data'], 300);
            $this->fireCommandFinished($command);
            $this->assertNull(Cache::get('notification_rules.all'), "El comando \"{$command}\" debería invalidar el caché.");
        }
    }
}
