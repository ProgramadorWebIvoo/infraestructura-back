<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\NotificationDispatcher;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_settings_grouped_by_group(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['presupuesto', 'notificaciones', 'fiscal']]);
    }

    public function test_any_authenticated_role_can_read_settings(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);

        $this->actingAs($user)->getJson('/api/settings')->assertStatus(200);
    }

    public function test_non_admin_cannot_update_a_setting(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($user)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '50'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_a_string_setting(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $setting = AppSetting::where('key', 'razon_social')->firstOrFail();

        $response = $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => 'IVOO Construcciones C.A.']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'razon_social',
            'value' => 'IVOO Construcciones C.A.',
        ]);
    }

    public function test_update_rejects_invalid_integer_value(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => 'no-es-un-numero'])
            ->assertStatus(422);
    }

    public function test_update_rejects_invalid_boolean_value(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'cambios_bloqueados')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => 'tal-vez'])
            ->assertStatus(422);
    }

    public function test_update_rejects_invalid_json_value(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'acciones_con_correo')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '{invalido'])
            ->assertStatus(422);
    }

    public function test_update_rejects_percentage_value_above_max(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '150'])
            ->assertStatus(422);
    }

    public function test_update_rejects_percentage_value_below_min(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '-5'])
            ->assertStatus(422);
    }

    public function test_update_invalidates_the_settings_cache(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->assertSame(100, SettingsService::get('anticipo_maximo_porcentaje'));

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '60'])
            ->assertStatus(200);

        $this->assertSame(60, SettingsService::get('anticipo_maximo_porcentaje'));
    }

    public function test_notification_actions_endpoint_returns_the_real_auditable_actions_catalog(): void
    {
        // Misma fuente que usa NotificationDispatcher al filtrar — el
        // selector de tags en CONFIG APP nunca debe mostrar una acción que
        // la app no dispare realmente.
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings/notification-actions');

        $response->assertStatus(200);
        $response->assertJson(['data' => NotificationDispatcher::AUDITABLE_ACTIONS]);
    }
}
