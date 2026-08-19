<?php

namespace Database\Factories;

use App\Models\Contractor;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractorFactory extends Factory
{
    protected $model = Contractor::class;

    public function definition(): array
    {
        return [
            'code' => 'CON-' . fake()->unique()->randomNumber(4),
            'name' => fake()->company(),
            'specialty' => fake()->randomElement(['Construcción Civil', 'Electricidad', 'Plomería', 'Estructuras Metálicas', 'Pintura']),
            'rating' => fake()->randomFloat(1, 3.0, 5.0),
            'email' => fake()->email(),
            'phone' => fake()->optional()->phoneNumber(),
            'registration_source' => 'SEED',
            'status' => 'ACTIVE',
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn() => ['status' => 'PENDING_REVIEW']);
    }

    public function inactive(): static
    {
        return $this->state(fn() => ['status' => 'INACTIVE']);
    }
}
