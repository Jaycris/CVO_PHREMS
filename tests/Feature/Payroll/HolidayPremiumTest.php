<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Holiday;
use App\Services\Payroll\AttendanceAggregator;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\StatutoryDeductionCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The extra earned for turning up on a holiday.
 *
 * The company was already paying this by hand. Their August sheet carries an
 * "Adjustment 30%" column of PHP 272.73 against every 20,000 salary, which is
 * one day's pay at 20,000 over 22, times three tenths — the premium for working
 * Ninoy Aquino Day, a special non-working holiday, on 21 August.
 *
 * Only the extra is paid here. A monthly employee is already being paid for the
 * day inside their fixed half, so a regular holiday worked adds one more day to
 * reach the double the Labor Code calls for, not two.
 *
 * Everything keys off the holiday list somebody typed in. The company follows
 * some Philippine holidays and some American ones, so a date nobody entered is
 * an ordinary working day however national the calendar says it is.
 */
class HolidayPremiumTest extends PayrollTestCase
{
    protected function payFor(Employee $employee): array
    {
        $period = $this->period();

        $counters = (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];

        $figures = (new PayslipCalculator(
            (new StatutoryDeductionCalculator)->preload($period['pay_date'])
        ))->calculate($employee, $counters, $period['cutoff']);

        return [$counters, $figures];
    }

    /** Puts a holiday on a day the employee works, and has them work it. */
    protected function workedHoliday(Employee $employee, string $type, string $observance = Holiday::PHILIPPINES, ?int $premium = null): array
    {
        $period = $this->period();
        $on = $this->workingDays($employee, $period)[2];

        Holiday::create([
            'date' => $on,
            'name' => 'Test Holiday',
            'type' => $type,
            'observance' => $observance,
            'worked_premium_percent' => $premium ?? Holiday::defaultPremiumFor($type),
        ]);

        // Present every scheduled day, the holiday included.
        $this->fillAttendance($employee, $period);

        return $this->payFor($employee);
    }

    #[Test]
    public function a_special_non_working_day_worked_pays_the_companys_272_73(): void
    {
        // Straight off their August sheet: 20,000 salary, one special
        // non-working holiday worked, Adjustment 30% of 272.73.
        $employee = $this->makeEmployee(salary: 20000);

        [$counters, $figures] = $this->workedHoliday($employee, Holiday::SPECIAL_NON_WORKING);

        $this->assertSame(1, $counters['days_holiday_worked']);
        $this->assertSame(272.73, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function the_premium_follows_the_salary(): void
    {
        // Marindoque, Niño on the same sheet: 23,000 salary, 313.64.
        $employee = $this->makeEmployee(salary: 23000);

        [, $figures] = $this->workedHoliday($employee, Holiday::SPECIAL_NON_WORKING);

        $this->assertSame(313.64, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function a_regular_holiday_worked_pays_a_whole_extra_day(): void
    {
        // National Heroes Day. Double pay means the day again, not twice again:
        // the first half of it is already inside the fixed monthly half.
        $employee = $this->makeEmployee(salary: 20000);

        [, $figures] = $this->workedHoliday($employee, Holiday::REGULAR);

        $this->assertSame(909.09, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function a_holiday_nobody_worked_pays_no_premium(): void
    {
        // Staying home on a holiday keeps the day's pay, which the holiday list
        // already handles. It does not earn the premium on top.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $on = $this->workingDays($employee, $period)[2];

        Holiday::create([
            'date' => $on,
            'name' => 'Test Holiday',
            'type' => Holiday::REGULAR,
            'observance' => Holiday::PHILIPPINES,
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$on]);

        [$counters, $figures] = $this->payFor($employee);

        $this->assertSame(0, $counters['days_holiday_worked']);
        $this->assertSame(0.0, $figures['holiday_premium_pay']);
        $this->assertSame(0.0, $figures['absence_deduction']);
    }

    #[Test]
    public function a_day_nobody_put_on_the_list_pays_nothing_extra(): void
    {
        // The rule the user was clear about: the company follows some Philippine
        // holidays and some American ones. A national holiday they work straight
        // through, and never entered, is an ordinary day.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);

        $this->fillAttendance($employee, $period);

        [, $figures] = $this->payFor($employee);

        $this->assertSame(0.0, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function a_us_day_off_worked_pays_nothing_extra_by_default(): void
    {
        // Not a Labor Code category, so there is no legal rate to suggest.
        $employee = $this->makeEmployee(salary: 20000);

        [$counters, $figures] = $this->workedHoliday(
            $employee,
            Holiday::PAID_DAY_OFF,
            Holiday::UNITED_STATES,
        );

        $this->assertSame(1, $counters['days_holiday_worked']);
        $this->assertSame(0.0, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function a_us_day_off_can_be_set_to_double_pay(): void
    {
        /*
         * The reason the rate lives on the holiday. Christmas Day is on this
         * company's list as an American paid day off, and it is also a
         * Philippine regular holiday — so anyone working it is owed double.
         * A rule reading the Labor Code type would have paid nothing.
         */
        $employee = $this->makeEmployee(salary: 20000);

        [, $figures] = $this->workedHoliday(
            $employee,
            Holiday::PAID_DAY_OFF,
            Holiday::UNITED_STATES,
            premium: 100,
        );

        $this->assertSame(909.09, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function two_holidays_at_different_rates_add_up(): void
    {
        // A cutoff can hold both. 30% of a day plus a whole one.
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $days = $this->workingDays($employee, $period);

        Holiday::create([
            'date' => $days[2], 'name' => 'Special Day', 'observance' => Holiday::PHILIPPINES,
            'type' => Holiday::SPECIAL_NON_WORKING, 'worked_premium_percent' => 30,
        ]);
        Holiday::create([
            'date' => $days[3], 'name' => 'Regular Day', 'observance' => Holiday::PHILIPPINES,
            'type' => Holiday::REGULAR, 'worked_premium_percent' => 100,
        ]);

        $this->fillAttendance($employee, $period);

        [$counters, $figures] = $this->payFor($employee);

        $this->assertSame(2, $counters['days_holiday_worked']);
        $this->assertSame(1181.82, $figures['holiday_premium_pay']);
    }

    #[Test]
    public function the_premium_reaches_gross_pay_and_is_taxable(): void
    {
        // Not a de minimis benefit — it is ordinary earnings, like overtime.
        $employee = $this->makeEmployee(salary: 20000);

        [, $figures] = $this->workedHoliday($employee, Holiday::REGULAR);

        $this->assertSame(
            round($figures['basic_earned'] + $figures['overtime_pay']
                + $figures['night_differential_pay'] + $figures['holiday_premium_pay']
                + $figures['allowance'], 2),
            $figures['gross_pay'],
        );

        $this->assertGreaterThanOrEqual($figures['holiday_premium_pay'], $figures['taxable_income']);
    }
}
