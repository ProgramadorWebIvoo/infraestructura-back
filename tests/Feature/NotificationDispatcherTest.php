<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectActionMail;
use App\Notifications\ProjectActionNotification;
use App\Notifications\UserPasswordReset;
use App\Services\NotificationDispatcher;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_record_notifies_recipients_for_current_project_status(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $otroRol = User::factory()->create(['role' => 'FINANZAS']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        // CREADO -> notifica a CIERRE_DE_OBRA (+ SUPERADMIN/ADMIN), no a FINANZAS.
        Notification::assertSentTo($cierre, ProjectActionNotification::class);
        Notification::assertNotSentTo($otroRol, ProjectActionNotification::class);
    }

    public function test_audit_log_record_does_not_notify_when_status_has_no_recipients(): void
    {
        Notification::fake();

        $user = User::factory()->create(['role' => 'ANALISTA']);
        $project = Project::factory()->create(['status' => 'VERIFICANDO_FINALIZACION']);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Verificacion de finalizacion y calidad de obra');

        // VERIFICANDO_FINALIZACION solo tiene SUPERADMIN/ADMIN como destinatarios.
        Notification::assertNotSentTo($user, ProjectActionNotification::class);
    }

    public function test_listo_pago_final_notifies_finanzas(): void
    {
        Notification::fake();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $project = Project::factory()->create(['status' => 'LISTO_PAGO_FINAL']);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Verificacion de finalizacion y calidad de obra');

        Notification::assertSentTo($finanzas, ProjectActionNotification::class);
    }

    public function test_notification_payload_carries_action_as_message(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra');

        Notification::assertSentTo(
            $cierre,
            ProjectActionNotification::class,
            function (ProjectActionNotification $notification) use ($project) {
                return $notification->project->id === $project->id
                    && $notification->action === 'Creacion de peticion de obra'
                    && $notification->status === 'CREADO';
            }
        );
    }

    public function test_record_persists_a_notification_row_per_recipient(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $cierre->id,
            'project_id' => $project->id,
            'action' => 'Creacion de peticion de obra',
            'details' => 'detalle',
            'read_at' => null,
        ]);
    }

    public function test_non_critical_action_does_not_send_mail(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra');

        Notification::assertNotSentTo($cierre, ProjectActionMail::class);
    }

    public function test_critical_action_sends_mail_to_recipients(): void
    {
        Notification::fake();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $project = Project::factory()->create(['status' => 'LISTO_PAGO_FINAL']);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Liberacion total de fondos', 'Pago final liberado');

        Notification::assertSentTo(
            $finanzas,
            ProjectActionMail::class,
            fn (ProjectActionMail $mail) => $mail->project->id === $project->id
                && $mail->action === 'Liberacion total de fondos'
        );
    }

    public function test_mail_actions_list_is_editable_via_config_app_without_deploy(): void
    {
        Notification::fake();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $project = Project::factory()->create(['status' => 'LISTO_PAGO_FINAL']);

        // Quitar "Liberacion total de fondos" de la lista editable — sin
        // tocar código, esa acción deja de enviar correo.
        AppSetting::where('key', 'acciones_con_correo')->update([
            'value' => json_encode(['Rechazo de cuadro comparativo']),
        ]);
        SettingsService::forget();

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Liberacion total de fondos');

        Notification::assertNotSentTo($finanzas, ProjectActionMail::class);
    }

    public function test_action_excluded_from_notification_list_does_not_notify_nor_create_row(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AppSetting::where('key', 'acciones_con_notificacion_app')->update([
            'value' => json_encode(['Otra accion cualquiera']),
        ]);
        SettingsService::forget();

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        Notification::assertNotSentTo($cierre, ProjectActionNotification::class);
        $this->assertDatabaseMissing('app_notifications', [
            'user_id' => $cierre->id,
            'action' => 'Creacion de peticion de obra',
        ]);
    }

    public function test_action_still_in_notification_list_notifies_normally(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AppSetting::where('key', 'acciones_con_notificacion_app')->update([
            'value' => json_encode(['Creacion de peticion de obra']),
        ]);
        SettingsService::forget();

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        Notification::assertSentTo($cierre, ProjectActionNotification::class);
    }

    public function test_mark_read_endpoint_updates_read_at(): void
    {
        $user = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $notification = AppNotification::create([
            'user_id' => $user->id,
            'project_id' => null,
            'action' => 'Test',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(200);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_unread_count_endpoint_only_counts_own_unread(): void
    {
        $user = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $other = User::factory()->create(['role' => 'PROCURA']);

        AppNotification::create(['user_id' => $user->id, 'action' => 'A']);
        AppNotification::create(['user_id' => $user->id, 'action' => 'B', 'read_at' => now()]);
        AppNotification::create(['user_id' => $other->id, 'action' => 'C']);

        $this->actingAs($user)
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200)
            ->assertJson(['count' => 1]);
    }

    public function test_audit_log_record_accepts_null_project_and_does_not_notify_recipients(): void
    {
        Notification::fake();

        $someUser = User::factory()->create(['role' => 'SUPERADMIN']);

        $log = AuditLog::record(null, 'SISTEMA', 'contractor.register', 'detalle');

        $this->assertNull($log->project_id);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'project_id' => null, 'action' => 'contractor.register']);
        Notification::assertNotSentTo($someUser, ProjectActionNotification::class);
    }

    public function test_is_mail_action_allowed_reflects_acciones_con_correo_setting(): void
    {
        AppSetting::where('key', 'acciones_con_correo')->update([
            'value' => json_encode(['Solicitud de restablecimiento de contrasena']),
        ]);
        SettingsService::forget();

        $this->assertTrue(NotificationDispatcher::isMailActionAllowed('Solicitud de restablecimiento de contrasena'));
        $this->assertFalse(NotificationDispatcher::isMailActionAllowed('Otra accion cualquiera'));
    }

    public function test_password_reset_is_audited_and_sends_mail_when_action_allowed(): void
    {
        Notification::fake();

        AppSetting::where('key', 'acciones_con_correo')->update([
            'value' => json_encode(['Solicitud de restablecimiento de contrasena']),
        ]);
        SettingsService::forget();

        $user = User::factory()->create();

        $user->sendPasswordResetNotification('token-123');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Solicitud de restablecimiento de contrasena',
            'project_id' => null,
        ]);
        Notification::assertSentTo($user, UserPasswordReset::class);
    }

    public function test_password_reset_is_audited_but_mail_is_suppressed_when_action_not_allowed(): void
    {
        Notification::fake();

        AppSetting::where('key', 'acciones_con_correo')->update([
            'value' => json_encode(['Otra accion cualquiera']),
        ]);
        SettingsService::forget();

        $user = User::factory()->create();

        $user->sendPasswordResetNotification('token-123');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Solicitud de restablecimiento de contrasena',
        ]);
        Notification::assertNotSentTo($user, UserPasswordReset::class);
    }

    public function test_contractor_register_action_is_now_auditable_and_included_in_catalog(): void
    {
        $this->assertContains('contractor.register', NotificationDispatcher::AUDITABLE_ACTIONS);
        $this->assertContains('invitation.view', NotificationDispatcher::AUDITABLE_ACTIONS);
        $this->assertContains('proposal.submit', NotificationDispatcher::AUDITABLE_ACTIONS);
        $this->assertContains('Solicitud de restablecimiento de contrasena', NotificationDispatcher::AUDITABLE_ACTIONS);
    }

    public function test_public_contractor_registration_is_now_audited(): void
    {
        // Antes de esta integración, LogsPublicAccess::logPublicAccess() solo
        // llamaba a AuditLog::record() cuando había un $project asociado —
        // el registro público de proveedor (sin proyecto) nunca quedaba
        // auditado. Con AuditLog::record() aceptando ?Project, ahora sí.
        $response = $this->postJson('/api/contractors', [
            'name' => 'Constructora XYZ',
            'specialty' => 'Electricidad',
            'contact' => 'contacto@xyz.com',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'contractor.register',
            'role' => 'SISTEMA',
            'project_id' => null,
        ]);
    }
}
