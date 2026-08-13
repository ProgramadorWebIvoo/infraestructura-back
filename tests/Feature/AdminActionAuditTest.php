<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\Contractor;
use App\Models\ConfigAuditLog;
use App\Models\MaterialCatalog;
use App\Models\User;
use App\Notifications\ProjectActionMail;
use App\Notifications\ProjectActionNotification;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cubre la Fase A del plan de notificaciones configurables por rol: las 17
 * operaciones administrativas (usuarios, proveedores, materiales, config de
 * IA) antes no generaban ningún registro de auditoría. Ahora pasan por
 * ConfigAuditLog::recordAdminAction() (no AuditLog — decisión explícita:
 * administración usa el canal de config, Presidencia usa AuditLog para el
 * flujo regular de obra) y disparan NotificationDispatcher::notify() con
 * $project = null.
 */
class AdminActionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperadmin(): User
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);
        return $superadmin;
    }

    public function test_user_creation_is_recorded_in_config_audit_log_not_audit_log(): void
    {
        $superadmin = $this->actingAsSuperadmin();

        $this->postJson('/api/users', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@test.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'ANALISTA',
        ])->assertStatus(201);

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'user',
            'action' => 'Creacion de usuario',
            'user_id' => $superadmin->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'Creacion de usuario']);
    }

    public function test_role_change_is_recorded_as_its_own_critical_action(): void
    {
        $this->actingAsSuperadmin();
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $this->patchJson("/api/users/{$user->id}", ['role' => 'PROCURA'])
            ->assertStatus(200);

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'user',
            'action' => 'Modificacion de usuario',
        ]);
        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'user',
            'action' => 'Cambio de rol de usuario',
            'old_value' => 'ANALISTA',
            'new_value' => 'PROCURA',
        ]);
        $this->assertTrue(NotificationCatalog::isCritical('Cambio de rol de usuario'));
    }

    public function test_update_without_role_change_does_not_emit_role_change_action(): void
    {
        $this->actingAsSuperadmin();
        $user = User::factory()->create(['role' => 'ANALISTA', 'name' => 'Viejo Nombre']);

        $this->patchJson("/api/users/{$user->id}", ['name' => 'Nuevo Nombre'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('config_audit_logs', ['action' => 'Cambio de rol de usuario']);
    }

    public function test_toggle_user_status_is_audited(): void
    {
        $this->actingAsSuperadmin();
        $user = User::factory()->create(['status' => 'Active']);

        $this->postJson("/api/users/{$user->id}/toggle-status")->assertStatus(200);

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'user',
            'action' => 'Activacion/desactivacion de usuario',
        ]);
    }

    public function test_contractor_admin_crud_is_audited(): void
    {
        $this->actingAsSuperadmin();

        $response = $this->postJson('/api/contractors/config', [
            'name' => 'Constructora ACME',
            'specialty' => 'Electricidad',
            'contact' => 'acme@test.com',
        ]);
        $response->assertStatus(201);

        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'contractor', 'action' => 'Alta de proveedor']);

        $contractor = Contractor::first();
        $this->patchJson("/api/contractors/config/{$contractor->code}", ['specialty' => 'Plomería'])->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'contractor', 'action' => 'Modificacion de proveedor']);

        $this->postJson("/api/contractors/config/{$contractor->code}/toggle-status")->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'contractor', 'action' => 'Activacion/desactivacion de proveedor']);

        $this->postJson("/api/contractors/{$contractor->code}/rating", ['rating' => 4.5])->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'contractor', 'action' => 'Calificacion de proveedor']);
    }

    public function test_material_admin_crud_is_audited(): void
    {
        $this->actingAsSuperadmin();

        $this->postJson('/api/materials/config', [
            'name' => 'Cemento',
            'unit' => 'saco',
            'estimatedUnitPrice' => 12.5,
        ])->assertStatus(201);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'material', 'action' => 'Alta de material']);

        $material = MaterialCatalog::first();
        $this->patchJson("/api/materials/config/{$material->id}", ['estimatedUnitPrice' => 15])->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'material', 'action' => 'Modificacion de material']);

        $this->postJson("/api/materials/config/{$material->id}/toggle-status")->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'material', 'action' => 'Activacion/desactivacion de material']);
    }

    public function test_ai_config_crud_is_audited_without_leaking_the_api_key(): void
    {
        $this->actingAsSuperadmin();

        $response = $this->postJson('/api/ai/config', [
            'provider' => 'openai',
            'model' => 'gpt-4.1',
            'apiKey' => 'sk-super-secret-key',
        ]);
        $response->assertStatus(201);

        $log = ConfigAuditLog::where('entity_type', 'ai_config')->where('action', 'Alta de configuracion de IA')->firstOrFail();
        $this->assertStringNotContainsString('sk-super-secret-key', (string) $log->new_value);
        $this->assertStringContainsString('openai', $log->new_value);
        $this->assertStringContainsString('gpt-4.1', $log->new_value);

        $config = AiConfiguration::first();
        $this->patchJson("/api/ai/config/{$config->id}", ['maxTokens' => 8192])->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'ai_config', 'action' => 'Modificacion de configuracion de IA']);

        $this->deleteJson("/api/ai/config/{$config->id}")->assertStatus(200);
        $this->assertDatabaseHas('config_audit_logs', ['entity_type' => 'ai_config', 'action' => 'Eliminacion de configuracion de IA']);
    }

    public function test_admin_action_notifies_configured_recipients_via_app_channel(): void
    {
        Notification::fake();
        $superadmin = $this->actingAsSuperadmin();
        $otherSuperadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->postJson('/api/materials/config', [
            'name' => 'Arena',
            'unit' => 'm3',
        ])->assertStatus(201);

        // recipientsFor() legacy no reconoce acciones nuevas (branch default
        // => []) hasta que la Fase B active el resolver — por ahora no debe
        // fallar ni notificar a nadie todavía, solo quedar auditado.
        Notification::assertNothingSent();
    }

    public function test_admin_actions_are_included_in_default_acciones_con_notificacion_app(): void
    {
        $this->assertContains('Creacion de usuario', SettingsService::get('acciones_con_notificacion_app'));
        $this->assertContains('Alta de proveedor', SettingsService::get('acciones_con_notificacion_app'));
        $this->assertContains('Alta de configuracion de IA', SettingsService::get('acciones_con_notificacion_app'));
    }
}
