<?php

namespace Database\Factories;

use App\Models\MaterialCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

class MaterialCatalogFactory extends Factory
{
    protected $model = MaterialCatalog::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word() . ' ' . fake()->randomElement(['Cemento', 'Acero', 'Tubería', 'Cable', 'Ladrillo']),
            'unit' => fake()->randomElement(['kg', 'm', 'm2', 'm3', 'unidad', 'litro']),
            'estimated_unit_price' => fake()->randomFloat(2, 10, 5000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['is_active' => false]);
    }
}
