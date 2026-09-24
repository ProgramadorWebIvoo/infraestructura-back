<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 3 del plan de refuerzo de auditorías: filtros server-side en
 * /audit-logs, paridad con /config-audit-logs (ver ConfigAuditLogController::index).
 * Shape anidado bajo data.items (no data plano) desde la unificación que
 * habilitó useAuditLogs en el frontend — ver docblock de
 * AuditLogController::index.
 */
class AuditLogFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_by_role(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        AuditLog::record($projectA, 'INFRAESTRUCTURA', 'Creacion de peticion de obra');
        AuditLog::record($projectB, 'PROCURA', 'Confirmacion de presupuesto');

        $response = $this->getJson('/api/audit-logs?role=PROCURA');

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('PROCURA', $items[0]['role']);
    }

    public function test_filters_by_project_id(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        AuditLog::record($projectA, 'INFRAESTRUCTURA', 'Accion A');
        AuditLog::record($projectB, 'INFRAESTRUCTURA', 'Accion B');

        $response = $this->getJson("/api/audit-logs?project_id={$projectA->id}");

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame($projectA->id, $items[0]['projectId']);
    }

    public function test_filters_by_free_text_query(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $project = Project::factory()->create();

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'Detalle relevante xyz');
        AuditLog::record($project, 'PROCURA', 'Otra accion cualquiera');

        $response = $this->getJson('/api/audit-logs?q=xyz');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_filters_by_date_range(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $project = Project::factory()->create();

        $old = AuditLog::record($project, 'INFRAESTRUCTURA', 'Accion vieja');
        AuditLog::withoutEvents(fn () => $old->forceFill(['logged_at' => now()->subDays(10)])->save());

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Accion reciente');

        $response = $this->getJson('/api/audit-logs?date_from=' . now()->subDay()->toDateString());

        $response->assertStatus(200);
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Accion reciente', $items[0]['action']);
    }

    public function test_per_page_default_covers_more_than_fifty_entries(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $project = Project::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            AuditLog::record($project, 'INFRAESTRUCTURA', "Accion {$i}");
        }

        $response = $this->getJson('/api/audit-logs');

        $response->assertStatus(200);
        $this->assertCount(60, $response->json('data.items'));
        $this->assertSame(60, $response->json('data.total'));
    }

    public function test_paginates_with_page_and_per_page_params(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $project = Project::factory()->create();

        for ($i = 0; $i < 15; $i++) {
            AuditLog::record($project, 'INFRAESTRUCTURA', "Accion {$i}");
        }

        $response = $this->getJson('/api/audit-logs?per_page=10&page=2');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.currentPage'));
        $this->assertSame(2, $response->json('data.lastPage'));
        $this->assertSame(15, $response->json('data.total'));
    }
}
