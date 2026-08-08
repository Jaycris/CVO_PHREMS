<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        LeaveType::firstOrCreate(['code' => 'SL'], [
            'name' => 'Sick Leave',
            'accrual_mode' => 'annual_upfront',
            'default_annual_credits' => 5,
            'resets_annually' => true,
            'allow_carry_over' => false,
            'allow_cash_conversion' => false,
            'is_active' => true,
        ]);

        LeaveType::firstOrCreate(['code' => 'VL'], [
            'name' => 'Vacation Leave',
            'accrual_mode' => 'monthly_accrual',
            'default_annual_credits' => 10,
            'monthly_accrual_rate' => 0.833,
            'accrual_day_of_month' => 10,
            'resets_annually' => false,
            'allow_carry_over' => true,
            'allow_cash_conversion' => true,
            'is_active' => true,
        ]);

        // Event-based: granted per occurrence by HR, never accrued or reset.
        // Nobody is entitled by default — HR ticks the box per employee.
        LeaveType::firstOrCreate(['code' => 'ML'], [
            'name' => 'Maternity Leave',
            'accrual_mode' => 'event_based',
            'default_annual_credits' => 105,
            'resets_annually' => false,
            'allow_carry_over' => false,
            'allow_cash_conversion' => false,
            'is_active' => true,
        ]);

        LeaveType::firstOrCreate(['code' => 'PL'], [
            'name' => 'Paternity Leave',
            'accrual_mode' => 'event_based',
            'default_annual_credits' => 7,
            'resets_annually' => false,
            'allow_carry_over' => false,
            'allow_cash_conversion' => false,
            'is_active' => true,
        ]);
    }
}
