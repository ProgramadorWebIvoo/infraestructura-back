<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\NotificationRule;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectActionMail;
use App\Notifications\ProjectActionNotification;
use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cubre el comportamiento de NotificationDispatcher::notify() detrás del
 * flag `usar_matriz_notificaciones` — con el flag en `false` (default), debe
 * reproducir exactamente el comportamiento legacy; con el flag en `true`,
 * debe resolver por la matriz configurable.
 */
class NotificationDispatcherMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function setFlag(bool $enabled): void
    {
        AppSetting::where('key', 'usar_matriz_notificaciones')->update(['value' => $enabled ? 'true' : 'false']);
        SettingsService::forget();
    }

    public function test_flag_off_uses_legacy_status_based_matrix(): void
    {
        Notification::fake();
        $this->setFlag(false);

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        Notification::assertSentTo($cierre, ProjectActionNotification::class);
    }

    public function test_flag_on_uses_rule_matrix_and_ignores_project_status(): void
    {
        Notification::fake();
        $this->setFlag(true);

        // Reemplaza lo sembrado por la migración D.1 para este escenario —
        // sin esto, "Rechazo de cuadro comparativo" ya trae PROCURA del
        // sembrado y la aserción assertNotSentTo(PROCURA) sería incorrecta.
        NotificationRule::where('action', 'Rechazo de cuadro comparativo')->delete();
        NotificationRule::create(['action' => 'Rechazo de cuadro comparativo', 'role' => 'FINANZAS', 'channel' => 'app', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $procura = User::factory()->create(['role' => 'PROCURA']);
        // Status que en el legacy notificaría a PROCURA, no a FINANZAS —
        // con el flag activo, la matriz por acción manda, no el status.
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);

        AuditLog::record($project, 'PROCURA', 'Rechazo de cuadro comparativo', 'motivo');

        Notification::assertSentTo($finanzas, ProjectActionNotification::class);
        Notification::assertNotSentTo($procura, ProjectActionNotification::class);
    }

    public function test_flag_on_notifies_admin_actions_without_project(): void
    {
        Notification::fake();
        $this->setFlag(true);

        // "Alta de material" ya viene sembrada con CATALOGOS por la
        // migración D.1 — no hace falta crearla, solo un usuario con ese rol.
        $catalogos = User::factory()->create(['role' => 'CATALOGOS']);
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);

        $this->postJson('/api/materials/config', ['name' => 'Cemento', 'unit' => 'saco'])->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $catalogos->id,
            'project_id' => null,
            'action' => 'Alta de material',
        ]);
    }

    public function test_flag_off_admin_actions_without_project_do_not_notify(): void
    {
        Notification::fake();
        $this->setFlag(false);

        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($superadmin);

        $this->postJson('/api/materials/config', ['name' => 'Cemento', 'unit' => 'saco'])->assertStatus(201);

        $this->assertDatabaseMissing('app_notifications', ['action' => 'Alta de material']);
    }

    public function test_flag_on_respects_mail_channel_via_rule_matrix(): void
    {
        Notification::fake();
        $this->setFlag(true);

        // "Liberacion de anticipo" ya viene sembrada en canal app (D.1); acá
        // solo se agrega el canal mail para este escenario.
        NotificationRule::firstOrCreate(['action' => 'Liberacion de anticipo', 'role' => 'FINANZAS', 'channel' => 'mail'], ['enabled' => true]);
        NotificationRuleResolver::forget();

        AppSetting::where('key', 'acciones_con_correo')->update(['value' => json_encode(['Liberacion de anticipo'])]);
        SettingsService::forget();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $project = Project::factory()->create(['status' => 'EN_EJECUCION']);

        AuditLog::record($project, 'FINANZAS', 'Liberacion de anticipo', 'anticipo liberado');

        Notification::assertSentTo($finanzas, ProjectActionMail::class);
    }
}
