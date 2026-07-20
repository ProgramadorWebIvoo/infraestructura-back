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

        // Old token must be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $original->accessToken->id,
        ]);

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

        $expired = $user->createToken('expired');
        $expired->accessToken->expires_at = now()->subDay();
        $expired->accessToken->save();

        $valid = $user->createToken('valid');
        $valid->accessToken->expires_at = now()->addDay();
        $valid->accessToken->save();

        $this->artisan('sanctum:clear-expired-tokens')
            ->expectsOutputToContain('Deleted 1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $expired->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $valid->accessToken->id,
        ]);
    }

    public function test_refresh_deletes_old_token(): void
    {
        $user = User::factory()->create();
        $original = $user->createToken('test');

        DB::table('personal_access_tokens')
            ->where('id', $original->accessToken->id)
            ->update(['created_at' => now()->subMinutes(1410)]);

        $response = $this->getJson('/api/user', [
            'Authorization' => 'Bearer '.$original->plainTextToken,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('X-Refresh-Token');

        // Old token deleted from DB
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $original->accessToken->id,
        ]);
    }
}
