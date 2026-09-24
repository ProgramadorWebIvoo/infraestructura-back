<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Contractor;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\ProjectRateFreeze;
use App\Models\User;
use App\Services\RateFreezeService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateFreezeTest extends TestCase
{
    use RefreshDatabase;

    private User $procura;
    private User $finanzas;
    private User $superadmin;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->contractor = Contractor::factory()->create();

        // USD ya viene sembrada como moneda base (create_currencies_table).
        ExchangeRate::create(['currency_code' => 'USD', 'rate_to_usd' => 100, 'source' => 'BCV', 'effective_at' => now()->subDay()]);
    }

    // ===== RateFreezeService (unidad) =====

    public function test_freeze_for_trigger_creates_snapshot_with_current_bcv_rate(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);

        $freeze = app(RateFreezeService::class)->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        $this->assertNotNull($freeze);
        $this->assertSame('USD', $freeze->base_currency);
        $this->assertEquals(100, $freeze->frozen_rate);
        $this->assertEquals(1500.0, $freeze->frozen_amount_base);
        $this->assertSame('AUTO', $freeze->source);
        $this->assertNull($freeze->superseded_by_id);
    }

    public function test_freeze_for_trigger_is_idempotent(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $service = app(RateFreezeService::class);

        $first = $service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);
        $second = $service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 9999.0);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertEquals(1, ProjectRateFreeze::where('project_id', $project->id)->count());
    }

    public function test_freeze_for_trigger_does_nothing_when_setting_disabled(): void
    {
        AppSetting::where('key', 'congelar_tasa_en_contratacion')->update(['value' => 'false']);
        SettingsService::forget();

        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $freeze = app(RateFreezeService::class)->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        $this->assertNull($freeze);
        $this->assertEquals(0, ProjectRateFreeze::where('project_id', $project->id)->count());
    }

    public function test_freeze_for_trigger_records_null_rate_when_no_exchange_rate_exists(): void
    {
        ExchangeRate::query()->delete();
        $project = Project::factory()->create(['status' => 'CONTRATADO']);

        $freeze = app(RateFreezeService::class)->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        $this->assertNotNull($freeze);
        $this->assertNull($freeze->frozen_rate);
        $this->assertNull($freeze->exchange_rate_id);
    }

    public function test_freeze_manually_supersedes_previous_active_freeze(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $service = app(RateFreezeService::class);

        $auto = $service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        ExchangeRate::create(['currency_code' => 'USD', 'rate_to_usd' => 120, 'source' => 'BCV', 'effective_at' => now()]);
        $manual = $service->freezeManually($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 'Corrección: la tasa BCV original era errónea.', 1500.0);

        $this->assertSame('MANUAL', $manual->source);
        $this->assertEquals(120, $manual->frozen_rate);
        $this->assertEquals($manual->id, $auto->fresh()->superseded_by_id);
        $this->assertNull($manual->superseded_by_id);

        // La activa ahora es la manual, no la automática.
        $active = ProjectRateFreeze::where('project_id', $project->id)->where('trigger', ProjectRateFreeze::TRIGGER_CONTRATADO)->active()->first();
        $this->assertEquals($manual->id, $active->id);
    }

    // ===== Integración con los triggers reales (ProjectController) =====

    public function test_send_to_finance_freezes_rate_for_contratado(): void
    {
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 28000.00,
        ]);

        $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/select-contractor", [
                'contractorCode' => $this->contractor->code,
                'proposalId' => $proposal->id,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'PENDIENTE_PRESIDENCIA');

        $this->assertDatabaseMissing('project_rate_freezes', ['project_id' => $project->id]);

        $project->update(['status' => 'APROBADO_PRESIDENCIA']);
        $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/send-to-finance")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'CONTRATADO');

        $this->assertDatabaseHas('project_rate_freezes', [
            'project_id' => $project->id,
            'trigger' => 'CONTRATADO',
            'frozen_rate' => 100,
            'frozen_amount_base' => 28000.00,
            'source' => 'AUTO',
        ]);
    }

    public function test_pay_advance_and_final_freeze_their_own_triggers(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $doc = \App\Models\ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", [
                'paymentType' => 'ADVANCE',
                'amount' => 6000.00,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('project_rate_freezes', [
            'project_id' => $project->id,
            'trigger' => 'PAGO_ANTICIPO',
            'frozen_amount_base' => 6000.00,
            'source' => 'AUTO',
        ]);

        $project->update(['status' => 'LISTO_PAGO_FINAL']);
        $doc = \App\Models\ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_FINIQUITO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", [
                'paymentType' => 'FINAL',
                'amount' => 14000.00,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('project_rate_freezes', [
            'project_id' => $project->id,
            'trigger' => 'PAGO_FINIQUITO',
            'frozen_amount_base' => 14000.00,
            'source' => 'AUTO',
        ]);

        // El anticipo no se toca al pagar el finiquito — cada trigger es independiente.
        $this->assertEquals(2, \App\Models\ProjectRateFreeze::where('project_id', $project->id)->count());
    }

    // ===== ProjectRateFreezeController =====

    public function test_index_lists_freezes_for_any_authenticated_role(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        app(RateFreezeService::class)->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1000.0);

        $this->actingAs($this->finanzas)
            ->getJson("/api/projects/{$project->id}/rate-freezes")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.trigger', 'CONTRATADO');
    }

    public function test_store_requires_superadmin(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/rate-freezes", [
                'trigger' => 'PAGO_ANTICIPO',
                'reason' => 'Corrección manual de la tasa aplicada.',
            ])
            ->assertStatus(403);
    }

    public function test_store_requires_reason_with_minimum_length(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);

        $this->actingAs($this->superadmin)
            ->postJson("/api/projects/{$project->id}/rate-freezes", [
                'trigger' => 'PAGO_ANTICIPO',
                'reason' => 'corto',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_store_creates_manual_freeze_and_audits_it(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);

        $response = $this->actingAs($this->superadmin)
            ->postJson("/api/projects/{$project->id}/rate-freezes", [
                'trigger' => 'PAGO_ANTICIPO',
                'reason' => 'Tasa acordada contractualmente con el proveedor, distinta a la BCV.',
                'amountBase' => 6000.00,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.source', 'MANUAL');
        $response->assertJsonPath('data.frozenByName', $this->superadmin->name);

        $this->assertDatabaseHas('project_rate_freezes', [
            'project_id' => $project->id,
            'trigger' => 'PAGO_ANTICIPO',
            'source' => 'MANUAL',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'action' => 'Congelación manual de tasa de cambio',
        ]);
    }
}
