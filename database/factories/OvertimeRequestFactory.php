<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class OvertimeRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'work_date' => '2026-08-03',
            'hours_requested' => 3,
            'hours_approved' => null,
            'reason' => 'Test overtime',
            'status' => 'pending_manager',
        ];
    }

    public function approved(?float $hours = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'hours_approved' => $hours ?? $attributes['hours_requested'],
            'manager_decision' => 'approved',
            'manager_decided_at' => now(),
        ]);
    }
}
