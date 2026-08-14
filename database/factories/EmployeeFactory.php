<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    /**
     * A regular employee on 20,000 a month, hired well before any test period.
     *
     * The salary is deliberately round: every hand-checked figure in the suite
     * is derived from it, and a number that divides cleanly makes a wrong
     * result obvious rather than plausible.
     *
     * Enrolment flags default ON even though the company currently deducts
     * nothing, so the statutory tests exercise the calculation. Tests that care
     * about the company's real setting turn them off explicitly.
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_email' => fake()->unique()->safeEmail(),
            'department_id' => Department::factory(),
            'position_id' => Position::factory(),
            'hire_date' => '2020-01-01',
            'basic_salary' => 20000,
            'allowance' => 0,
            'employment_status' => 'Regular',
            'include_in_payroll' => true,
            'sss_enrolled' => true,
            'philhealth_enrolled' => true,
            'pagibig_enrolled' => true,
            'bir_withholding_enrolled' => true,
            'allowance_taxable' => false,
        ];
    }

    public function salary(float $amount): static
    {
        return $this->state(['basic_salary' => $amount]);
    }

    public function hiredOn(string $date): static
    {
        return $this->state(['hire_date' => $date]);
    }

    public function separatedOn(string $date): static
    {
        return $this->state(['separation_date' => $date]);
    }

    /** Nothing statutory is deducted — the company's current setup. */
    public function notEnrolled(): static
    {
        return $this->state([
            'sss_enrolled' => false,
            'philhealth_enrolled' => false,
            'pagibig_enrolled' => false,
            'bir_withholding_enrolled' => false,
        ]);
    }

    public function excludedFromPayroll(): static
    {
        return $this->state(['include_in_payroll' => false]);
    }
}
