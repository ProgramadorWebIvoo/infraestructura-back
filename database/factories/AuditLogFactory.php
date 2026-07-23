<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'id' => 'LOG-' . now()->format('YmdHisv') . fake()->unique()->randomNumber(2),
            'project_id' => Project::factory(),
            'project_title_snapshot' => fake()->sentence(4),
            'role' => fake()->randomElement(['PRESIDENCIA', 'INFRAESTRUCTURA', 'CIERRE_DE_OBRA', 'PROCURA', 'ANALISTA', 'FINANZAS']),
            'user_id' => User::factory(),
            'user_name_snapshot' => fake()->name(),
            'action' => fake()->sentence(3),
            'logged_at' => now(),
            'details' => fake()->optional()->paragraph(),
        ];
    }
}
