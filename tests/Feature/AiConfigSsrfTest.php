<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConfigSsrfTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->superadmin->createToken('test')->plainTextToken];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'openai',
            'model'    => 'gpt-4o-mini',
            'apiKey'   => 'sk-test-12345678',
        ], $overrides);
    }

    public function test_store_rejects_non_https_base_url(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/ai/config', $this->payload(['baseUrl' => 'http://api.example.com']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('baseUrl');
    }

    public function test_store_rejects_localhost_base_url(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/ai/config', $this->payload(['baseUrl' => 'https://localhost']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('baseUrl');
    }

    public function test_store_rejects_literal_private_ip_base_url(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/ai/config', $this->payload(['baseUrl' => 'https://192.168.1.10']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('baseUrl');
    }

    public function test_store_rejects_literal_cloud_metadata_ip_base_url(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/ai/config', $this->payload(['baseUrl' => 'https://169.254.169.254']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('baseUrl');
    }

    public function test_store_accepts_valid_public_https_base_url(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/ai/config', $this->payload(['baseUrl' => 'https://api.openai.com/v1']));

        $response->assertStatus(201);
    }

    public function test_store_accepts_omitted_base_url(): void
    {
        $response = $this->withHeaders($this->headers())
            ->postJson('/api/ai/config', $this->payload());

        $response->assertStatus(201);
    }
}
