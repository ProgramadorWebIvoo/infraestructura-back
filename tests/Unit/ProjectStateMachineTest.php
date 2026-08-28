<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\ProjectStateMachine;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Extiende Tests\TestCase (no PHPUnit\Framework\TestCase puro) porque
 * assertStatus()/assertStatusIn() usan el helper abort_unless(), que
 * requiere el container de Laravel — pero no toca la base de datos
 * (Project se instancia sin persistir), así que sigue siendo rápido.
 */
class ProjectStateMachineTest extends TestCase
{
    public function test_assert_status_passes_when_status_matches(): void
    {
        $project = new Project(['status' => ProjectStateMachine::STATUSES['CONTRATADO']]);

        ProjectStateMachine::assertStatus($project, ProjectStateMachine::STATUSES['CONTRATADO'], 'no debería fallar');

        $this->assertTrue(true);
    }

    public function test_assert_status_aborts_with_422_when_status_does_not_match(): void
    {
        $project = new Project(['status' => ProjectStateMachine::STATUSES['CREADO']]);

        try {
            ProjectStateMachine::assertStatus($project, ProjectStateMachine::STATUSES['CONTRATADO'], 'mensaje esperado');
            $this->fail('Se esperaba un HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('mensaje esperado', $e->getMessage());
        }
    }

    public function test_assert_status_in_passes_when_status_is_in_list(): void
    {
        $project = new Project(['status' => ProjectStateMachine::STATUSES['RECHAZADO_CIERRE']]);

        ProjectStateMachine::assertStatusIn(
            $project,
            [ProjectStateMachine::STATUSES['CREADO'], ProjectStateMachine::STATUSES['RECHAZADO_CIERRE']],
            'no debería fallar'
        );

        $this->assertTrue(true);
    }

    public function test_assert_status_in_aborts_when_status_not_in_list(): void
    {
        $project = new Project(['status' => ProjectStateMachine::STATUSES['CONTRATADO']]);

        try {
            ProjectStateMachine::assertStatusIn(
                $project,
                [ProjectStateMachine::STATUSES['CREADO'], ProjectStateMachine::STATUSES['RECHAZADO_CIERRE']],
                'mensaje esperado'
            );
            $this->fail('Se esperaba un HttpException 422.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    /**
     * RECHAZADO_CIERRE es el único estado sin lugar en el funnel (es un
     * estado transitorio de vuelta a CREADO, no una etapa del flujo hacia
     * adelante) — todos los demás deben tener una posición en STATUS_ORDER.
     */
    public function test_status_order_covers_every_status_except_rechazado_cierre(): void
    {
        $missing = array_diff(array_keys(ProjectStateMachine::STATUSES), array_keys(ProjectStateMachine::STATUS_ORDER));

        $this->assertSame(['RECHAZADO_CIERRE'], array_values($missing));
    }
}
