<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\SupplierInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SupplierInvitationFactory extends Factory
{
    protected $model = SupplierInvitation::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid(),
            'project_id' => Project::factory(),
            'supplier_name' => fake()->name(),
            'supplier_company' => fake()->company(),
            'supplier_contact' => fake()->email(),
        ];
    }

    public function used(): static
    {
        return $this->state(fn() => ['used_at' => now()]);
    }

    public function replaced(): static
    {
        return $this->state(fn() => ['replaced_by' => Str::uuid()]);
    }
}
