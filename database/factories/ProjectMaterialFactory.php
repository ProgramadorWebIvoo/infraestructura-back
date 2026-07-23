<?php

namespace Database\Factories;

use App\Models\MaterialCatalog;
use App\Models\ProjectMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectMaterialFactory extends Factory
{
    protected $model = ProjectMaterial::class;

    public function definition(): array
    {
        return [
            'id' => fake()->unique()->uuid(),
            'project_id' => \App\Models\Project::factory(),
            'material_catalog_id' => MaterialCatalog::factory(),
            'name' => fake()->word(),
            'quantity' => fake()->randomFloat(2, 1, 100),
            'unit' => fake()->randomElement(['kg', 'm', 'm2', 'm3', 'unidad']),
            'estimated_unit_price' => fake()->randomFloat(2, 10, 5000),
        ];
    }
}
