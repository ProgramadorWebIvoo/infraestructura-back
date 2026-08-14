<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConfigCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $analista;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->analista = User::factory()->create(['role' => 'ANALISTA']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'openai',
            'model'    => 'gpt-4o-mini',
            'apiKey'   => 'sk-test-12345678',
        ], $overrides);
    }

    public function test_index_lists_configs_with_masked_api_key(): void
    {
        AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-real-secret-1234',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers($this->superadmin))->getJson('/api/ai/config');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.hasApiKey', true);
        $response->assertJsonPath('0.apiKey', '••••1234');
        $this->assertStringNotContainsString('sk-real-secret-1234', $response->getContent());
    }

    public function test_store_creates_config_and_masks_response(): void
    {
        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/ai/config', $this->payload());

        $response->assertStatus(201);
        $response->assertJsonPath('provider', 'openai');
        $response->assertJsonPath('hasApiKey', true);
        $this->assertStringNotContainsString('sk-test-12345678', $response->getContent());

        $this->assertDatabaseHas('ai_configurations', [
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);
    }

    public function test_store_response_includes_the_audit_log_for_live_panel_update(): void
    {
        // El frontend inserta esta entrada en vivo en el panel de auditoría
        // (prependLocal) sin re-consultar /config-audit-logs — regresión del
        // hallazgo "el cambio no aparece en auditlogs sin refrescar".
        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/ai/config', $this->payload());

        $response->assertStatus(201);
        $response->assertJsonPath('auditLog.action', 'Alta de configuracion de IA');
        $response->assertJsonPath('auditLog.entityType', 'ai_config');
    }

    public function test_store_rejects_duplicate_provider_model(): void
    {
        $this->withHeaders($this->headers($this->superadmin))->postJson('/api/ai/config', $this->payload());

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/ai/config', $this->payload());

        $response->assertStatus(422);
    }

    public function test_store_rejects_invalid_provider(): void
    {
        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/ai/config', $this->payload(['provider' => 'not-a-real-provider']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('provider');
    }

    public function test_show_returns_single_config(): void
    {
        $config = AiConfiguration::create([
            'provider' => 'gemini',
            'model' => 'gemini-1.5-pro',
            'api_key' => 'sk-gemini-key-999',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers($this->superadmin))->getJson("/api/ai/config/{$config->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('provider', 'gemini');
    }

    public function test_update_changes_model_and_api_key(): void
    {
        $config = AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-old-key-0000',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->patchJson("/api/ai/config/{$config->id}", ['model' => 'gpt-4o']);

        $response->assertStatus(200);
        $response->assertJsonPath('model', 'gpt-4o');
        $response->assertJsonPath('auditLog.action', 'Modificacion de configuracion de IA');
        $this->assertDatabaseHas('ai_configurations', ['id' => $config->id, 'model' => 'gpt-4o']);
    }

    public function test_update_rejects_conflicting_model_for_same_provider(): void
    {
        AiConfiguration::create(['provider' => 'openai', 'model' => 'gpt-4o', 'api_key' => 'sk-a', 'is_active' => true]);
        $config = AiConfiguration::create(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-b', 'is_active' => true]);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->patchJson("/api/ai/config/{$config->id}", ['model' => 'gpt-4o']);

        $response->assertStatus(422);
    }

    public function test_destroy_deletes_config(): void
    {
        $config = AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-key',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers($this->superadmin))->deleteJson("/api/ai/config/{$config->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('auditLog.action', 'Eliminacion de configuracion de IA');
        $this->assertDatabaseMissing('ai_configurations', ['id' => $config->id]);
    }

    public function test_non_admin_role_cannot_access_ai_config(): void
    {
        $response = $this->withHeaders($this->headers($this->analista))->getJson('/api/ai/config');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_ai_config(): void
    {
        $response = $this->getJson('/api/ai/config');

        $response->assertStatus(401);
    }

    // ─── test() / sync() / usage() — sin cobertura previa ───

    public function test_non_admin_role_cannot_access_test_sync_or_usage_endpoints(): void
    {
        $config = AiConfiguration::create($this->payload() + ['api_key' => 'sk-real-secret']);
        $headers = $this->headers($this->analista);

        $this->withHeaders($headers)->postJson("/api/ai/config/{$config->id}/test")->assertStatus(403);
        $this->withHeaders($headers)->postJson('/api/ai/config/sync')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/ai/config/usage')->assertStatus(403);
    }

    public function test_test_endpoint_rejects_config_without_api_key(): void
    {
        $config = AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => '',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson("/api/ai/config/{$config->id}/test");

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'API Key no configurada.']);
    }

    public function test_test_endpoint_rejects_unsupported_provider_without_leaking_key(): void
    {
        $config = AiConfiguration::create([
            'provider' => 'proveedor-inventado',
            'model' => 'x',
            'api_key' => 'sk-super-secret-leak-check',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson("/api/ai/config/{$config->id}/test");

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'Proveedor no soportado.']);
        $this->assertStringNotContainsString('sk-super-secret-leak-check', $response->getContent());
    }

    public function test_sync_endpoint_returns_active_config_count(): void
    {
        AiConfiguration::create(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-1', 'is_active' => true]);
        AiConfiguration::create(['provider' => 'openai', 'model' => 'gpt-4-turbo', 'api_key' => 'sk-2', 'is_active' => false]);

        $response = $this->withHeaders($this->headers($this->superadmin))->postJson('/api/ai/config/sync');

        $response->assertStatus(200);
        $response->assertJsonPath('activeConfigs', 1);
    }

    public function test_usage_endpoint_returns_summary_for_superadmin(): void
    {
        $response = $this->withHeaders($this->headers($this->superadmin))->getJson('/api/ai/config/usage');

        $response->assertStatus(200);
    }

    public function test_update_with_new_api_key_does_not_leak_it_in_audit_log(): void
    {
        $config = AiConfiguration::create($this->payload() + ['api_key' => 'sk-original-key']);

        $this->withHeaders($this->headers($this->superadmin))
            ->patchJson("/api/ai/config/{$config->id}", ['apiKey' => 'sk-brand-new-secret-value'])
            ->assertStatus(200);

        $log = \App\Models\ConfigAuditLog::where('entity_type', 'ai_config')
            ->where('action', 'Modificacion de configuracion de IA')
            ->latest('id')
            ->firstOrFail();

        $this->assertStringNotContainsString('sk-brand-new-secret-value', (string) $log->new_value);
        $this->assertStringNotContainsString('sk-original-key', (string) $log->new_value);
    }
}
