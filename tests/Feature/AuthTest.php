<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('sanctum.expiration', 1440);
    }

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('secret123'),
            'role'     => 'INFRAESTRUCTURA',
            'status'   => 'Active',
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'user@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'email', 'role'],
        ]);
        $this->assertNotNull($response->json('token'));
        $this->assertEquals('INFRAESTRUCTURA', $response->json('user.role'));
    }

    public function test_login_with_invalid_password_returns_422(): void
    {
        User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'user@test.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_with_nonexistent_email_returns_422(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'ghost@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_with_inactive_user_returns_422(): void
    {
        User::factory()->create([
            'email'    => 'inactive@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Inactive',
        ]);

        $response = $this->postJson('/api/login', [
            'email'    => 'inactive@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_login_enforces_max_two_sessions(): void
    {
        $user = User::factory()->create([
            'email'    => 'user@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Active',
        ]);

        // Create 2 existing tokens
        $user->createToken('session-1');
        $user->createToken('session-2');
        $this->assertCount(2, $user->tokens);

        // Third login should succeed and drop oldest token
        $response = $this->postJson('/api/login', [
            'email'    => 'user@test.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200);
        // Still only 2 tokens max — oldest was dropped
        $this->assertCount(2, $user->tokens()->get());
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);
        $token = $user->createToken('test');

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => 'ANALISTA',
            ],
        ]);
    }

    public function test_me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/user');
        $response->assertStatus(401);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $response = $this->postJson('/api/logout', [], [
            'Authorization' => 'Bearer ' . $token->plainTextToken,
        ]);

        $response->assertStatus(204);
        $this->assertCount(0, $user->tokens);
    }

    public function test_logout_without_token_returns_401(): void
    {
        $response = $this->postJson('/api/logout');
        $response->assertStatus(401);
    }

    public function test_token_works_with_device_name(): void
    {
        $user = User::factory()->create([
            'email'    => 'device@test.com',
            'password' => bcrypt('secret123'),
            'status'   => 'Active',
        ]);

        $response = $this->postJson('/api/login', [
            'email'       => 'user@test.com',
            'password'    => 'secret123',
            'device_name' => 'mobile-app',
        ]);

        // Login should still work — device_name is optional
        $response = $this->postJson('/api/login', [
            'email'       => 'device@test.com',
            'password'    => 'secret123',
            'device_name' => 'mobile-app',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'user']);
    }
}
