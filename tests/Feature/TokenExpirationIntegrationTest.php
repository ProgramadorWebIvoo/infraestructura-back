<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TokenExpirationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('sanctum.expiration', 1440);
    }

    /** @test */
    public function login_returns_usable_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        $login->assertStatus(200);
        $token = $login->json('token');
        $this->assertNotNull($token);

        // Token works for protected route
        $me = $this->getJson('/api/user', [
            'Authorization' => "Bearer $token",
        ]);
        $me->assertStatus(200);
        $this->assertEquals('test@example.com', $me->json('user.email'));
        // No refresh on fresh token
        $me->assertHeaderMissing('X-Refresh-Token');
    }

    /** @test */
    public function logout_invalidates_token(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $login = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        $token = $login->json('token');

        // Logout
        $logout = $this->postJson('/api/logout', [], [
            'Authorization' => "Bearer $token",
        ]);
        $logout->assertStatus(204);

        // Token deleted from DB
        [$id] = explode('|', $token, 2);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => (int) $id,
        ]);
    }

    /** @test */
    public function public_routes_unaffected(): void
    {
        // Login (public) works
        $user = User::factory()->create([
            'email' => 'public@example.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'public@example.com',
            'password' => 'secret',
        ]);
        $response->assertStatus(200);
        $this->assertNotNull($response->json('token'));
    }

    /** @test */
    public function role_middleware_grants_access_after_refresh(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $token = $admin->createToken('test');

        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['created_at' => now()->subMinutes(1410)]);

        // refresh.token runs BEFORE role middleware, so token gets refreshed
        $response = $this->getJson('/api/users', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);

        $response->assertStatus(200);
        // Token refresh happens regardless of role check success
        $response->assertHeader('X-Refresh-Token');
    }

    /** @test */
    public function role_middleware_rejects_unauthorized_users(): void
    {
        $user = User::factory()->create(['role' => 'ANALYST']);
        $token = $user->createToken('test');

        // Token is fresh (no refresh triggered)
        $response = $this->getJson('/api/users', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);
        $response->assertStatus(403);
        $response->assertHeaderMissing('X-Refresh-Token');
    }

    /** @test */
    public function without_middleware_exemptions_still_work(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        // modules route has withoutMiddleware([ThrottleRequests])
        $response = $this->getJson('/api/modules', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function refresh_preserves_token_name_and_abilities(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('my-device', ['read', 'write']);

        DB::table('personal_access_tokens')
            ->where('id', $original->accessToken->id)
            ->update(['created_at' => now()->subMinutes(1410)]);

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);

        $response->assertStatus(200);
        $newTokenPlain = $response->headers->get('X-Refresh-Token');
        $this->assertNotNull($newTokenPlain);

        // New token has preserved name and abilities
        [$newId] = explode('|', $newTokenPlain, 2);
        $newRow = DB::table('personal_access_tokens')->find((int) $newId);
        $this->assertNotNull($newRow);
        $this->assertEquals('my-device', $newRow->name);
        $this->assertEquals(json_encode(['read', 'write']), $newRow->abilities);

        // Old token still exists with grace period expires_at
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $original->accessToken->id,
        ]);
        $oldRow = DB::table('personal_access_tokens')->find($original->accessToken->id);
        $this->assertNotNull($oldRow->expires_at);
        $this->assertTrue(
            now()->diffInSeconds($oldRow->expires_at, true) <= 60,
            'Grace period must be ≤ 60 seconds'
        );
    }

    /** @test */
    public function token_expiration_uses_config_not_expires_at(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');
        // expires_at far in future BUT created_at is 25h ago
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update([
                'created_at' => now()->subHours(25),
                'expires_at' => now()->addYear(),
            ]);

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);
        $response->assertStatus(401);
    }

    /** @test */
    public function refresh_works_on_business_endpoints(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['created_at' => now()->subMinutes(1410)]);

        // Real endpoint: contractors list
        $response = $this->getJson('/api/contractors', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Refresh-Token');
    }

    /** @test */
    public function fresh_token_not_refreshed_on_any_endpoint(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        // Test multiple endpoints with fresh token
        $endpoints = ['/api/user', '/api/modules', '/api/contractors'];
        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint, [
                'Authorization' => 'Bearer '.$token->plainTextToken,
            ]);
            $response->assertStatus(200);
            $response->assertHeaderMissing('X-Refresh-Token');
        }
    }
}
