<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_setting_notifies_recipients(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        // Regresión del Hallazgo 1 (auditoría Fase 0-1): AppSettingController
        // auditaba vía ConfigAuditLog::recordSettingChange() sin notificar a
        // nadie — recordSettingChange() ahora dispara notify() internamente.
        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '25'])
            ->assertStatus(200);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $admin->id,
            'action' => 'Modificacion de configuracion',
        ]);
    }

    public function test_index_lists_settings_grouped_by_group(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['presupuesto', 'notificaciones', 'fiscal']]);
    }

    public function test_any_authenticated_role_can_read_settings(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);

        $this->actingAs($user)->getJson('/api/settings')->assertStatus(200);
    }

    public function test_non_admin_cannot_update_a_setting(): void
    {
        $user = User::factory()->create(['role' => 'PROCURA']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($user)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '50'])
            ->assertStatus(403);
    }

    public function test_admin_can_update_a_string_setting(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $setting = AppSetting::where('key', 'razon_social')->firstOrFail();

        $response = $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => 'IVOO Construcciones C.A.']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('app_settings', [
            'key' => 'razon_social',
            'value' => 'IVOO Construcciones C.A.',
        ]);
    }

    public function test_update_rejects_invalid_integer_value(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => 'no-es-un-numero'])
            ->assertStatus(422);
    }

    public function test_update_rejects_invalid_boolean_value(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::create(['group' => 'app', 'key' => 'flag_de_prueba', 'value' => 'false', 'type' => 'boolean']);

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => 'tal-vez'])
            ->assertStatus(422);
    }

    public function test_update_rejects_invalid_json_value(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'acciones_con_correo')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '{invalido'])
            ->assertStatus(422);
    }

    public function test_update_rejects_percentage_value_above_max(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '150'])
            ->assertStatus(422);
    }

    public function test_update_rejects_percentage_value_below_min(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '-5'])
            ->assertStatus(422);
    }

    public function test_update_rejects_notification_retention_above_one_week(): void
    {
        // Purgado destructivo (notifications:prune elimina filas sin
        // posibilidad de recuperación) — rango acotado a 1-7 días.
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'retencion_notificaciones_dias')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '30'])
            ->assertStatus(422);
    }

    public function test_update_rejects_notification_retention_below_one_day(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'retencion_notificaciones_dias')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '0'])
            ->assertStatus(422);
    }

    public function test_label_and_description_are_resolved_from_the_code_catalog_not_the_database(): void
    {
        // label/description no son columnas de app_settings — vienen de
        // App\Support\AppSettingCatalog (código versionado), expuestas vía
        // accessors para no cambiar el shape de la API.
        $setting = AppSetting::where('key', 'razon_social')->firstOrFail();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('app_settings', 'label'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('app_settings', 'description'));
        $this->assertSame('Razón social', $setting->label);
        $this->assertSame('Razón social de la empresa, usada en comprobantes de pago.', $setting->description);
    }

    public function test_settings_endpoint_response_still_includes_label_and_description(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['presupuesto' => [['label', 'description']]]]);
    }

    public function test_unknown_setting_key_falls_back_to_key_as_label(): void
    {
        $setting = AppSetting::create([
            'group' => 'app',
            'key' => 'clave_inventada_sin_catalogo',
            'value' => 'x',
            'type' => 'string',
        ]);

        $this->assertSame('clave_inventada_sin_catalogo', $setting->label);
        $this->assertNull($setting->description);
    }

    public function test_update_invalidates_the_settings_cache(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'anticipo_maximo_porcentaje')->firstOrFail();

        $this->assertSame(100, SettingsService::get('anticipo_maximo_porcentaje'));

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '60'])
            ->assertStatus(200);

        $this->assertSame(60, SettingsService::get('anticipo_maximo_porcentaje'));
    }

    public function test_app_group_settings_exist_with_expected_defaults_and_ranges(): void
    {
        $expected = [
            'proyecto_estancado_umbral_dias' => ['value' => '14', 'min_value' => 1, 'max_value' => 90],
            'documento_tamano_maximo_mb' => ['value' => '25', 'min_value' => 1, 'max_value' => 40],
            'documento_cantidad_maxima_archivos' => ['value' => '10', 'min_value' => 1, 'max_value' => 50],
            'invitacion_proveedor_vigencia_dias' => ['value' => '7', 'min_value' => 1, 'max_value' => 30],
            'sesion_inactividad_minutos' => ['value' => '30', 'min_value' => 5, 'max_value' => 120],
        ];

        foreach ($expected as $key => $ranges) {
            $setting = AppSetting::where('key', $key)->firstOrFail();
            $this->assertSame('app', $setting->group);
            $this->assertSame($ranges['value'], $setting->value);
            $this->assertEquals($ranges['min_value'], $setting->min_value);
            $this->assertEquals($ranges['max_value'], $setting->max_value);
        }
    }

    public function test_update_rejects_max_file_size_above_the_php_physical_limit(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'documento_tamano_maximo_mb')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '60'])
            ->assertStatus(422);
    }

    public function test_update_rejects_invitation_validity_above_thirty_days(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $setting = AppSetting::where('key', 'invitacion_proveedor_vigencia_dias')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/settings/{$setting->id}", ['value' => '60'])
            ->assertStatus(422);
    }

    public function test_cambios_bloqueados_setting_no_longer_exists(): void
    {
        $this->assertDatabaseMissing('app_settings', ['key' => 'cambios_bloqueados']);
    }

    public function test_index_reports_no_missing_settings_when_catalog_is_fully_seeded(): void
    {
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.missing'));
    }

    public function test_index_reports_missing_settings_when_a_row_is_absent(): void
    {
        // Simula el escenario real que motivó este guard: una migración de
        // seed que no corrió (o una fila borrada) deja una key documentada
        // en AppSettingCatalog sin fila en app_settings — antes, el campo
        // simplemente no aparecía en el panel sin ningún rastro.
        AppSetting::where('key', 'proyecto_estancado_umbral_dias')->delete();
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings');

        $response->assertStatus(200);
        $this->assertContains('proyecto_estancado_umbral_dias', $response->json('data.missing'));
    }

    public function test_notification_actions_endpoint_returns_the_real_auditable_actions_catalog(): void
    {
        // Misma fuente que usa NotificationDispatcher al filtrar — el
        // selector de tags en CONFIG APP nunca debe mostrar una acción que
        // la app no dispare realmente. `value` es el string persistido en
        // AuditLog/settings; `label` es el texto legible mostrado en la UI.
        $user = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($user)->getJson('/api/settings/notification-actions');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(count(NotificationCatalog::keys()), $data);
        $this->assertSame(NotificationCatalog::keys(), array_column($data, 'value'));

        $contractorRegisterEntry = collect($data)->firstWhere('value', 'contractor.register');
        $this->assertSame('Registro público de proveedor', $contractorRegisterEntry['label']);

        $scalarActionEntry = collect($data)->firstWhere('value', 'Carga de propuesta');
        $this->assertSame('Carga de propuesta', $scalarActionEntry['label']);
    }
}
