<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Payroll\AttendanceAggregator;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\StatutoryDeductionCalculator;
use PHPUnit\Framework\Attributes\Test;

/**
 * Holidays, and the day's pay they used to cost.
 *
 * Before the holiday list existed, a scheduled workday with no punch-in was an
 * absence and nothing else. That meant every employee who stayed home on
 * Christmas Day silently lost a day's salary, and the payslip gave no hint —
 * it just said one absence. These tests exist so that cannot come back.
 */
class HolidayTest extends PayrollTestCase
{
    protected function aggregate(Employee $employee, array $period): array
    {
        return (new AttendanceAggregator)
            ->aggregate(collect([$employee]), $period['start'], $period['end'])[$employee->id];
    }

    /** A date inside the period that the employee is scheduled to work. */
    protected function workday(Employee $employee, array $period, int $index = 2): string
    {
        return $this->workingDays($employee, $period)[$index];
    }

    #[Test]
    public function a_regular_holiday_not_worked_is_not_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $holidayOn = $this->workday($employee, $period);

        Holiday::create([
            'date' => $holidayOn,
            'name' => 'Test Regular Holiday',
            'type' => Holiday::REGULAR,
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$holidayOn]);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(0, $counters['days_absent'], 'a regular holiday was counted as an absence');
        $this->assertSame(1, $counters['days_holiday']);
        $this->assertSame(0, $counters['days_holiday_worked']);
    }

    #[Test]
    public function a_special_non_working_day_not_worked_is_not_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $holidayOn = $this->workday($employee, $period);

        Holiday::create([
            'date' => $holidayOn,
            'name' => 'Test Special Day',
            'type' => Holiday::SPECIAL_NON_WORKING,
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$holidayOn]);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(0, $counters['days_absent']);
        $this->assertSame(1, $counters['days_holiday']);
    }

    #[Test]
    public function a_special_working_day_is_an_ordinary_day_and_missing_it_is_still_an_absence(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $holidayOn = $this->workday($employee, $period);

        // The government declaring a "special working day" is the government
        // saying come to work. Treating it as a day off would be the one way
        // this feature could start paying people for days they owe.
        Holiday::create([
            'date' => $holidayOn,
            'name' => 'Test Special Working Day',
            'type' => Holiday::SPECIAL_WORKING,
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$holidayOn]);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(1, $counters['days_absent']);
        $this->assertSame(0, $counters['days_holiday']);
    }

    #[Test]
    public function working_a_holiday_counts_as_present_and_as_a_worked_holiday(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $holidayOn = $this->workday($employee, $period);

        Holiday::create([
            'date' => $holidayOn,
            'name' => 'Test Regular Holiday',
            'type' => Holiday::REGULAR,
        ]);

        $expected = $this->fillAttendance($employee, $period);

        $counters = $this->aggregate($employee, $period);

        $this->assertSame(count($expected), $counters['days_present']);
        $this->assertSame(0, $counters['days_absent']);
        $this->assertSame(1, $counters['days_holiday']);
        $this->assertSame(1, $counters['days_holiday_worked']);
    }

    #[Test]
    public function a_holiday_falling_on_a_rest_day_counts_for_nothing(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();

        // A Sunday inside the 11-25 August cutoff.
        Holiday::create([
            'date' => '2026-08-16',
            'name' => 'Test Sunday Holiday',
            'type' => Holiday::REGULAR,
        ]);

        $this->fillAttendance($employee, $period);

        $counters = $this->aggregate($employee, $period);

        // A monthly-paid employee gains nothing from a holiday on a day they
        // were never going to work, and loses nothing either.
        $this->assertSame(0, $counters['days_holiday']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function a_holiday_costs_no_absence_deduction(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee(salary: 20000);
        $holidayOn = $this->workday($employee, $period);

        Holiday::create([
            'date' => $holidayOn,
            'name' => 'Test Regular Holiday',
            'type' => Holiday::REGULAR,
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$holidayOn]);

        $counters = $this->aggregate($employee, $period);
        $calculator = new PayslipCalculator(
            (new StatutoryDeductionCalculator)->preload($period['pay_date'])
        );
        $figures = $calculator->calculate($employee, $counters, $period['cutoff']);

        // The point of the whole feature, stated in pesos.
        $this->assertSame(0.0, $figures['absence_deduction']);
        $this->assertSame(10000.0, $figures['basic_earned']);
    }

    #[Test]
    public function leave_already_filed_on_a_holiday_stays_leave(): void
    {
        $period = $this->period();
        $employee = $this->makeEmployee();
        $holidayOn = $this->workday($employee, $period);

        Holiday::create([
            'date' => $holidayOn,
            'name' => 'Test Regular Holiday',
            'type' => Holiday::REGULAR,
        ]);

        $leaveType = LeaveType::first() ?? LeaveType::create([
            'name' => 'Vacation Leave',
            'code' => 'VL',
            'days_per_year' => 5,
            'is_active' => true,
        ]);

        LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $holidayOn,
            'end_date' => $holidayOn,
            'days_requested' => 1,
            'status' => 'approved',
            'is_lwop' => false,
            'reason' => 'Booked before the proclamation',
        ]);

        $this->fillAttendance($employee, $period, absentOn: [$holidayOn]);

        $counters = $this->aggregate($employee, $period);

        // Both pay the same, so the payslip is identical either way. The leave
        // credit was already spent when the request was approved; refunding it
        // belongs to the leave module, not to a read-only aggregator.
        $this->assertSame(1, $counters['days_on_paid_leave']);
        $this->assertSame(0, $counters['days_absent']);
    }

    #[Test]
    public function only_pay_protected_holidays_are_handed_to_payroll(): void
    {
        Holiday::create(['date' => '2026-08-12', 'name' => 'Regular', 'type' => Holiday::REGULAR]);
        Holiday::create(['date' => '2026-08-13', 'name' => 'Special', 'type' => Holiday::SPECIAL_NON_WORKING]);
        Holiday::create(['date' => '2026-08-14', 'name' => 'Working', 'type' => Holiday::SPECIAL_WORKING]);

        $map = Holiday::payProtectedBetween(
            \Illuminate\Support\Carbon::parse('2026-08-11'),
            \Illuminate\Support\Carbon::parse('2026-08-25'),
        );

        $this->assertArrayHasKey('2026-08-12', $map);
        $this->assertArrayHasKey('2026-08-13', $map);
        $this->assertArrayNotHasKey('2026-08-14', $map);
    }

    #[Test]
    public function the_years_list_only_offers_years_that_have_holidays(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => Holiday::REGULAR]);
        Holiday::create(['date' => '2027-01-01', 'name' => 'New Year\'s Day', 'type' => Holiday::REGULAR]);

        $this->assertSame([2027, 2026], Holiday::years());
    }

    #[Test]
    public function the_holidays_page_loads_and_lists_the_year(): void
    {
        Holiday::create(['date' => '2026-12-25', 'name' => 'Christmas Day', 'type' => Holiday::REGULAR]);

        $this->get('/holidays')
            ->assertOk()
            ->assertSee('Christmas Day');
    }

    #[Test]
    public function an_employee_without_the_permission_cannot_open_the_holidays_page(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($employee->user ?? \App\Models\User::factory()->create())
            ->get('/holidays')
            ->assertForbidden();
    }
}
