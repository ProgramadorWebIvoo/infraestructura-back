<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo base de materiales. Idempotente por `name` (no trunca, a diferencia
 * de SampleProductionSeeder).
 */
class MaterialCatalogSeeder extends Seeder
{
    private const MATERIALS = [
        ['Cemento Portland (Saco 42.5kg)', 'Saco', 12.50],
        ['Acero de Refuerzo 1/2 pulgada', 'Cabilla', 18.00],
        ['Bloque de Arcilla de 15cm', 'Millar', 450.00],
        ['Arena Lavada para Concreto', 'm3', 35.00],
        ['Piedra Picada para Mezcla', 'm3', 40.00],
        ['Cable de Cobre THHN #10 AWG', 'Rollo (100m)', 110.00],
        ['Lampara LED Industrial 150W', 'Unidad', 55.00],
        ['Pintura de Caucho Profesional (Cunete)', 'Cunete', 85.00],
        ['Tubo de PVC de Agua 3 pulgadas', 'Tubo (6m)', 22.00],
        ['Tablero Electrico Principal de 24 Circuitos', 'Unidad', 320.00],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::MATERIALS as [$name, $unit, $price]) {
            $exists = DB::table('material_catalog')->where('name', $name)->exists();

            if ($exists) {
                continue;
            }

            DB::table('material_catalog')->insert([
                'name' => $name,
                'unit' => $unit,
                'estimated_unit_price' => $price,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
