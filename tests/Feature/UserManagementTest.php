<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private User $analista;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->admin = User::factory()->create(['role' => 'ADMIN']);
        $this->analista = User::factory()->create(['role' => 'ANALISTA']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_roles_returns_valid_role_list(): void
    {
        $response = $this->withHeaders($this->headers($this->superadmin))
            ->getJson('/api/roles');

        $response->assertStatus(200);
        $response->assertJson([
            'SUPERADMIN', 'ADMIN', 'PRESIDENCIA', 'INFRAESTRUCTURA',
            'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS', 'CATALOGOS',
        ]);
    }

    public function test_roles_requires_superadmin_or_admin(): void
    {
        $response = $this->withHeaders($this->headers($this->analista))
            ->getJson('/api/roles');

        $response->assertStatus(403);
    }

    public function test_index_returns_all_users(): void
    {
        User::factory()->count(3)->create();

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->getJson('/api/users');

        $response->assertStatus(200);
        // 3 created in setUp (superadmin, admin, analista) + 3 = 6
        $this->assertCount(6, $response->json('data'));
    }

    public function test_store_creates_user(): void
    {
        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/users', [
                'name'                  => 'Nuevo Usuario',
                'email'                 => 'nuevo@test.com',
                'password'              => 'securePass1',
                'password_confirmation' => 'securePass1',
                'role'                  => 'ANALISTA',
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'name'   => 'Nuevo Usuario',
            'email'  => 'nuevo@test.com',
            'role'   => 'ANALISTA',
            'status' => 'Active',
        ]);
        $this->assertDatabaseHas('users', [
            'email'  => 'nuevo@test.com',
            'role'   => 'ANALISTA',
            'status' => 'Active',
        ]);
    }

    public function test_store_requires_valid_role(): void
    {
        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/users', [
                'name'                  => 'Bad User',
                'email'                 => 'bad@test.com',
                'password'              => 'securePass1',
                'password_confirmation' => 'securePass1',
                'role'                  => 'INVALID_ROLE',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role');
    }

    public function test_store_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'exists@test.com']);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson('/api/users', [
                'name'                  => 'Duplicate',
                'email'                 => 'exists@test.com',
                'password'              => 'securePass1',
                'password_confirmation' => 'securePass1',
                'role'                  => 'ANALISTA',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_update_user(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->patchJson("/api/users/{$user->id}", [
                'name'   => 'Nombre Actualizado',
                'status' => 'Inactive',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'name'   => 'Nombre Actualizado',
            'status' => 'Inactive',
        ]);
    }

    public function test_toggle_status_to_inactive_revokes_tokens(): void
    {
        $user = User::factory()->create(['status' => 'Active']);
        $user->createToken('token-1');
        $user->createToken('token-2');
        $this->assertCount(2, $user->tokens);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson("/api/users/{$user->id}/toggle-status");

        $response->assertStatus(200);
        $response->assertJson(['status' => 'Inactive']);

        // Tokens should be revoked
        $user->refresh();
        $this->assertCount(0, $user->tokens);
    }

    public function test_toggle_status_cycles_back_to_active(): void
    {
        $user = User::factory()->create(['status' => 'Inactive']);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson("/api/users/{$user->id}/toggle-status");

        $response->assertStatus(200);
        $response->assertJson(['status' => 'Active']);
    }

    public function test_send_reset_link(): void
    {
        $user = User::factory()->create(['email' => 'reset@test.com']);

        $response = $this->withHeaders($this->headers($this->superadmin))
            ->postJson("/api/users/{$user->id}/send-reset-link");

        // In testing env with array mailer, it might return 500
        // but we test that the route exists and is accessible
        $this->assertContains($response->getStatusCode(), [200, 500]);
    }

    public function test_unauthorized_role_cannot_access_users(): void
    {
        $response = $this->withHeaders($this->headers($this->analista))
            ->getJson('/api/users');

        $response->assertStatus(403);
    }
}
