<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Support\AppSettingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AppSettingController::index() devuelve `missing` como clave hermana
     * a los grupos reales (`data.presupuesto`, `data.app`, etc.) para no
     * romper a los consumidores existentes — lo que asume que ningún
     * `AppSetting.group` real se llama literalmente "missing". Si alguna
     * migración futura sembrara ese nombre de grupo, colisionaría en
     * silencio con la lista de keys faltantes en la respuesta del API.
     * Este test es la guarda: falla en CI antes de que eso llegue a prod.
     */
    public function test_no_seeded_group_is_named_missing(): void
    {
        $groups = AppSetting::query()->distinct()->pluck('group');

        $this->assertNotContains('missing', $groups);
    }

    public function test_catalog_has_no_duplicate_or_empty_keys(): void
    {
        $keys = AppSettingCatalog::keys();

        $this->assertSame(array_unique($keys), $keys, 'El catálogo tiene keys duplicadas.');
        $this->assertNotContains('', $keys, 'El catálogo tiene una key vacía.');
    }

    public function test_every_seeded_setting_has_a_catalog_entry(): void
    {
        // Inverso de missingFrom(): ninguna fila real de app_settings debe
        // quedar sin label/description documentados — evita el mismo tipo
        // de "campo huérfano" que motivó el guard de `missing`, pero en la
        // dirección contraria (fila en BD sin entrada en el catálogo).
        $seededKeys = AppSetting::pluck('key')->all();
        $catalogKeys = AppSettingCatalog::keys();

        $orphaned = array_diff($seededKeys, $catalogKeys);

        $this->assertSame([], array_values($orphaned), 'Hay settings en BD sin entrada en AppSettingCatalog: ' . implode(', ', $orphaned));
    }
}
