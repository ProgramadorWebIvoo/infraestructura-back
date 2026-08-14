<?php

namespace Tests\Feature;

use App\Models\NotificationRule;
use App\Models\User;
use App\Services\NotificationRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationRuleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_notifies_recipients_as_a_single_catalog_action(): void
    {
        Notification::fake();
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        // Regresión del Hallazgo 1 (auditoría Fase 0-1): NotificationRuleController
        // auditaba con una acción por fila ("notification_rules.{accion}", que no
        // existe en el catálogo) sin notificar a nadie. Ahora recordAdminAction()
        // dispara notify() usando el $notifyAction fijo del catálogo real.
        $this->actingAs($superadmin)->putJson('/api/notification-rules', [
            'action' => 'Rechazo de cuadro comparativo',
            'app' => ['PROCURA'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $superadmin->id,
            'action' => 'Modificacion de reglas de notificacion',
        ]);
    }

    public function test_index_requires_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)->getJson('/api/notification-rules')->assertStatus(403);
    }

    public function test_index_returns_catalog_roles_rules_and_unconfigured(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($superadmin)->getJson('/api/notification-rules');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['actions', 'roles', 'rules', 'unconfigured']]);
        $this->assertContains('SUPERADMIN', $response->json('data.roles'));

        $actions = collect($response->json('data.actions'));
        $criticalAction = $actions->firstWhere('value', 'Cambio de rol de usuario');
        $this->assertTrue($criticalAction['critical']);
        $this->assertSame('usuarios', $criticalAction['group']);
    }

    public function test_update_requires_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)
            ->putJson('/api/notification-rules', ['action' => 'Rechazo de cuadro comparativo', 'app' => ['PROCURA']])
            ->assertStatus(403);
    }

    public function test_update_rejects_unknown_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($superadmin)
            ->putJson('/api/notification-rules', ['action' => 'Accion inexistente', 'app' => ['SUPERADMIN']])
            ->assertStatus(404);
    }

    public function test_update_rejects_invalid_role(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($superadmin)
            ->putJson('/api/notification-rules', ['action' => 'Rechazo de cuadro comparativo', 'app' => ['ROL_INVENTADO']])
            ->assertStatus(422);
    }

    public function test_update_replaces_rules_for_the_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        NotificationRule::create(['action' => 'Rechazo de cuadro comparativo', 'role' => 'ANALISTA', 'channel' => 'app', 'enabled' => true]);

        $response = $this->actingAs($superadmin)->putJson('/api/notification-rules', [
            'action' => 'Rechazo de cuadro comparativo',
            'app' => ['PROCURA', 'SUPERADMIN'],
            'mail' => ['SUPERADMIN'],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('notification_rules', ['action' => 'Rechazo de cuadro comparativo', 'role' => 'ANALISTA']);
        $this->assertDatabaseHas('notification_rules', ['action' => 'Rechazo de cuadro comparativo', 'role' => 'PROCURA', 'channel' => 'app']);
        $this->assertDatabaseHas('notification_rules', ['action' => 'Rechazo de cuadro comparativo', 'role' => 'SUPERADMIN', 'channel' => 'mail']);

        $this->assertEqualsCanonicalizing(['PROCURA', 'SUPERADMIN'], NotificationRuleResolver::rolesFor('Rechazo de cuadro comparativo', 'app'));
    }

    public function test_update_rejects_empty_app_roles_for_critical_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($superadmin)->putJson('/api/notification-rules', [
            'action' => 'Cambio de rol de usuario',
            'app' => [],
        ]);

        $response->assertStatus(422);
    }

    public function test_update_allows_empty_app_roles_for_non_critical_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($superadmin)->putJson('/api/notification-rules', [
            'action' => 'Alta de material',
            'app' => [],
        ]);

        $response->assertStatus(200);
    }

    public function test_update_is_recorded_in_config_audit_log(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($superadmin)->putJson('/api/notification-rules', [
            'action' => 'Rechazo de cuadro comparativo',
            'app' => ['PROCURA'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'notification_rule',
            'action' => 'notification_rules.Rechazo de cuadro comparativo',
        ]);
    }
}
