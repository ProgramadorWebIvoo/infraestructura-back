<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('sanctum.expiration', 1440);
    }

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('test');

        // Force created_at to 25h ago — beyond the 1440 min config window
        DB::table('personal_access_tokens')
            ->where('id', $original->accessToken->id)
            ->update(['created_at' => now()->subHours(25)]);

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);

        $response->assertStatus(401);
    }

    public function test_valid_token_allows_access(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test');

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);

        $response->assertStatus(200);
    }

    public function test_token_is_refreshed_when_near_expiration(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('test');

        // Set created_at to 23.5h ago — within 1h of the 24h config expiry
        DB::table('personal_access_tokens')
            ->where('id', $original->accessToken->id)
            ->update(['created_at' => now()->subMinutes(1410)]);

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Refresh-Token');

        // Old token still exists with grace period expires_at
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $original->accessToken->id,
        ]);
        $oldToken = DB::table('personal_access_tokens')->find($original->accessToken->id);
        $this->assertNotNull($oldToken->expires_at);

        // New token returned in header must be valid
        $newToken = $response->headers->get('X-Refresh-Token');
        $this->assertNotNull($newToken);
        $this->assertStringContainsString('|', $newToken);

        // The new token should work
        $response2 = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$newToken,
        ]);
        $response2->assertStatus(200);
    }

    public function test_token_not_refreshed_when_far_from_expiration(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('test');

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertHeaderMissing('X-Refresh-Token');

        // Original token unchanged
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $original->accessToken->id,
        ]);
    }

    public function test_clear_expired_tokens_command(): void
    {
        $user = User::factory()->create();

        // Token with expired expires_at
        $expiredColumn = $user->createToken('column-expired');
        $expiredColumn->accessToken->expires_at = now()->subDay();
        $expiredColumn->accessToken->save();

        // Token with null expires_at but old created_at (config-expired)
        $expiredConfig = $user->createToken('config-expired');
        DB::table('personal_access_tokens')
            ->where('id', $expiredConfig->accessToken->id)
            ->update(['created_at' => now()->subHours(25)]);

        // Valid token
        $valid = $user->createToken('valid');
        $valid->accessToken->expires_at = now()->addDay();
        $valid->accessToken->save();

        $this->artisan('sanctum:clear-expired-tokens')
            ->expectsOutputToContain('Deleted 2')
            ->assertExitCode(0);

        // Both expired tokens removed
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $expiredColumn->accessToken->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $expiredConfig->accessToken->id,
        ]);
        // Valid token remains
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $valid->accessToken->id,
        ]);
    }

    public function test_refresh_grace_period_keeps_old_token(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('test');
        $this->assertNull($original->accessToken->expires_at);

        DB::table('personal_access_tokens')
            ->where('id', $original->accessToken->id)
            ->update(['created_at' => now()->subMinutes(1410)]);

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Refresh-Token');

        // Old token still exists with grace period (60s from now)
        $oldToken = DB::table('personal_access_tokens')->find($original->accessToken->id);
        $this->assertNotNull($oldToken);
        $this->assertNotNull($oldToken->expires_at);
        $this->assertTrue(
            now()->diffInSeconds($oldToken->expires_at, true) <= 60,
            'Grace period must be ≤ 60 seconds'
        );

        // Old token is still usable during grace period
        $graceRequest = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);
        $graceRequest->assertStatus(200);
    }
}
