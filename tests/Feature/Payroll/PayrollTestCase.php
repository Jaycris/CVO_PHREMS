<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\PayrollSetting;
use App\Models\StatutoryContributionSetting;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Payroll\PayrollPeriodResolver;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatutorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Shared ground for the payroll tests.
 *
 * The setup here is what makes the rest short: a seeded rate table, a helper to
 * make an employee with a schedule, and one to fill a period with attendance.
 * Without it every test would spend twenty lines building a world before it
 * could assert anything, and tests that cost that much do not get written.
 */
abstract class PayrollTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected PayrollPeriodResolver $periods;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(StatutorySeeder::class);

        $this->periods = new PayrollPeriodResolver();

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    /**
     * Turns the government deductions on. They are seeded off, matching the
     * company's current setup, so statutory tests have to ask for them.
     */
    protected function enableStatutory(string ...$codes): void
    {
        StatutoryContributionSetting::whereIn('code', $codes ?: ['sss', 'philhealth', 'pagibig', 'bir'])
            ->update(['is_active' => true]);
    }

    protected function setPayrollSetting(string $key, string $value): void
    {
        PayrollSetting::where('key', $key)->update(['value' => $value]);
        PayrollSetting::flushCache();
    }

    /** An employee with a schedule already assigned from well before any test period. */
    protected function makeEmployee(float $salary = 20000, string $schedule = 'day', array $attributes = []): Employee
    {
        $employee = Employee::factory()->salary($salary)->create($attributes);

        $employee->assignSchedule($this->schedule($schedule), '2020-01-01');

        return $employee->fresh();
    }

    protected function schedule(string $kind = 'day'): WorkSchedule
    {
        return $kind === 'graveyard'
            ? WorkSchedule::factory()->graveyard()->create()
            : WorkSchedule::factory()->create();
    }

    /**
     * Fills every scheduled working day in a period with a full, punctual day.
     *
     * @param  list<string>  $absentOn  dates to leave with no attendance row at all
     * @return list<string> the dates that were given attendance
     */
    protected function fillAttendance(Employee $employee, array $period, array $absentOn = []): array
    {
        $schedule = $employee->scheduleAssignmentForDate($period['start'])->workSchedule;
        $filled = [];

        foreach ($this->periods->datesIn($period['start'], $period['end']) as $date) {
            if (! $schedule->isWorkDay(Carbon::parse($date))) {
                continue;
            }

            if ($employee->hire_date && $date < $employee->hire_date->toDateString()) {
                continue;
            }

            if (in_array($date, $absentOn, true)) {
                continue;
            }

            $in = Carbon::parse($date . ' ' . $schedule->start_time->format('H:i:s'));
            $out = Carbon::parse($date . ' ' . $schedule->end_time->format('H:i:s'));

            if ($schedule->crossesMidnight()) {
                $out->addDay();
            }

            AttendanceDay::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'time_in' => $in,
                'time_out' => $out,
            ]);

            $filled[] = $date;
        }

        return $filled;
    }

    /** The scheduled working days in a period, without creating anything. */
    protected function workingDays(Employee $employee, array $period): array
    {
        $schedule = $employee->scheduleAssignmentForDate($period['start'])->workSchedule;

        return collect($this->periods->datesIn($period['start'], $period['end']))
            ->filter(fn ($d) => $schedule->isWorkDay(Carbon::parse($d)))
            ->filter(fn ($d) => ! $employee->hire_date || $d >= $employee->hire_date->toDateString())
            ->values()
            ->all();
    }

    /** @return array{cutoff: string, start: Carbon, end: Carbon, pay_date: Carbon} */
    protected function period(int $year = 2026, int $month = 8, string $cutoff = 'second'): array
    {
        return $this->periods->payDateFor($year, $month, $cutoff);
    }
}
