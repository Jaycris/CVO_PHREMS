<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Vacation Leave ' . fake()->unique()->numberBetween(1, 9999),
            'code' => 'VL' . fake()->unique()->numberBetween(1, 9999),
            'accrual_mode' => 'monthly_accrual',
            'default_annual_credits' => 0,
            'monthly_accrual_rate' => 1.25,
            'accrual_day_of_month' => 1,
            'resets_annually' => true,
            'allow_carry_over' => false,
            'allow_cash_conversion' => true,
            'is_active' => true,
        ];
    }
}
