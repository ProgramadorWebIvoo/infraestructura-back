<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ConfigAuditLog;
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
        $response->assertJsonPath('data.items.0.settingKey', 'anticipo_maximo_porcentaje');
        $response->assertJsonPath('data.items.0.newValue', '50');
        $response->assertJsonPath('data.currentPage', 1);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.perPage', 20);
    }

    public function test_config_audit_logs_endpoint_paginates(): void
    {
        // Anidado bajo `data` (no como hermanos de `data`) por el mismo
        // motivo que el auditLog del update: apiFetch desenvuelve
        // automáticamente `json.data`, así que la metadata de paginación
        // debe vivir dentro de `data` junto a `items`.
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        for ($i = 0; $i < 25; $i++) {
            $this->actingAs($superadmin)
                ->patchJson("/api/settings/{$setting->id}", ['value' => (string) (10 + $i)])
                ->assertStatus(200);
        }

        $firstPage = $this->actingAs($superadmin)->getJson('/api/config-audit-logs?per_page=20');
        $firstPage->assertStatus(200);
        $firstPage->assertJsonPath('data.total', 25);
        $firstPage->assertJsonPath('data.currentPage', 1);
        $firstPage->assertJsonPath('data.lastPage', 2);
        $this->assertCount(20, $firstPage->json('data.items'));

        $secondPage = $this->actingAs($superadmin)->getJson('/api/config-audit-logs?per_page=20&page=2');
        $secondPage->assertStatus(200);
        $secondPage->assertJsonPath('data.currentPage', 2);
        $this->assertCount(5, $secondPage->json('data.items'));
    }

    public function test_non_superadmin_role_cannot_see_config_audit_logs(): void
    {
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $this->actingAs($presidencia)
            ->getJson('/api/config-audit-logs')
            ->assertStatus(403);
    }

    public function test_record_setting_change_persists_entity_type_setting(): void
    {
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $log = ConfigAuditLog::recordSettingChange($setting, '100', '80');

        $this->assertSame('setting', $log->entity_type);
        $this->assertSame('anticipo_maximo_porcentaje', $log->action);
        $this->assertDatabaseHas('config_audit_logs', [
            'id' => $log->id,
            'entity_type' => 'setting',
            'action' => 'anticipo_maximo_porcentaje',
            'setting_key' => 'anticipo_maximo_porcentaje',
        ]);
    }

    public function test_record_admin_action_persists_non_setting_entity(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);

        $log = ConfigAuditLog::recordAdminAction('user', 'Creacion de usuario', null, null, 'Usuario: jdoe@test.com');

        $this->assertSame('user', $log->entity_type);
        $this->assertSame('Creacion de usuario', $log->action);
        $this->assertNull($log->setting_id);
        $this->assertNull($log->setting_key);
        $this->assertSame('Usuario: jdoe@test.com', $log->new_value);
        $this->assertSame($superadmin->id, $log->user_id);
    }

    public function test_config_audit_logs_endpoint_exposes_entity_type_and_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('contractor', 'Alta de proveedor', null, null, 'Proveedor: ACME');

        $response = $this->getJson('/api/config-audit-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('data.items.0.entityType', 'contractor');
        $response->assertJsonPath('data.items.0.action', 'Alta de proveedor');
    }

    public function test_filters_by_entity_type(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('contractor', 'Alta de proveedor', null, null, 'Proveedor: ACME');
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento');

        $response = $this->getJson('/api/config-audit-logs?entity_type=contractor');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.items.0.entityType', 'contractor');
    }

    public function test_filters_by_exact_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('contractor', 'Alta de proveedor', null, null, 'Proveedor: ACME');
        ConfigAuditLog::recordAdminAction('contractor', 'Modificacion de proveedor', null, null, 'Proveedor: ACME');

        $response = $this->getJson('/api/config-audit-logs?action='.urlencode('Alta de proveedor'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.items.0.action', 'Alta de proveedor');
    }

    public function test_filters_by_user_name_partial_match(): void
    {
        $alice = User::factory()->create(['role' => 'SUPERADMIN', 'name' => 'Alice Wonder']);
        $bob = User::factory()->create(['role' => 'SUPERADMIN', 'name' => 'Bob Builder']);

        $this->actingAs($alice);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: A');
        $this->actingAs($bob);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: B');

        $response = $this->getJson('/api/config-audit-logs?user=Alice');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.items.0.userName', 'Alice Wonder');
    }

    public function test_filters_by_free_text_query_across_values(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('contractor', 'Alta de proveedor', null, null, 'Proveedor: Constructora Andes');
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento Portland');

        $response = $this->getJson('/api/config-audit-logs?q=Andes');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.items.0.entityType', 'contractor');
    }

    public function test_filters_by_date_range(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);

        $old = ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Viejo');
        $old->forceFill(['changed_at' => now()->subDays(10)])->save();

        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Nuevo');

        $response = $this->getJson('/api/config-audit-logs?date_from='.now()->subDay()->toDateString());

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.items.0.newValue', 'Material: Nuevo');
    }

    public function test_combines_multiple_filters_with_and(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('contractor', 'Alta de proveedor', null, null, 'Proveedor: ACME');
        ConfigAuditLog::recordAdminAction('contractor', 'Modificacion de proveedor', null, null, 'Proveedor: ACME');
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento');

        $response = $this->getJson('/api/config-audit-logs?entity_type=contractor&action='.urlencode('Alta de proveedor'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
    }

    public function test_exposes_immutable_user_id_and_current_email(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN', 'name' => 'Alejandro González', 'email' => 'alejandro@ivoo.local']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento');

        $response = $this->getJson('/api/config-audit-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('data.items.0.userId', $superadmin->id);
        $response->assertJsonPath('data.items.0.userName', 'Alejandro González');
        $response->assertJsonPath('data.items.0.userEmail', 'alejandro@ivoo.local');
    }

    public function test_user_id_and_name_snapshot_survive_a_later_rename(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN', 'name' => 'Nombre Viejo', 'email' => 'user@ivoo.local']);
        $this->actingAs($superadmin);
        $log = ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento');

        $superadmin->update(['name' => 'Nombre Nuevo']);

        $response = $this->getJson('/api/config-audit-logs');

        $response->assertStatus(200);
        // El snapshot conserva el nombre de ese momento; el ID y el email
        // (si no cambió) siguen resolviendo al usuario actual.
        $response->assertJsonPath('data.items.0.userId', $log->user_id);
        $response->assertJsonPath('data.items.0.userName', 'Nombre Viejo');
        $response->assertJsonPath('data.items.0.userEmail', 'user@ivoo.local');
    }

    public function test_user_email_is_null_when_the_user_was_deleted(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento');
        $superadmin->delete();

        // El propio actor fue borrado — se consulta con otro SUPERADMIN.
        $otherSuperadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $response = $this->actingAs($otherSuperadmin)->getJson('/api/config-audit-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('data.items.0.userEmail', null);
    }

    public function test_filters_by_user_email_partial_match(): void
    {
        $alice = User::factory()->create(['role' => 'SUPERADMIN', 'name' => 'Alice', 'email' => 'alice.wonder@ivoo.local']);
        $bob = User::factory()->create(['role' => 'SUPERADMIN', 'name' => 'Bob', 'email' => 'bob.builder@ivoo.local']);

        $this->actingAs($alice);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: A');
        $this->actingAs($bob);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: B');

        $response = $this->getJson('/api/config-audit-logs?user=alice.wonder');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
        $response->assertJsonPath('data.items.0.userEmail', 'alice.wonder@ivoo.local');
    }

    public function test_free_text_query_also_matches_current_email(): void
    {
        $alice = User::factory()->create(['role' => 'SUPERADMIN', 'email' => 'unique.email.for.test@ivoo.local']);
        $this->actingAs($alice);
        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material: Cemento');

        $response = $this->getJson('/api/config-audit-logs?q=unique.email.for.test');

        $response->assertStatus(200);
        $response->assertJsonPath('data.total', 1);
    }
}
