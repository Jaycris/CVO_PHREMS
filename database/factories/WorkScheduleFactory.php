<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WorkScheduleFactory extends Factory
{
    /** A 9-to-6 weekday shift: nine hours on site, one unpaid hour for lunch. */
    public function definition(): array
    {
        return [
            'name' => 'Day Shift ' . fake()->unique()->numberBetween(1, 9999),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'lunch_break_minutes' => 60,
            'coffee_break_minutes' => 15,
            'work_days' => [1, 2, 3, 4, 5],
        ];
    }

    /** Crosses midnight and overlaps the night window, so it earns night differential. */
    public function graveyard(): static
    {
        return $this->state([
            'name' => 'Graveyard ' . fake()->unique()->numberBetween(1, 9999),
            'start_time' => '22:00',
            'end_time' => '07:00',
        ]);
    }

    public function dayShift(): static
    {
        return $this->state(['start_time' => '09:00', 'end_time' => '18:00']);
    }

    /** @param  list<int>  $days  ISO day numbers, 1 = Monday */
    public function workDays(array $days): static
    {
        return $this->state(['work_days' => $days]);
    }
}
