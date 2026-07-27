<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConfigModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_available_models_per_provider(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $admin->createToken('test')->plainTextToken,
        ])->getJson('/api/ai/config/models');

        $response->assertStatus(200);
        $response->assertJsonStructure(['openai', 'anthropic', 'gemini']);
        $this->assertNotEmpty($response->json('openai'));
    }

    public function test_requires_superadmin_or_admin(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ])->getJson('/api/ai/config/models');

        $response->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/ai/config/models');
        $response->assertStatus(401);
    }
}
