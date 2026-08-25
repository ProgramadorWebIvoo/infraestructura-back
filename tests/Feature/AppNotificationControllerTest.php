<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_deletes_own_notification(): void
    {
        $user = User::factory()->create();
        $notification = AppNotification::create(['user_id' => $user->id, 'action' => 'Test']);

        $this->actingAs($user)
            ->deleteJson("/api/notifications/{$notification->id}")
            ->assertOk();

        $this->assertDatabaseMissing('app_notifications', ['id' => $notification->id]);
    }

    public function test_destroy_forbids_deleting_another_users_notification(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notification = AppNotification::create(['user_id' => $owner->id, 'action' => 'Test']);

        $this->actingAs($other)
            ->deleteJson("/api/notifications/{$notification->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('app_notifications', ['id' => $notification->id]);
    }

    public function test_destroy_all_deletes_only_the_authenticated_users_notifications(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = AppNotification::create(['user_id' => $user->id, 'action' => 'Mine']);
        $theirs = AppNotification::create(['user_id' => $other->id, 'action' => 'Theirs']);

        $this->actingAs($user)
            ->deleteJson('/api/notifications')
            ->assertOk();

        $this->assertDatabaseMissing('app_notifications', ['id' => $mine->id]);
        $this->assertDatabaseHas('app_notifications', ['id' => $theirs->id]);
    }
}
