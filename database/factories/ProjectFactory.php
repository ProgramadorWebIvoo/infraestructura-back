<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'id' => 'PRJ-' . fake()->unique()->randomNumber(3),
            'title' => fake()->sentence(4),
            'type' => fake()->randomElement(['INFRAESTRUCTURA', 'MANTENIMIENTO']),
            'description' => fake()->paragraph(),
            'location' => fake()->city(),
            'created_date' => fake()->date(),
            'status' => 'CREADO',
            'estimated_total' => fake()->randomFloat(2, 1000, 50000),
            'calculations_added' => false,
            'blueprints_count' => 0,
            'quality_verified' => false,
        ];
    }

    public function reviewed(): static
    {
        return $this->state(fn() => [
            'status' => 'REVISADO_CIERRE',
            'cierre_obra_notes' => fake()->sentence(),
            'blueprints_count' => fake()->numberBetween(1, 10),
            'calculations_added' => true,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn() => [
            'status' => 'CONFIRMADO_PROCURA',
            'procura_review_notes' => fake()->sentence(),
            'approved_investment_amount' => fake()->randomFloat(2, 5000, 100000),
        ]);
    }
}
