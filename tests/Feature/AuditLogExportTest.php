<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ConfigAuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 4 del plan de refuerzo de auditorías: exportación CSV con los mismos
 * filtros que los endpoints de listado.
 */
class AuditLogExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logs_export_streams_csv_with_filtered_rows(): void
    {
        $user = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($user);
        $project = Project::factory()->create(['title' => 'Proyecto Exportable']);

        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra');
        AuditLog::record($project, 'PROCURA', 'Otra accion');

        $response = $this->get('/api/audit-logs/export?role=INFRAESTRUCTURA');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Proyecto Exportable', $content);
        $this->assertStringContainsString('Creacion de peticion de obra', $content);
        $this->assertStringNotContainsString('Otra accion', $content);
    }

    public function test_config_audit_logs_export_requires_superadmin(): void
    {
        $user = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->actingAs($user);

        $this->get('/api/config-audit-logs/export')->assertStatus(403);
    }

    public function test_config_audit_logs_export_streams_csv(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($admin);

        ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Material exportable');

        $response = $this->get('/api/config-audit-logs/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('Material exportable', $response->streamedContent());
    }
}
