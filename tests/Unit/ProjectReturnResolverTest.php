<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectClosureReport;
use App\Services\ProjectReturnResolver;
use Tests\TestCase;

class ProjectReturnResolverTest extends TestCase
{
    private function project(string $status, ?AuditLog $log, ?ProjectClosureReport $report = null): Project
    {
        $project = new Project(['status' => $status]);
        $project->status = $status;
        $project->setRelation('latestReturnLog', $log);
        $project->setRelation('closureReport', $report);

        return $project;
    }

    private function log(string $action, string $role, string $details, ?string $observations = null): AuditLog
    {
        $log = new AuditLog();
        $log->role = $role;
        $log->action = $action;
        $log->details = $details;
        $log->observations = $observations;
        $log->logged_at = now();

        return $log;
    }

    public function test_rechazo_vigente_indica_rol_y_motivo(): void
    {
        $log = $this->log('Rechazo de adjudicacion por Presidencia', 'PRESIDENCIA', "Monto excesivo\nResponsible: Procura");

        $info = ProjectReturnResolver::resolve($this->project('COMPARATIVA_ENVIADA', $log));

        $this->assertSame('PRESIDENCIA', $info['byRole']);
        $this->assertSame('Monto excesivo', $info['reason']);
    }

    public function test_deja_de_estar_devuelta_cuando_la_obra_avanza_de_estado(): void
    {
        $log = $this->log('Rechazo de cuadro comparativo', 'PROCURA', 'Faltan ofertas');

        $this->assertNull(ProjectReturnResolver::resolve($this->project('COMPARATIVA_ENVIADA', $log)));
    }

    public function test_devolucion_de_finiquito_toma_el_motivo_de_observaciones(): void
    {
        $log = $this->log('Devolucion de finiquito a Auditoria', 'PROCURA', 'Procura devolvió la verificación a Auditoría.', 'Monto no cuadra');

        $info = ProjectReturnResolver::resolve($this->project('VERIFICANDO_FINALIZACION', $log));

        $this->assertSame('PROCURA', $info['byRole']);
        $this->assertSame('Monto no cuadra', $info['reason']);
    }

    public function test_rechazo_del_informe_de_cierre_se_lee_del_informe(): void
    {
        $report = new ProjectClosureReport([
            'rejection_reason' => 'Fotos borrosas',
            'rejected_by_role' => 'AUDITORIA',
            'rejection_target' => 'CONTRATISTA',
        ]);

        $info = ProjectReturnResolver::resolve($this->project('INFORME_ENVIADO', null, $report));

        $this->assertSame('AUDITORIA', $info['byRole']);
        $this->assertSame('CONTRATISTA', $info['target']);
        $this->assertSame('Fotos borrosas', $info['reason']);
    }

    public function test_sin_devolucion_retorna_null(): void
    {
        $this->assertNull(ProjectReturnResolver::resolve($this->project('CREADO', null)));
    }
}
