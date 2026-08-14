<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\StatutoryContributionSetting;
use App\Services\Payroll\StatutoryDeductionCalculator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four government deductions.
 *
 * These figures come from the seeded rates. If a circular changes them the
 * seeder changes too and these expectations move with it — that is intended.
 * What must not change silently is the shape: the clamps, the cutoff
 * targeting, and the enrolment flags.
 */
class StatutoryDeductionTest extends PayrollTestCase
{
    protected function calc(): StatutoryDeductionCalculator
    {
        return (new StatutoryDeductionCalculator)->preload('2026-08-30');
    }

    protected function employee(float $salary): Employee
    {
        return Employee::factory()->salary($salary)->make();
    }

    #[Test]
    public function nothing_is_deducted_while_the_contributions_are_switched_off(): void
    {
        // The company's current setup: every rate loaded, none of them applied.
        $employee = $this->employee(20000);
        $calc = $this->calc();

        $this->assertSame(0.0, $calc->sssEmployeeTotal($employee, 'first'));
        $this->assertSame(0.0, $calc->philHealth($employee, 'second')['employee']);
        $this->assertSame(0.0, $calc->pagIbig($employee, 'second')['employee']);
        $this->assertSame(0.0, $calc->withholdingTax($employee, 20000));
    }

    #[Test]
    public function sss_splits_five_percent_employee_and_ten_percent_employer(): void
    {
        $this->enableStatutory('sss');

        $sss = $this->calc()->sss($this->employee(20000), 'first');

        $this->assertSame(1000.00, $sss['employee']);
        $this->assertSame(2000.00, $sss['employer']);
    }

    #[Test]
    public function pay_above_the_regular_ceiling_goes_to_the_provident_fund(): void
    {
        $this->enableStatutory('sss');
        $calc = $this->calc();

        // 25,000 salary credit: 20,000 regular + 5,000 provident.
        $sss = $calc->sss($this->employee(25000), 'first');

        $this->assertSame(1000.00, $sss['employee'], 'regular share should stop at the ceiling');
        $this->assertSame(250.00, $sss['employee_mpf']);
        $this->assertSame(1250.00, $calc->sssEmployeeTotal($this->employee(25000), 'first'));
    }

    #[Test]
    public function a_salary_outside_the_table_clamps_rather_than_deducting_nothing(): void
    {
        $this->enableStatutory('sss');
        $calc = $this->calc();

        // Below the lowest credit (5,000) and above the highest (35,000).
        $this->assertSame(5000.00, $calc->sss($this->employee(3000), 'first')['monthly_salary_credit']);
        $this->assertSame(35000.00, $calc->sss($this->employee(80000), 'first')['monthly_salary_credit']);
    }

    #[Test]
    public function philhealth_is_held_between_its_floor_and_ceiling(): void
    {
        $this->enableStatutory('philhealth');
        $calc = $this->calc();

        // 5% of monthly pay, split evenly, charged as if 10,000 at the bottom
        // and 100,000 at the top.
        $this->assertSame(250.00, $calc->philHealth($this->employee(8000), 'second')['employee']);
        $this->assertSame(500.00, $calc->philHealth($this->employee(20000), 'second')['employee']);
        $this->assertSame(2500.00, $calc->philHealth($this->employee(150000), 'second')['employee']);
    }

    #[Test]
    public function the_two_philhealth_shares_always_add_back_to_the_premium(): void
    {
        $this->enableStatutory('philhealth');
        $calc = $this->calc();

        // An odd premium is where a naive half-each would lose a centavo.
        foreach ([10333, 21777, 45111] as $salary) {
            $ph = $calc->philHealth($this->employee($salary), 'second');

            $this->assertSame(
                $ph['premium'],
                round($ph['employee'] + $ph['employer'], 2),
                "the shares do not add back to the premium on {$salary}"
            );
        }
    }

    #[Test]
    public function pagibig_applies_its_rate_to_a_capped_base(): void
    {
        $this->enableStatutory('pagibig');
        $calc = $this->calc();

        // 2% of at most 5,000 — so 100 however much is earned above it.
        $this->assertSame(15.00, $calc->pagIbig($this->employee(1500), 'second')['employee'], '1% band');
        $this->assertSame(100.00, $calc->pagIbig($this->employee(5000), 'second')['employee']);
        $this->assertSame(100.00, $calc->pagIbig($this->employee(50000), 'second')['employee'], 'the cap should hold');
    }

    #[Test]
    public function a_contribution_is_zero_on_the_cutoff_it_is_not_targeted_at(): void
    {
        $this->enableStatutory('sss', 'philhealth');
        $calc = $this->calc();
        $employee = $this->employee(20000);

        // Seeded SSS on the first cutoff, PhilHealth on the second.
        $this->assertSame(1000.00, $calc->sss($employee, 'first')['employee']);
        $this->assertSame(0.0, $calc->sss($employee, 'second')['employee']);

        $this->assertSame(0.0, $calc->philHealth($employee, 'first')['employee']);
        $this->assertSame(500.00, $calc->philHealth($employee, 'second')['employee']);
    }

    #[Test]
    public function moving_a_contribution_to_the_other_cutoff_moves_the_deduction(): void
    {
        $this->enableStatutory('philhealth');
        StatutoryContributionSetting::where('code', 'philhealth')->update(['deduct_on_cutoff' => 'first']);

        $calc = $this->calc();
        $employee = $this->employee(20000);

        $this->assertSame(500.00, $calc->philHealth($employee, 'first')['employee']);
        $this->assertSame(0.0, $calc->philHealth($employee, 'second')['employee']);
    }

    #[Test]
    public function an_employee_who_is_not_enrolled_owes_nothing(): void
    {
        $this->enableStatutory();

        $employee = Employee::factory()->salary(25000)->notEnrolled()->make();
        $calc = $this->calc();

        $this->assertSame(0.0, $calc->sssEmployeeTotal($employee, 'first'));
        $this->assertSame(0.0, $calc->philHealth($employee, 'second')['employee']);
        $this->assertSame(0.0, $calc->pagIbig($employee, 'second')['employee']);
        $this->assertSame(0.0, $calc->withholdingTax($employee, 20000));
    }

    #[Test]
    public function withholding_tax_follows_the_bracket_table(): void
    {
        $this->enableStatutory('bir');
        $calc = $this->calc();
        $employee = $this->employee(20000);

        // Hand-worked against the semi-monthly table.
        $this->assertSame(0.0, $calc->withholdingTax($employee, 10416.99), 'below the floor');
        $this->assertSame(0.0, $calc->withholdingTax($employee, 10417), 'exactly at the floor');
        $this->assertSame(237.45, $calc->withholdingTax($employee, 12000), '15% of the excess over 10,417');
        $this->assertSame(937.50, $calc->withholdingTax($employee, 16667), 'bracket base, no excess');
        $this->assertSame(1604.10, $calc->withholdingTax($employee, 20000), '937.50 + 20% of 3,333');
        $this->assertSame(8437.45, $calc->withholdingTax($employee, 50000), '4,270.70 + 25% of 16,667');
    }

    #[Test]
    public function negative_or_zero_taxable_income_is_not_taxed(): void
    {
        $this->enableStatutory('bir');
        $calc = $this->calc();

        $this->assertSame(0.0, $calc->withholdingTax($this->employee(20000), 0));
        $this->assertSame(0.0, $calc->withholdingTax($this->employee(20000), -500));
    }

    #[Test]
    public function the_rates_are_read_once_however_many_employees_there_are(): void
    {
        $this->enableStatutory();

        $calc = new StatutoryDeductionCalculator();

        // Built before the log opens: making an employee resolves a department
        // and a position, and counting those would hide what is being measured.
        $employees = collect(range(0, 99))->map(fn (int $i) => $this->employee(18000 + ($i * 100)));

        DB::enableQueryLog();
        $calc->preload('2026-08-30');
        $afterPreload = count(DB::getQueryLog());

        foreach ($employees as $employee) {
            $calc->sss($employee, 'first');
            $calc->philHealth($employee, 'second');
            $calc->pagIbig($employee, 'second');
            $calc->withholdingTax($employee, 15000);
        }

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The N+1 this replaced cost four queries per employee. If a lookup
        // creeps back in, this is what catches it.
        $this->assertSame($afterPreload, $total, 'the calculator queried the database after preloading');
    }
}
