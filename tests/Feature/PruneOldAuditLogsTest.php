<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ConfigAuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOldAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_audit_logs_older_than_configured_retention(): void
    {
        $project = Project::factory()->create();
        $old = AuditLog::record($project, 'INFRAESTRUCTURA', 'Accion vieja');
        AuditLog::withoutEvents(fn () => $old->forceFill(['logged_at' => now()->subMonths(30)])->save());

        $recent = AuditLog::record($project, 'INFRAESTRUCTURA', 'Accion reciente');

        AppSetting::where('key', 'retencion_auditoria_meses')->update(['value' => '24']);
        SettingsService::forget();

        $this->artisan('audit:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }

    public function test_deletes_config_audit_logs_older_than_configured_retention(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->actingAs($admin);

        $old = ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Viejo');
        ConfigAuditLog::withoutEvents(fn () => $old->forceFill(['changed_at' => now()->subMonths(30)])->save());

        $recent = ConfigAuditLog::recordAdminAction('material', 'Alta de material', null, null, 'Reciente');

        AppSetting::where('key', 'retencion_auditoria_meses')->update(['value' => '24']);
        SettingsService::forget();

        $this->artisan('audit:prune');

        $this->assertDatabaseMissing('config_audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('config_audit_logs', ['id' => $recent->id]);
    }

    public function test_setting_range_is_bounded_in_months(): void
    {
        $setting = AppSetting::where('key', 'retencion_auditoria_meses')->firstOrFail();

        $this->assertSame(6, (int) $setting->min_value);
        $this->assertSame(60, (int) $setting->max_value);
    }
}
