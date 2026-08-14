<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PositionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Agent ' . fake()->unique()->numberBetween(1, 9999),
            'description' => 'Test position',
            'is_supervisory' => false,
        ];
    }

    public function supervisory(): static
    {
        return $this->state(['is_supervisory' => true]);
    }
}
