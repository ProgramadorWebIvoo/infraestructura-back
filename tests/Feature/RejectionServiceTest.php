<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectActionNotification;
use App\Services\RejectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * RejectionService es la orquestación genérica detrás de
 * ProjectController::rejectProposals (ver test de integración completo en
 * ProjectLifecycleTest::test_reject_proposals_returns_to_previous_state).
 * Estos tests ejercitan el servicio en aislamiento: validación de estado,
 * atomicidad, y el string de auditoría compuesto.
 */
class RejectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aborts_with_422_when_status_does_not_match(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->create(['status' => 'CREADO']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            RejectionService::reject(
                $project,
                'COMPARATIVA_ENVIADA',
                'CONFIRMADO_PROCURA',
                'PROCURA',
                'Rechazo de cuadro comparativo',
                ['reason' => 'Motivo de prueba'],
                function () {}
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertEquals(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_status_unchanged_when_callback_throws(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        try {
            RejectionService::reject(
                $project,
                'COMPARATIVA_ENVIADA',
                'CONFIRMADO_PROCURA',
                'PROCURA',
                'Rechazo de cuadro comparativo',
                ['reason' => 'Motivo de prueba'],
                function () {
                    throw new \RuntimeException('Fallo simulado en la mutación de dominio');
                }
            );
            $this->fail('Se esperaba que la excepción del callback se propagara.');
        } catch (\RuntimeException $e) {
            // esperado
        }

        $this->assertEquals('COMPARATIVA_ENVIADA', $project->fresh()->status);
    }

    public function test_records_audit_log_with_correct_arguments(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        RejectionService::reject(
            $project,
            'COMPARATIVA_ENVIADA',
            'CONFIRMADO_PROCURA',
            'PROCURA',
            'Rechazo de cuadro comparativo',
            ['reason' => 'Presupuesto excedido'],
            function () {}
        );

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role'       => 'PROCURA',
            'action'     => 'Rechazo de cuadro comparativo',
            'details'    => 'Presupuesto excedido',
        ]);

        $log = AuditLog::where('project_id', $project->id)->first();
        $this->assertEquals($user->id, $log->user_id);
    }

    public function test_build_details_is_plain_reason_when_no_optional_fields(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        RejectionService::reject(
            $project,
            'COMPARATIVA_ENVIADA',
            'CONFIRMADO_PROCURA',
            'PROCURA',
            'Rechazo de cuadro comparativo',
            ['reason' => 'Solo motivo, sin campos opcionales'],
            function () {}
        );

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'details'    => 'Solo motivo, sin campos opcionales',
        ]);
    }

    public function test_build_details_appends_optional_fields_when_present(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        RejectionService::reject(
            $project,
            'COMPARATIVA_ENVIADA',
            'CONFIRMADO_PROCURA',
            'PROCURA',
            'Rechazo de cuadro comparativo',
            [
                'reason'       => 'Motivo principal',
                'observations' => 'Observación adicional',
                'responsible'  => 'Juan Pérez',
            ],
            function () {}
        );

        $log = AuditLog::where('project_id', $project->id)->first();
        $this->assertStringContainsString('Motivo principal', $log->details);
        $this->assertStringNotContainsString('Observations:', $log->details);
        $this->assertStringContainsString('Responsible: Juan Pérez', $log->details);
        $this->assertSame('Observación adicional', $log->observations);
    }

    public function test_transitions_status_and_returns_fresh_project(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        $result = RejectionService::reject(
            $project,
            'COMPARATIVA_ENVIADA',
            'CONFIRMADO_PROCURA',
            'PROCURA',
            'Rechazo de cuadro comparativo',
            ['reason' => 'Motivo de prueba'],
            function () {}
        );

        $this->assertEquals('CONFIRMADO_PROCURA', $result->status);
        $this->assertEquals('CONFIRMADO_PROCURA', $project->fresh()->status);
    }

    /**
     * Fase 4 del plan de refuerzo de auditorías: detección de rachas de
     * rechazos consecutivos sobre el mismo proyecto (RejectionService::
     * alertIfConsecutiveRejections). Cada llamada revierte el estado a
     * COMPARATIVA_ENVIADA entre rechazos para simular que el flujo vuelve a
     * quedar en condición de ser rechazado de nuevo, sin generar ninguna
     * acción de auditoría distinta de por medio.
     */
    public function test_notifies_once_when_consecutive_rejection_threshold_is_reached(): void
    {
        Notification::fake();

        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        for ($i = 0; $i < 3; $i++) {
            RejectionService::reject(
                $project,
                'COMPARATIVA_ENVIADA',
                'CONFIRMADO_PROCURA',
                'PROCURA',
                'Rechazo de cuadro comparativo',
                ['reason' => "Motivo {$i}"],
                function () {}
            );
            $project->status = 'COMPARATIVA_ENVIADA';
            $project->save();
        }

        Notification::assertSentTo($superadmin, ProjectActionNotification::class, function ($notification) {
            return $notification->action === 'Racha de rechazos detectada';
        });

        Notification::assertSentTimes(ProjectActionNotification::class, 4);
    }

    public function test_does_not_notify_before_threshold_is_reached(): void
    {
        Notification::fake();

        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $user = User::factory()->create(['role' => 'PROCURA']);
        $this->actingAs($user);

        $project = Project::factory()->confirmed()->create(['status' => 'COMPARATIVA_ENVIADA']);

        for ($i = 0; $i < 2; $i++) {
            RejectionService::reject(
                $project,
                'COMPARATIVA_ENVIADA',
                'CONFIRMADO_PROCURA',
                'PROCURA',
                'Rechazo de cuadro comparativo',
                ['reason' => "Motivo {$i}"],
                function () {}
            );
            $project->status = 'COMPARATIVA_ENVIADA';
            $project->save();
        }

        Notification::assertNotSentTo($superadmin, ProjectActionNotification::class, function ($notification) {
            return $notification->action === 'Racha de rechazos detectada';
        });
    }
}
