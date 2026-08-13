<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_value_casted_by_type(): void
    {
        $this->assertSame(100, SettingsService::get('anticipo_maximo_porcentaje'));
        $this->assertSame(false, SettingsService::get('cambios_bloqueados'));
        $this->assertSame(0.0, SettingsService::get('inflacion_referencia_anual_porcentaje'));
        $this->assertIsArray(SettingsService::get('acciones_con_correo'));
    }

    public function test_get_returns_default_when_key_does_not_exist(): void
    {
        $this->assertSame('fallback', SettingsService::get('clave_inexistente', 'fallback'));
    }

    public function test_get_returns_null_when_value_is_null_and_no_default_given(): void
    {
        $this->assertNull(SettingsService::get('razon_social'));
    }

    public function test_all_returns_every_setting_keyed_by_key(): void
    {
        $all = SettingsService::all();

        $this->assertArrayHasKey('anticipo_maximo_porcentaje', $all);
        $this->assertArrayHasKey('semaforo_umbral_verde', $all);
        $this->assertCount(AppSetting::count(), $all);
    }

    public function test_forget_clears_the_cache_so_subsequent_reads_hit_the_database(): void
    {
        $this->assertSame(100, SettingsService::get('anticipo_maximo_porcentaje'));

        AppSetting::where('key', 'anticipo_maximo_porcentaje')->update(['value' => '75']);
        // Sin forget(), la lectura seguiría devolviendo el valor cacheado (100).
        $this->assertSame(100, SettingsService::get('anticipo_maximo_porcentaje'));

        SettingsService::forget();

        $this->assertSame(75, SettingsService::get('anticipo_maximo_porcentaje'));
    }
}
