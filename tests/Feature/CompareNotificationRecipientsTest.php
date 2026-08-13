<?php

namespace Tests\Feature;

use App\Models\NotificationRule;
use App\Services\NotificationRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompareNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_succeeds_when_seeded_matrix_matches_legacy(): void
    {
        // La migración D.1 ya sembró notification_rules por equivalencia
        // exacta — el comando debe confirmar que no hay diferencias.
        $this->artisan('notifications:compare-recipients')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_a_seeded_action_diverges_from_legacy(): void
    {
        NotificationRule::where('action', 'Rechazo de cuadro comparativo')->where('channel', 'app')->delete();
        NotificationRule::create(['action' => 'Rechazo de cuadro comparativo', 'role' => 'FINANZAS', 'channel' => 'app', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $this->artisan('notifications:compare-recipients')
            ->assertExitCode(1);
    }
}
