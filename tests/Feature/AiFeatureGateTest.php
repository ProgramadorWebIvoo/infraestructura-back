<?php

namespace Tests\Feature;

use App\Services\AiFeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_to_enabled_when_no_row_exists(): void
    {
        // Fail-open: Procura/Auditoría ya usan IA hoy y no deben
        // apagarse solas al desplegar este sistema de toggles.
        $this->assertTrue(AiFeatureGate::isEnabled('PROCURA', 'ia.procura.evaluacion_propuestas'));
    }

    public function test_master_switch_off_disables_every_action_in_the_department(): void
    {
        AiFeatureGate::setDepartmentEnabled('PROCURA', false);

        $this->assertFalse(AiFeatureGate::isEnabled('PROCURA', 'ia.procura.evaluacion_propuestas'));
    }

    public function test_specific_action_off_disables_only_that_action(): void
    {
        AiFeatureGate::setActionEnabled('PROCURA', 'ia.procura.evaluacion_propuestas', false);

        $this->assertFalse(AiFeatureGate::isEnabled('PROCURA', 'ia.procura.evaluacion_propuestas'));
        $this->assertTrue(AiFeatureGate::isDepartmentEnabled('PROCURA'));
    }

    public function test_master_switch_on_does_not_override_a_disabled_specific_action(): void
    {
        AiFeatureGate::setActionEnabled('ANALISTA', 'ia.analistas.evaluacion_propuestas', false);
        AiFeatureGate::setDepartmentEnabled('ANALISTA', true);

        $this->assertFalse(AiFeatureGate::isEnabled('ANALISTA', 'ia.analistas.evaluacion_propuestas'));
    }

    public function test_setter_invalidates_cache_immediately(): void
    {
        $this->assertTrue(AiFeatureGate::isEnabled('CATALOGOS', 'ia.proveedores.sugerencia_rating'));

        AiFeatureGate::setDepartmentEnabled('CATALOGOS', false);

        $this->assertFalse(AiFeatureGate::isEnabled('CATALOGOS', 'ia.proveedores.sugerencia_rating'));
    }

    public function test_matrix_includes_every_catalog_action_with_default_true(): void
    {
        $matrix = AiFeatureGate::matrix();

        $this->assertTrue($matrix['PROCURA']['master']);
        $this->assertTrue($matrix['PROCURA']['actions']['ia.procura.evaluacion_propuestas']);
        $this->assertArrayHasKey('AUDITORIA', $matrix);
        $this->assertArrayHasKey('ANALISTA', $matrix);
        $this->assertArrayHasKey('CATALOGOS', $matrix);
    }
}
