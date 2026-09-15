<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ConfigAuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 1 del plan de refuerzo de auditorías: los registros de AuditLog y
 * ConfigAuditLog deben ser inmutables una vez creados (ni update ni delete),
 * para que sirvan como evidencia confiable. Bloqueado en Model::booted().
 */
class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_cannot_be_updated(): void
    {
        $project = Project::factory()->create();
        $log = AuditLog::record($project, 'INFRAESTRUCTURA', 'Accion de prueba', 'Detalle original');

        $this->expectException(\RuntimeException::class);

        $log->update(['details' => 'Detalle alterado']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $project = Project::factory()->create();
        $log = AuditLog::record($project, 'INFRAESTRUCTURA', 'Accion de prueba', 'Detalle original');

        $this->expectException(\RuntimeException::class);

        $log->delete();
    }

    public function test_config_audit_log_cannot_be_updated(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($admin);

        $log = ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Detalle original');

        $this->expectException(\RuntimeException::class);

        $log->update(['new_value' => 'Detalle alterado']);
    }

    public function test_config_audit_log_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($admin);

        $log = ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Detalle original');

        $this->expectException(\RuntimeException::class);

        $log->delete();
    }
}
