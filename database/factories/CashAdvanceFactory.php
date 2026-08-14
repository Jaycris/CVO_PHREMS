<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class CashAdvanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'reference_no' => 'CA-TEST-' . fake()->unique()->numberBetween(1000, 9999),
            'principal_amount' => 3000,
            'amount_per_cutoff' => 1500,
            'start_date' => '2026-08-01',
            'status' => 'active',
            'source' => 'hr_recorded',
            'deduction_plan' => 'split_two_cutoffs',
        ];
    }
}
