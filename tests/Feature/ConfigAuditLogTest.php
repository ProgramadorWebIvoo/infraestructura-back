<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_setting_creates_a_config_audit_log_entry(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '60'])
            ->assertStatus(200);

        $this->assertDatabaseHas('config_audit_logs', [
            'setting_key' => 'anticipo_maximo_porcentaje',
            'old_value' => '100',
            'new_value' => '60',
            'user_id' => $admin->id,
        ]);
    }

    public function test_update_response_carries_the_audit_log_entry_nested_under_data(): void
    {
        // Anidado bajo `data` (no como hermano) porque apiFetch en el
        // frontend desenvuelve automáticamente `json.data` — el frontend usa
        // esto para insertar la entrada en el panel de auditoría sin volver a
        // consultar /config-audit-logs (evita polling).
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $response = $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '60']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.auditLog.settingKey', 'anticipo_maximo_porcentaje');
        $response->assertJsonPath('data.auditLog.oldValue', '100');
        $response->assertJsonPath('data.auditLog.newValue', '60');
        $response->assertJsonPath('data.auditLog.userName', $admin->name);
    }

    public function test_config_audit_logs_endpoint_requires_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)
            ->getJson('/api/config-audit-logs')
            ->assertStatus(403);
    }

    public function test_superadmin_can_list_config_audit_logs(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($superadmin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '50'])
            ->assertStatus(200);

        $response = $this->actingAs($superadmin)->getJson('/api/config-audit-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.settingKey', 'anticipo_maximo_porcentaje');
        $response->assertJsonPath('data.0.newValue', '50');
    }

    public function test_non_superadmin_role_cannot_see_config_audit_logs(): void
    {
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $this->actingAs($presidencia)
            ->getJson('/api/config-audit-logs')
            ->assertStatus(403);
    }
}
