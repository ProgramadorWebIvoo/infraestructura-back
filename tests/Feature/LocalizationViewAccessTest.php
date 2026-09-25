<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationViewAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_roles_get_the_localizations_config_view(): void
    {
        foreach (['ADMIN', 'SUPERADMIN'] as $role) {
            $views = $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/auth/permissions')->json();
            $this->assertContains('/config-localizations', $views);
        }

        foreach (['INFRAESTRUCTURA', 'AUDITORIA', 'RESIDENTE'] as $role) {
            $views = $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/api/auth/permissions')->json();
            $this->assertNotContains('/config-localizations', $views);
        }
    }
}
