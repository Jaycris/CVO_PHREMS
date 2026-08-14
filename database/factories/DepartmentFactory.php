<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Operations ' . fake()->unique()->numberBetween(1, 9999),
            'description' => 'Test department',
        ];
    }
}
