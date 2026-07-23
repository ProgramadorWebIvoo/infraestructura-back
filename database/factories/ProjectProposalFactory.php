<?php

namespace Database\Factories;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectProposalFactory extends Factory
{
    protected $model = ProjectProposal::class;

    public function definition(): array
    {
        $contractor = Contractor::inRandomOrder()->first() ?? ContractorFactory::new()->create();
        $materialCost = fake()->randomFloat(2, 1000, 30000);
        $laborCost = fake()->randomFloat(2, 500, 15000);

        return [
            'id' => 'PROP-' . now()->format('Hisv') . fake()->unique()->randomNumber(2),
            'project_id' => Project::factory(),
            'contractor_code' => $contractor->code,
            'contractor_name_snapshot' => $contractor->name,
            'material_cost' => $materialCost,
            'labor_cost' => $laborCost,
            'total_cost' => $materialCost + $laborCost,
            'delivery_weeks' => fake()->numberBetween(2, 24),
            'negotiated_advance_percent' => fake()->randomFloat(2, 10, 50),
            'description' => fake()->paragraph(),
        ];
    }
}
