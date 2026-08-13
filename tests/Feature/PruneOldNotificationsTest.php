<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOldNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_notifications_older_than_configured_retention(): void
    {
        $user = User::factory()->create();

        $old = AppNotification::create(['user_id' => $user->id, 'action' => 'Vieja']);
        $old->forceFill(['created_at' => now()->subDays(100)])->save();

        $recent = AppNotification::create(['user_id' => $user->id, 'action' => 'Reciente']);

        AppSetting::where('key', 'retencion_notificaciones_dias')->update(['value' => '90']);
        SettingsService::forget();

        $this->artisan('notifications:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('app_notifications', ['id' => $old->id]);
        $this->assertDatabaseHas('app_notifications', ['id' => $recent->id]);
    }

    public function test_respects_configured_retention_value(): void
    {
        $user = User::factory()->create();

        $notification = AppNotification::create(['user_id' => $user->id, 'action' => 'Diez dias']);
        $notification->forceFill(['created_at' => now()->subDays(10)])->save();

        AppSetting::where('key', 'retencion_notificaciones_dias')->update(['value' => '7']);
        SettingsService::forget();

        $this->artisan('notifications:prune');

        $this->assertDatabaseMissing('app_notifications', ['id' => $notification->id]);
    }
}
