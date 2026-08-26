<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el punto más frágil de la migración de notificaciones a WebSocket
 * (plan Fase B): /broadcasting/auth debe autenticar con la sesión Sanctum
 * SPA (grupo 'api'), no con el grupo 'web' que Broadcast::routes() usa por
 * defecto — ver BroadcastServiceProvider::boot(). Un usuario solo puede
 * autorizar su propio canal privado App.Models.User.{id} (routes/channels.php).
 */
class BroadcastingAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_authorize_own_private_channel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.'.$user->id,
        ]);

        $response->assertOk();
        $this->assertArrayHasKey('auth', $response->json());
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.'.$otherUser->id,
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected_from_authorizing_private_channel(): void
    {
        $response = $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-App.Models.User.1',
        ]);

        $response->assertForbidden();
    }
}
