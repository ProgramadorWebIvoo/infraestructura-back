<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectActionMail;
use App\Notifications\ProjectActionNotification;
use App\Models\NotificationRule;
use App\Notifications\UserPasswordReset;
use App\Services\NotificationDispatcher;
use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use App\Support\NotificationType;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

        // "Liberacion total de fondos" ocurre con el proyecto en
        // COMPLETADO_PAGADO (después del pago) — status real donde la
        // matriz sembrada tiene destinatarios de correo (CIERRE_DE_OBRA,
        // INFRAESTRUCTURA, PRESIDENCIA), no LISTO_PAGO_FINAL (antes del pago).
        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'COMPLETADO_PAGADO']);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Liberacion total de fondos', 'Pago final liberado');

        Notification::assertSentTo(
            $cierre,
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
        $this->assertContains('contractor.register', NotificationCatalog::keys());
        $this->assertContains('invitation.view', NotificationCatalog::keys());
        $this->assertContains('proposal.submit', NotificationCatalog::keys());
        $this->assertContains('Solicitud de restablecimiento de contrasena', NotificationCatalog::keys());
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
            'email' => 'contacto@xyz.com',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'contractor.register',
            'role' => 'SISTEMA',
            'project_id' => null,
        ]);
    }

    public function test_rule_matrix_resolves_recipients_by_action_ignoring_project_status(): void
    {
        Notification::fake();

        // La matriz por acción manda, no el status del proyecto — un status
        // que en el mapeo original correspondía a otro rol no cambia esto.
        NotificationRule::where('action', 'Rechazo de cuadro comparativo')->delete();
        NotificationRule::create(['action' => 'Rechazo de cuadro comparativo', 'role' => 'FINANZAS', 'channel' => 'app', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $procura = User::factory()->create(['role' => 'PROCURA']);
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);

        AuditLog::record($project, 'PROCURA', 'Rechazo de cuadro comparativo', 'motivo');

        Notification::assertSentTo($finanzas, ProjectActionNotification::class);
        Notification::assertNotSentTo($procura, ProjectActionNotification::class);
    }

    public function test_project_rejection_notifies_infraestructura_via_app_and_mail(): void
    {
        Notification::fake();

        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Rechazo de petición de obra', 'Descripción insuficiente.');

        Notification::assertSentTo($infra, ProjectActionNotification::class);
        Notification::assertSentTo($infra, ProjectActionMail::class);
        Notification::assertNotSentTo($cierre, ProjectActionNotification::class);
    }

    public function test_project_resubmission_notifies_cierre_de_obra_via_app(): void
    {
        Notification::fake();

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $project = Project::factory()->create(['status' => 'RECHAZADO_CIERRE']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Reenvío de petición corregida', 'Petición corregida y reenviada.');

        Notification::assertSentTo($cierre, ProjectActionNotification::class);
        Notification::assertNotSentTo($infra, ProjectActionNotification::class);
    }

    public function test_admin_action_without_project_notifies_via_rule_matrix(): void
    {
        Notification::fake();

        // "Alta de material" ya viene sembrada con CATALOGOS.
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

    public function test_notify_falls_back_to_informacion_type_for_an_action_not_in_the_catalog(): void
    {
        // NotificationDispatcher::notify() no exige que $action exista en
        // NotificationCatalog — no lanza excepción y cae al tipo INFORMACION
        // por defecto (ver NotificationCatalog::type()). El propio setting
        // `acciones_con_notificacion_app` sembrado es una whitelist explícita
        // de acciones conocidas, así que una acción inventada queda filtrada
        // por esa capa antes de llegar a NotificationRuleResolver — para
        // probar solo el fallback de tipo (sin la capa de whitelist),
        // vaciamos la whitelist (null = "no filtrar nada", ver isAppNotificationAllowed()).
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        AppSetting::where('key', 'acciones_con_notificacion_app')->update(['value' => null]);
        SettingsService::forget();

        NotificationDispatcher::notify(null, 'SISTEMA', 'Accion completamente inventada sin catalogo', 'detalle');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $superadmin->id,
            'action' => 'Accion completamente inventada sin catalogo',
            'type' => NotificationType::INFORMACION,
        ]);
    }

    public function test_app_notification_row_carries_the_type_from_the_catalog(): void
    {
        // "Rechazo de cuadro comparativo" tiene un TYPE_OVERRIDE explícito
        // a accion_requerida en NotificationCatalog — Hallazgo 3 de la
        // auditoría Fase 0-1 (antes app_notifications no tenía columna type).
        // Sin notification_rules sembradas para esta acción en el entorno de
        // test, cae al fallback DEFAULT_APP_ROLES (SUPERADMIN/ADMIN).
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);

        AuditLog::record($project, 'PROCURA', 'Rechazo de cuadro comparativo', 'motivo');

        $this->assertDatabaseHas('app_notifications', [
            'action' => 'Rechazo de cuadro comparativo',
            'type' => NotificationType::ACCION_REQUERIDA,
        ]);
    }

    public function test_app_notification_row_defaults_to_prioritario_for_critical_actions_without_override(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create(['status' => 'COMPLETADO_PAGADO']);

        AuditLog::record($project, 'CIERRE_DE_OBRA', 'Liberacion total de fondos', 'pago final');

        $this->assertDatabaseHas('app_notifications', [
            'action' => 'Liberacion total de fondos',
            'type' => NotificationType::PRIORITARIO,
        ]);
    }

    public function test_app_notification_row_defaults_to_informacion_for_non_critical_actions(): void
    {
        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra');

        $this->assertDatabaseHas('app_notifications', [
            'action' => 'Creacion de peticion de obra',
            'type' => NotificationType::INFORMACION,
        ]);
    }

    public function test_mail_channel_resolved_via_rule_matrix_independent_of_app_channel(): void
    {
        Notification::fake();

        NotificationRule::firstOrCreate(['action' => 'Liberacion de anticipo', 'role' => 'FINANZAS', 'channel' => 'mail'], ['enabled' => true]);
        NotificationRuleResolver::forget();

        AppSetting::where('key', 'acciones_con_correo')->update(['value' => json_encode(['Liberacion de anticipo'])]);
        SettingsService::forget();

        $finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $project = Project::factory()->create(['status' => 'EN_EJECUCION']);

        AuditLog::record($project, 'FINANZAS', 'Liberacion de anticipo', 'anticipo liberado');

        Notification::assertSentTo($finanzas, ProjectActionMail::class);
    }

    public function test_notify_broadcasts_notification_created_per_app_recipient(): void
    {
        Event::fake([NotificationCreated::class]);

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        Event::assertDispatched(
            NotificationCreated::class,
            fn (NotificationCreated $event) => $event->notification->user_id === $cierre->id
                && $event->notification->action === 'Creacion de peticion de obra'
        );
    }

    public function test_notification_created_broadcasts_on_the_recipients_private_channel(): void
    {
        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $notification = AppNotification::create([
            'user_id' => $cierre->id,
            'action' => 'Test',
        ]);

        $event = new NotificationCreated($notification);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-App.Models.User.'.$cierre->id, $channels[0]->name);
        $this->assertSame('notification.created', $event->broadcastAs());
        $this->assertSame($notification->toArray(), $event->broadcastWith());
    }

    public function test_notify_does_not_throw_when_broadcasting_fails(): void
    {
        // Simula Reverb caído: NotificationCreated::broadcastOn() lanza al
        // resolverse. El try/catch en NotificationDispatcher::notify() debe
        // absorberlo — la notificación ya persistida en BD es lo que
        // importa; el push es una mejora, no un requisito del flujo.
        Event::listen(NotificationCreated::class, function () {
            throw new \RuntimeException('Reverb unavailable');
        });

        $cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $project = Project::factory()->create(['status' => 'CREADO']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'detalle');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $cierre->id,
            'action' => 'Creacion de peticion de obra',
        ]);
    }
}
