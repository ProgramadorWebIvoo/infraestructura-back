<?php

namespace Tests\Feature;

use App\Models\NotificationRule;
use App\Models\User;
use App\Services\NotificationRuleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NotificationRule::query()->delete();
        NotificationRuleResolver::forget();
    }

    public function test_roles_for_returns_configured_roles(): void
    {
        NotificationRule::create(['action' => 'Rechazo de cuadro comparativo', 'role' => 'PROCURA', 'channel' => 'app', 'enabled' => true]);
        NotificationRule::create(['action' => 'Rechazo de cuadro comparativo', 'role' => 'SUPERADMIN', 'channel' => 'app', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $roles = NotificationRuleResolver::rolesFor('Rechazo de cuadro comparativo', 'app');

        $this->assertEqualsCanonicalizing(['PROCURA', 'SUPERADMIN'], $roles);
    }

    public function test_roles_for_unconfigured_action_falls_back_to_admin_roles_on_app_channel(): void
    {
        $roles = NotificationRuleResolver::rolesFor('Accion inexistente', 'app');

        $this->assertEqualsCanonicalizing(['SUPERADMIN', 'ADMIN'], $roles);
    }

    public function test_roles_for_unconfigured_action_returns_empty_on_mail_channel(): void
    {
        // El correo nunca es automático por defecto — solo el canal app
        // tiene fallback administrativo.
        $roles = NotificationRuleResolver::rolesFor('Accion inexistente', 'mail');

        $this->assertSame([], $roles);
    }

    public function test_disabled_rule_is_excluded(): void
    {
        NotificationRule::create(['action' => 'X', 'role' => 'PROCURA', 'channel' => 'app', 'enabled' => false]);
        NotificationRuleResolver::forget();

        // Una acción con solo filas deshabilitadas se comporta como "sin
        // filas" → cae en el fallback administrativo (app), no queda vacía
        // silenciosamente.
        $roles = NotificationRuleResolver::rolesFor('X', 'app');

        $this->assertEqualsCanonicalizing(['SUPERADMIN', 'ADMIN'], $roles);
    }

    public function test_recipients_for_excludes_inactive_users(): void
    {
        NotificationRule::create(['action' => 'X', 'role' => 'PROCURA', 'channel' => 'app', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $active = User::factory()->create(['role' => 'PROCURA', 'status' => 'Active']);
        User::factory()->create(['role' => 'PROCURA', 'status' => 'Inactive']);

        $recipients = NotificationRuleResolver::recipientsFor('X', 'app');

        $this->assertCount(1, $recipients);
        $this->assertSame($active->id, $recipients->first()->id);
    }

    public function test_unconfigured_actions_lists_catalog_entries_without_any_rule(): void
    {
        NotificationRule::create(['action' => 'Creacion de peticion de obra', 'role' => 'CIERRE_DE_OBRA', 'channel' => 'app', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $unconfigured = NotificationRuleResolver::unconfiguredActions();

        $this->assertNotContains('Creacion de peticion de obra', $unconfigured);
        $this->assertContains('Rechazo de cuadro comparativo', $unconfigured);
    }

    public function test_matrix_includes_every_catalog_action_with_empty_arrays_when_unconfigured(): void
    {
        $matrix = NotificationRuleResolver::matrix();

        $this->assertArrayHasKey('Rechazo de cuadro comparativo', $matrix);
        $this->assertSame(['app' => [], 'mail' => []], $matrix['Rechazo de cuadro comparativo']);
    }

    public function test_cache_is_invalidated_by_forget(): void
    {
        $this->assertSame([], NotificationRuleResolver::rolesFor('X', 'mail'));

        NotificationRule::create(['action' => 'X', 'role' => 'PROCURA', 'channel' => 'mail', 'enabled' => true]);
        NotificationRuleResolver::forget();

        $this->assertEqualsCanonicalizing(['PROCURA'], NotificationRuleResolver::rolesFor('X', 'mail'));
    }
}
