<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AppSetting;
use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertStaleProjectsAndExpiringInvitationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_a_project_with_no_activity_past_the_configured_threshold(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $stale = Project::factory()->create(['status' => 'REVISADO_CIERRE', 'updated_at' => now()->subDays(20)]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $admin->id,
            'project_id' => $stale->id,
            'action' => 'Obra sin actividad reciente',
            'type' => 'advertencia',
        ]);
    }

    public function test_does_not_notify_a_recently_active_project(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        $active = Project::factory()->create(['status' => 'REVISADO_CIERRE', 'updated_at' => now()->subDays(2)]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'project_id' => $active->id,
            'action' => 'Obra sin actividad reciente',
        ]);
    }

    public function test_does_not_notify_a_completed_project_regardless_of_inactivity(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        $completed = Project::factory()->create(['status' => 'COMPLETADO_PAGADO', 'updated_at' => now()->subDays(60)]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'project_id' => $completed->id,
            'action' => 'Obra sin actividad reciente',
        ]);
    }

    public function test_does_not_notify_the_same_stale_project_twice_the_same_day(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        Project::factory()->create(['status' => 'REVISADO_CIERRE', 'updated_at' => now()->subDays(20)]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();
        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertSame(1, AppNotification::where('action', 'Obra sin actividad reciente')->count());
    }

    public function test_respects_configurable_threshold_via_config_app(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create(['status' => 'REVISADO_CIERRE', 'updated_at' => now()->subDays(5)]);

        AppSetting::where('key', 'proyecto_estancado_umbral_dias')->update(['value' => '3']);
        SettingsService::forget();

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', [
            'project_id' => $project->id,
            'action' => 'Obra sin actividad reciente',
        ]);
    }

    public function test_notifies_an_invitation_expiring_within_the_warning_window(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create();
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $project->id,
            'expires_at' => now()->addHours(10),
        ]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $admin->id,
            'project_id' => $project->id,
            'action' => 'Invitacion a proveedor proxima a vencer',
            'type' => 'advertencia',
        ]);
        $this->assertStringContainsString(
            "ID {$invitation->id}",
            AppNotification::where('action', 'Invitacion a proveedor proxima a vencer')->first()->details,
        );
    }

    public function test_does_not_notify_an_invitation_far_from_expiring(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create();
        SupplierInvitation::factory()->create([
            'project_id' => $project->id,
            'expires_at' => now()->addDays(5),
        ]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'action' => 'Invitacion a proveedor proxima a vencer',
        ]);
    }

    public function test_does_not_notify_an_already_used_invitation(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create();
        SupplierInvitation::factory()->used()->create([
            'project_id' => $project->id,
            'expires_at' => now()->addHours(10),
        ]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'action' => 'Invitacion a proveedor proxima a vencer',
        ]);
    }

    public function test_does_not_notify_an_already_expired_invitation(): void
    {
        User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create();
        SupplierInvitation::factory()->expired()->create([
            'project_id' => $project->id,
        ]);

        $this->artisan('alertas:vencimientos')->assertSuccessful();

        $this->assertDatabaseMissing('app_notifications', [
            'action' => 'Invitacion a proveedor proxima a vencer',
        ]);
    }

    public function test_both_actions_are_registered_in_the_notification_catalog(): void
    {
        $this->assertContains('Obra sin actividad reciente', \App\Support\NotificationCatalog::keys());
        $this->assertContains('Invitacion a proveedor proxima a vencer', \App\Support\NotificationCatalog::keys());
    }
}
