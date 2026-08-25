<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Payroll\AttendanceAggregator;
use App\Services\Payroll\PayrollPeriodResolver;
use App\Services\Payroll\PayslipCalculator;
use App\Services\Payroll\StatutoryDeductionCalculator;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatutorySeeder;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\PayrollTestCase;

/**
 * Two things that vary by person and by nothing else.
 *
 * Whether somebody clocks in, and whether they earn commission. Neither
 * follows department, position or workplace type: some remote staff punch and
 * some do not, and somebody in Admin may sell while somebody in Sales does not.
 *
 * Both were previously inferred — attendance from the fact that everybody
 * punched, commission from the department being named "Sales" — and both
 * inferences were wrong for real people on this payroll.
 */
class PerPersonSettingsTest extends PayrollTestCase
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

    #[Test]
    public function someone_who_does_not_clock_in_keeps_their_whole_salary(): void
    {
        // The bug this exists for: every scheduled day read as an absence, so
        // a PHP 30,000 salary paid PHP 1,363.64 for the cutoff.
        $employee = $this->makeEmployee(salary: 30000);
        $employee->update(['tracks_attendance' => false]);

        [$counters, $figures] = $this->payFor($employee->fresh());

        $this->assertSame(0, $counters['days_absent']);
        $this->assertSame(0.0, $figures['absence_deduction']);
        $this->assertSame(15000.0, $figures['net_pay']);
    }

    #[Test]
    public function someone_who_does_clock_in_is_still_marked_absent(): void
    {
        // The flag must not quietly excuse everybody.
        $employee = $this->makeEmployee(salary: 30000);

        [$counters, $figures] = $this->payFor($employee->fresh());

        $this->assertGreaterThan(0, $counters['days_absent']);
        $this->assertGreaterThan(0, $figures['absence_deduction']);
    }

    #[Test]
    public function everybody_clocks_in_unless_somebody_says_otherwise(): void
    {
        // Most staff punch, so the flag defaults on and nobody's pay changes
        // the day this shipped.
        $this->assertTrue((bool) Employee::factory()->create()->tracks_attendance);
    }

    #[Test]
    public function a_non_clocking_employee_still_loses_pay_for_unpaid_leave(): void
    {
        // Not tracking attendance is not a blanket excuse. Leave without pay is
        // a decision somebody made on purpose, not a missing punch.
        $employee = $this->makeEmployee(salary: 30000);
        $employee->update(['tracks_attendance' => false]);
        $employee->refresh();

        $period = $this->period();
        $days = $this->workingDays($employee, $period);

        $leaveType = \App\Models\LeaveType::first() ?? \App\Models\LeaveType::create([
            'name' => 'Vacation Leave',
            'code' => 'VL',
            'days_per_year' => 5,
            'is_active' => true,
        ]);

        \App\Models\LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $days[0],
            'end_date' => $days[0],
            'days_requested' => 1,
            'status' => 'approved',
            'is_lwop' => true,
            'reason' => 'Personal',
        ]);

        [$counters, $figures] = $this->payFor($employee->fresh());

        $this->assertSame(1, $counters['days_lwop']);
        $this->assertGreaterThan(0, $figures['absence_deduction']);
    }

    #[Test]
    public function a_non_clocking_employee_never_shows_as_late(): void
    {
        $employee = $this->makeEmployee(salary: 30000);
        $employee->update(['tracks_attendance' => false]);

        [$counters] = $this->payFor($employee->fresh());

        $this->assertSame(0, $counters['late_minutes']);
        $this->assertSame(0, $counters['undertime_minutes']);
    }

    #[Test]
    public function the_daily_rate_still_works_for_someone_who_never_punches(): void
    {
        // Their days are counted as worked rather than skipped, because the
        // daily rate is the cutoff's half salary divided by days_expected —
        // skipping would leave that at zero.
        $employee = $this->makeEmployee(salary: 30000);
        $employee->update(['tracks_attendance' => false]);

        [$counters, $figures] = $this->payFor($employee->fresh());

        $this->assertGreaterThan(0, $counters['days_expected']);
        $this->assertSame($counters['days_expected'], $counters['days_present']);
        $this->assertGreaterThan(0, $figures['daily_rate']);
    }

    #[Test]
    public function the_punch_clock_hides_itself_from_someone_who_does_not_use_it(): void
    {
        $employee = Employee::factory()->create(['tracks_attendance' => false]);
        $user = \App\Models\User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Employee');
        $employee->assignSchedule(WorkSchedule::factory()->create(), '2020-01-01');

        $this->actingAs($user)
            ->get('/attendance')
            ->assertOk()
            ->assertSee('You do not need to clock in')
            // The button itself, not the words — "Time In" is also a column
            // heading in the history table below.
            ->assertDontSee('wire:click="timeIn"', false);
    }

    #[Test]
    public function the_punch_clock_still_works_for_everybody_else(): void
    {
        $employee = Employee::factory()->create(['tracks_attendance' => true]);
        $user = \App\Models\User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Employee');
        $employee->assignSchedule(WorkSchedule::factory()->create(), '2020-01-01');

        $this->actingAs($user)
            ->get('/attendance')
            ->assertOk()
            ->assertSee('wire:click="timeIn"', false)
            ->assertDontSee('You do not need to clock in');
    }

    #[Test]
    public function earning_commission_does_not_depend_on_the_department(): void
    {
        // Somebody in Admin may sell. The old rule checked that the department
        // was literally named "Sales", so they could not be set up at all.
        $sales = \App\Models\Department::factory()->create(['name' => 'Sales']);
        $admin = \App\Models\Department::factory()->create(['name' => 'Admin']);

        $adminSeller = Employee::factory()->create([
            'department_id' => $admin->id,
            'commission_frequency' => 'monthly',
        ]);

        $salesNonSeller = Employee::factory()->create([
            'department_id' => $sales->id,
            'commission_frequency' => 'none',
        ]);

        $this->assertSame('monthly', $adminSeller->commission_frequency);
        $this->assertSame('none', $salesNonSeller->commission_frequency);

        // And a department rename cannot strip anybody's setup, because nothing
        // reads the name any more.
        $sales->update(['name' => 'Sales & Marketing']);

        $this->assertSame('monthly', $adminSeller->fresh()->commission_frequency);
    }

    #[Test]
    public function the_employee_forms_no_longer_read_the_department_name(): void
    {
        foreach (['create', 'edit'] as $form) {
            $source = file_get_contents(resource_path("views/components/employees/⚡{$form}.blade.php"));

            $this->assertStringNotContainsString("name === 'Sales'", $source,
                "{$form} still decides commission from the department name");
            $this->assertStringContainsString('earnsCommission', $source,
                "{$form} does not use the per-person check");
        }
    }
    #[Test]
    public function a_run_covers_only_the_people_marked_as_earning_commission(): void
    {
        $wanted = Employee::factory()->create(["commission_frequency" => "monthly"]);
        Employee::factory()->count(3)->create(["commission_frequency" => "none"]);

        $chosen = app(\App\Services\Commission\CommissionRunService::class)
            ->defaultAgentsFor("monthly");

        $this->assertSame([$wanted->id], $chosen->pluck("id")->all());
    }

    #[Test]
    public function a_run_refuses_to_open_when_nobody_earns_commission(): void
    {
        // This used to fall back to the whole roster, which meant setting every
        // employee to No produced a run containing every employee — the switch
        // did nothing at all.
        Employee::factory()->count(3)->create(["commission_frequency" => "none"]);

        $this->expectExceptionMessage("Nobody is set to earn commission");

        app(\App\Services\Commission\CommissionRunService::class)
            ->openRun("2026-07-01", "2026-07-31", "monthly");
    }

    #[Test]
    public function somebody_can_still_be_added_to_a_run_by_hand(): void
    {
        // The switch pre-selects; it does not forbid. Someone who sold once
        // can be put on a run without changing their profile.
        $occasional = Employee::factory()->create(["commission_frequency" => "none"]);

        $run = app(\App\Services\Commission\CommissionRunService::class)
            ->openRun("2026-07-01", "2026-07-31", "monthly", agentIds: [$occasional->id]);

        $this->assertSame([$occasional->id], $run->agents()->pluck("employees.id")->all());
    }
}