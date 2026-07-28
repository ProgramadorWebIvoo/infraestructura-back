<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SupplierMaterialProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierMaterialProposalFactory extends Factory
{
    protected $model = SupplierMaterialProposal::class;

    public function definition(): array
    {
        return [
            'id' => 'SMP-' . fake()->unique()->randomNumber(3),
            'project_id' => Project::factory(),
            'project_title_snapshot' => fake()->sentence(4),
            'supplier_name' => fake()->name(),
            'supplier_company' => fake()->company(),
            'supplier_contact' => fake()->email(),
            'items' => [
                ['name' => 'Cemento', 'quantity' => 100, 'unitPrice' => 12.50, 'totalPrice' => 1250.00],
                ['name' => 'Acero', 'quantity' => 50, 'unitPrice' => 25.00, 'totalPrice' => 1250.00],
            ],
            'general_notes' => fake()->optional()->paragraph(),
            'estimated_days' => fake()->numberBetween(15, 120),
            'duration_unit' => fake()->randomElement(['dias', 'semanas', 'meses']),
            'advance_percent' => fake()->randomElement([0, 10, 20, 30, 40, 50]),
        ];
    }
}
