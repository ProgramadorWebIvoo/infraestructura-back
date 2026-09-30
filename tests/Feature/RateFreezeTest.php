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

    /** Fija el momento de congelación (radio único en CONFIG APP). */
    private function setFreezeMoment(string $moment): void
    {
        AppSetting::where('key', 'congelar_tasa_momento')->update(['value' => $moment]);
        SettingsService::forget();
    }

    // ===== RateFreezeService (unidad) =====

    public function test_freeze_for_trigger_creates_snapshot_with_current_bcv_rate(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);

        $freeze = app(RateFreezeService::class)->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        $this->assertNotNull($freeze);
        $this->assertSame('USD', $freeze->base_currency);
        $this->assertSame('USD', $freeze->frozen_currency);
        $this->assertEquals(100, $freeze->frozen_rate);
        $this->assertEquals(1500.0, $freeze->frozen_amount_base);
        $this->assertEquals(1500.0, $freeze->frozen_amount);
        $this->assertEquals(150000.0, $freeze->frozen_amount_bs);
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

    public function test_the_freeze_moment_defaults_to_contratado_and_the_old_toggles_are_gone(): void
    {
        $this->assertDatabaseHas('app_settings', ['key' => 'congelar_tasa_momento', 'value' => 'CONTRATADO']);
        $this->assertDatabaseMissing('app_settings', ['key' => 'congelar_tasa_en_contratacion']);
        $this->assertDatabaseMissing('app_settings', ['key' => 'congelar_tasa_en_pago_anticipo']);
        $this->assertDatabaseMissing('app_settings', ['key' => 'congelar_tasa_en_pago_finiquito']);
    }

    public function test_only_the_configured_moment_freezes(): void
    {
        $this->setFreezeMoment('PAGO_ANTICIPO');
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $service = app(RateFreezeService::class);

        $this->assertNull($service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0));
        $this->assertNull($service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_PAGO_FINIQUITO, 1500.0));
        $this->assertNotNull($service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_PAGO_ANTICIPO, 1500.0));
        $this->assertEquals(1, ProjectRateFreeze::where('project_id', $project->id)->count());
    }

    public function test_freeze_for_trigger_does_nothing_when_setting_disabled(): void
    {
        $this->setFreezeMoment('NINGUNO');

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

    public function test_an_empty_freeze_is_completed_when_the_rate_appears(): void
    {
        ExchangeRate::query()->delete();
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $service = app(RateFreezeService::class);

        $empty = $service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);
        $this->assertNull($empty->frozen_rate);

        // Sigue sin tasa: no se acumulan filas vacías.
        $this->assertNull($service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0));
        $this->assertEquals(1, ProjectRateFreeze::where('project_id', $project->id)->count());

        ExchangeRate::create(['currency_code' => 'USD', 'rate_to_usd' => 100, 'source' => 'BCV', 'effective_at' => now()->subMinute()]);
        $completed = $service->freezeForTrigger($project, ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        $this->assertNotNull($completed);
        $this->assertEquals(150000.0, $completed->frozen_amount_bs);
        $this->assertEquals($completed->id, $empty->fresh()->superseded_by_id);
        $this->assertEquals(1, ProjectRateFreeze::where('project_id', $project->id)->active()->count());
    }

    public function test_the_manual_override_audit_records_the_result_and_flags_an_ignored_amount(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 28000.00,
        ]);
        $project->update(['selected_proposal_id' => $proposal->id]);

        $this->actingAs($this->superadmin)
            ->postJson("/api/projects/{$project->id}/rate-freezes", ['trigger' => 'CONTRATADO', 'reason' => 'Corrección de la tasa del día de adjudicación.', 'amountBase' => 1.0])
            ->assertStatus(201);

        $entry = \App\Models\AuditLog::where('project_id', $project->id)->where('action', 'Congelación manual de tasa de cambio')->firstOrFail();
        $this->assertStringContainsString('28000', $entry->details);
        $this->assertStringContainsString('ignorado', $entry->details);
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

    private function projectWithAdvanceOrder(): Project
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 20000.00,
            'negotiated_advance_percent' => 30,
        ]);
        $project->update(['selected_contractor_code' => $this->contractor->code, 'selected_proposal_id' => $proposal->id]);
        app(\App\Services\PaymentOrderService::class)->generate($project->fresh(), \App\Models\PaymentOrder::TYPE_ADVANCE);
        \App\Models\ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        return $project;
    }

    public function test_paying_does_not_freeze_when_the_moment_is_contratado(): void
    {
        $project = $this->projectWithAdvanceOrder();

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 6000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 6000.00])
            ->assertStatus(200);

        $this->assertDatabaseMissing('project_rate_freezes', ['project_id' => $project->id]);
    }

    public function test_pay_advance_and_final_freeze_only_when_they_are_the_configured_moment(): void
    {
        $this->setFreezeMoment('PAGO_ANTICIPO');
        $project = $this->projectWithAdvanceOrder();

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 6000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 6000.00])
            ->assertStatus(200);

        $this->assertDatabaseHas('project_rate_freezes', [
            'project_id' => $project->id,
            'trigger' => 'PAGO_ANTICIPO',
            'frozen_currency' => 'USD',
            'frozen_amount' => 6000.00,
            'frozen_amount_base' => 6000.00,
            'frozen_amount_bs' => 600000.00,
            'source' => 'AUTO',
        ]);

        $this->setFreezeMoment('PAGO_FINIQUITO');
        \App\Models\ProjectClosureReport::updateOrCreate(
            ['project_id' => $project->id],
            ['id' => (string) \Illuminate\Support\Str::uuid(), 'status' => \App\Models\ProjectClosureReport::STATUS_AUDIT_APPROVED, 'finiquito_amount' => 14000.00]
        );
        $project->update(['status' => 'LISTO_PAGO_FINAL']);
        app(\App\Services\PaymentOrderService::class)->generate($project->fresh(), \App\Models\PaymentOrder::TYPE_FINAL);
        \App\Models\ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_FINIQUITO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'FINAL', 'amount' => 14000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 14000.00])
            ->assertStatus(200);

        $this->assertDatabaseHas('project_rate_freezes', ['project_id' => $project->id, 'trigger' => 'PAGO_FINIQUITO', 'frozen_amount_base' => 14000.00, 'source' => 'AUTO']);
        // El anticipo no se toca al pagar el finiquito.
        $this->assertEquals(2, ProjectRateFreeze::where('project_id', $project->id)->count());
    }

    public function test_the_freeze_is_made_in_the_quote_currency_with_its_own_rate(): void
    {
        ExchangeRate::create(['currency_code' => 'USDT', 'rate_to_usd' => 1000, 'source' => 'TEST', 'effective_at' => now()->subMinute()]);
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 1500.00,
            'quote_currency' => 'USDT',
            'total_cost_original' => 1200.00,
            'fx_rate_to_base' => 1.25,
            'base_currency_at_import' => 'USD',
        ]);
        $project->update(['selected_proposal_id' => $proposal->id]);

        $freeze = app(RateFreezeService::class)->freezeForTrigger($project->fresh(), ProjectRateFreeze::TRIGGER_CONTRATADO, 1500.0);

        // Se congelan los Bs. de 1.200 USDT con la tasa USDT — no los 1.500 USD con la BCV.
        $this->assertSame('USDT', $freeze->frozen_currency);
        $this->assertEquals(1200.0, $freeze->frozen_amount);
        $this->assertEquals(1000, $freeze->frozen_rate);
        $this->assertEquals(1200000.0, $freeze->frozen_amount_bs);
        $this->assertEquals(1500.0, $freeze->frozen_amount_base);
        $this->assertSame('USD', $freeze->base_currency);
    }

    public function test_a_payment_freeze_uses_the_order_obligation_currency(): void
    {
        ExchangeRate::create(['currency_code' => 'USDT', 'rate_to_usd' => 1000, 'source' => 'TEST', 'effective_at' => now()->subMinute()]);
        $this->setFreezeMoment('PAGO_ANTICIPO');
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 1500.00,
            'quote_currency' => 'USDT',
            'total_cost_original' => 1200.00,
            'fx_rate_to_base' => 1.25,
            'base_currency_at_import' => 'USD',
            'negotiated_advance_percent' => 30,
        ]);
        $project->update(['selected_contractor_code' => $this->contractor->code, 'selected_proposal_id' => $proposal->id]);
        app(\App\Services\PaymentOrderService::class)->generate($project->fresh(), \App\Models\PaymentOrder::TYPE_ADVANCE);

        $freeze = app(RateFreezeService::class)->freezeForTrigger($project->fresh(), ProjectRateFreeze::TRIGGER_PAGO_ANTICIPO, 450.0);

        // Orden de 360 USDT (equivale a 450 USD).
        $this->assertSame('USDT', $freeze->frozen_currency);
        $this->assertEquals(360.0, $freeze->frozen_amount);
        $this->assertEquals(360000.0, $freeze->frozen_amount_bs);
        $this->assertEquals(450.0, $freeze->frozen_amount_base);
    }

    public function test_the_freeze_moment_setting_only_accepts_a_valid_moment(): void
    {
        $setting = AppSetting::where('key', 'congelar_tasa_momento')->firstOrFail();

        $this->actingAs($this->superadmin)->patchJson("/api/settings/{$setting->id}", ['value' => 'CUALQUIER_COSA'])->assertStatus(422);
        $this->actingAs($this->superadmin)->patchJson("/api/settings/{$setting->id}", ['value' => 'true'])->assertStatus(422);

        foreach (['PAGO_ANTICIPO', 'PAGO_FINIQUITO', 'NINGUNO', 'CONTRATADO'] as $moment) {
            $this->actingAs($this->superadmin)->patchJson("/api/settings/{$setting->id}", ['value' => $moment])->assertStatus(200);
            $this->assertDatabaseHas('app_settings', ['key' => 'congelar_tasa_momento', 'value' => $moment]);
        }
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
