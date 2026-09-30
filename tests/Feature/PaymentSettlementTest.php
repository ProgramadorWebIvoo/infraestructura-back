<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\ExchangeRate;
use App\Models\PaymentOrder;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\ProjectRateFreeze;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pago registrado "como fue realizado": una obra cotizada en 1.200 USDT
 * (= 1.500 USD base, tasa 1,25) con anticipo del 30% → orden de 360 USDT
 * (450 USD base). Finanzas declara moneda, monto y tasa reales; el sistema
 * calcula el equivalente cubierto, la diferencia y deja todo auditable.
 */
class PaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $procura;
    private User $presidencia;
    private User $finanzas;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $contractor = Contractor::factory()->create();

        foreach (['USD' => 800, 'EUR' => 900, 'USDT' => 1000] as $code => $rate) {
            ExchangeRate::create(['currency_code' => $code, 'rate_to_usd' => $rate, 'source' => 'TEST', 'effective_at' => now()->subMinute()]);
        }

        $this->project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $this->project->id,
            'contractor_code' => $contractor->code,
            'total_cost' => 1500.00,
            'quote_currency' => 'USDT',
            'total_cost_original' => 1200.00,
            'fx_rate_to_base' => 1.25,
            'base_currency_at_import' => 'USD',
            'negotiated_advance_percent' => 30,
        ]);

        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/select-contractor", [
            'contractorCode' => $contractor->code,
            'proposalId' => $proposal->id,
        ])->assertStatus(200);
        $this->actingAs($this->presidencia)->postJson("/api/projects/{$this->project->id}/award-approval")->assertStatus(200);
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/send-to-finance")->assertStatus(200);

        ProjectDocument::create([
            'project_id' => $this->project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf',
            'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1,
        ]);
    }

    private function pay(array $payload)
    {
        return $this->actingAs($this->finanzas)->postJson("/api/projects/{$this->project->id}/payments", array_merge([
            'paymentType' => 'ADVANCE',
            'amount' => 450.00, // importe base (USD) de la obligación de 360 USDT
        ], $payload));
    }

    public function test_the_order_is_in_the_quote_currency_with_its_base_equivalent(): void
    {
        $this->assertDatabaseHas('payment_orders', [
            'project_id' => $this->project->id, 'amount' => 360.00, 'amount_base' => 450.00, 'currency' => 'USDT',
        ]);
    }

    public function test_paid_in_the_quote_currency(): void
    {
        $this->pay(['paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 360.00, 'bank' => 'Binance', 'reference' => 'TX-1'])
            ->assertStatus(200);

        $this->assertDatabaseHas('project_payments', [
            'project_id' => $this->project->id,
            'amount' => 450.00, // base: los agregados no cambian
            'obligation_amount' => 360.00,
            'obligation_currency' => 'USDT',
            'payment_mode' => 'QUOTE_CURRENCY',
            'paid_currency' => 'USDT',
            'paid_amount' => 360.00,
            'applied_rate' => 1.0,
            'covered_amount' => 360.00,
            'difference_amount' => 0.00,
            'difference_reason' => null,
        ]);
    }

    public function test_paid_in_bolivares_records_the_real_rate_and_the_suggested_one(): void
    {
        // 360 USDT * 960 Bs/USDT = 345.600 Bs (la tasa real difiere de la vigente, 1.000).
        $this->pay(['paymentMode' => 'BS', 'paidAmount' => 345600, 'appliedRate' => 960, 'appliedRateSource' => 'USDT'])
            ->assertStatus(200);

        $payment = ProjectPayment::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame('BS', $payment->payment_mode);
        $this->assertSame('VES', $payment->paid_currency);
        $this->assertSame(345600.0, $payment->paid_amount);
        $this->assertSame(960.0, $payment->applied_rate);
        $this->assertSame('USDT', $payment->applied_rate_source);
        $this->assertSame(1000.0, $payment->suggested_rate);
        $this->assertSame(360.0, $payment->covered_amount);
        $this->assertSame(0.0, $payment->difference_amount);
    }

    public function test_paid_in_another_currency(): void
    {
        // 360 USDT a 0,80 USD por USDT = 288 USD.
        $this->pay(['paymentMode' => 'OTHER_CURRENCY', 'paidCurrency' => 'usd', 'paidAmount' => 288, 'appliedRate' => 0.8, 'appliedRateSource' => 'MANUAL'])
            ->assertStatus(200);

        $this->assertDatabaseHas('project_payments', [
            'project_id' => $this->project->id,
            'payment_mode' => 'OTHER_CURRENCY',
            'paid_currency' => 'USD',
            'paid_amount' => 288.00,
            'applied_rate' => 0.8,
            'covered_amount' => 360.00,
        ]);
    }

    public function test_a_difference_requires_a_reason_and_is_recorded_with_it(): void
    {
        // 340.000 Bs / 960 = 354,17 USDT: 5,83 por debajo de la obligación.
        $payload = ['paymentMode' => 'BS', 'paidAmount' => 340000, 'appliedRate' => 960, 'appliedRateSource' => 'MANUAL'];

        $this->pay($payload)->assertStatus(422)->assertJsonValidationErrors('differenceReason');
        $this->assertDatabaseCount('project_payments', 0);

        $this->pay($payload + ['differenceReason' => 'Comisión bancaria descontada'])->assertStatus(200);

        $this->assertDatabaseHas('project_payments', [
            'project_id' => $this->project->id,
            'covered_amount' => 354.17,
            'difference_amount' => -5.83,
            'difference_reason' => 'Comisión bancaria descontada',
        ]);
    }

    public function test_the_mode_is_mandatory(): void
    {
        $this->pay(['paidAmount' => 360])->assertStatus(422)->assertJsonValidationErrors('paymentMode');
    }

    public function test_conversion_modes_require_a_rate_and_its_source(): void
    {
        $this->pay(['paymentMode' => 'BS', 'paidAmount' => 360000])->assertStatus(422)->assertJsonValidationErrors('appliedRate');
        $this->pay(['paymentMode' => 'BS', 'paidAmount' => 360000, 'appliedRate' => 1000])->assertStatus(422)->assertJsonValidationErrors('appliedRateSource');
    }

    public function test_other_currency_must_differ_from_the_obligation_and_from_bolivares(): void
    {
        $base = ['paymentMode' => 'OTHER_CURRENCY', 'paidAmount' => 360, 'appliedRate' => 1, 'appliedRateSource' => 'MANUAL'];

        $this->pay($base + ['paidCurrency' => 'USDT'])->assertStatus(422)->assertJsonValidationErrors('paidCurrency');
        $this->pay($base + ['paidCurrency' => 'VES'])->assertStatus(422)->assertJsonValidationErrors('paidCurrency');
        $this->pay($base)->assertStatus(422)->assertJsonValidationErrors('paidCurrency');
    }

    public function test_the_payment_references_the_frozen_rates_and_the_audit_log_traces_it(): void
    {
        $this->pay(['paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 360])->assertStatus(200);

        $payment = ProjectPayment::where('project_id', $this->project->id)->firstOrFail();
        $contractFreeze = ProjectRateFreeze::where('project_id', $this->project->id)->where('trigger', 'CONTRATADO')->firstOrFail();
        $paymentFreeze = ProjectRateFreeze::where('project_id', $this->project->id)->where('trigger', 'PAGO_ANTICIPO')->firstOrFail();

        $this->assertSame($contractFreeze->id, $payment->contract_rate_freeze_id);
        $this->assertSame($paymentFreeze->id, $payment->payment_rate_freeze_id);

        $entry = AuditLog::where('project_id', $this->project->id)->where('action', 'Liberacion de anticipo')->latest('logged_at')->firstOrFail();
        $this->assertStringContainsString('Pagado 360.00 USDT', $entry->details);
        $this->assertStringContainsString('Obligación 360.00 USDT', $entry->details);
    }

    public function test_the_payment_is_recorded_without_freeze_references_when_the_config_is_off(): void
    {
        \App\Models\AppSetting::where('key', 'congelar_tasa_en_pago_anticipo')->update(['value' => 'false']);
        \App\Services\SettingsService::forget();

        $this->pay(['paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 360])->assertStatus(200);

        $payment = ProjectPayment::where('project_id', $this->project->id)->firstOrFail();
        $this->assertNotNull($payment->contract_rate_freeze_id);
        $this->assertNull($payment->payment_rate_freeze_id);
    }

    public function test_paid_orders_expose_no_stale_state(): void
    {
        $this->pay(['paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 360])->assertStatus(200);

        $this->assertSame('PAGADA', PaymentOrder::where('project_id', $this->project->id)->firstOrFail()->status);
    }
}
