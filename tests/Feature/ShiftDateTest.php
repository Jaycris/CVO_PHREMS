<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Services\Attendance\ShiftDateResolver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which shift a punch belongs to, for staff whose shift crosses midnight.
 *
 * A 10 PM to 6 AM shift is one shift and belongs on the day it started. Filing
 * each punch under the calendar date it happened on locked night staff out:
 * somebody arriving at 1 AM, three hours late, had it recorded against the new
 * day, and their real shift at 10 PM that evening was then refused as already
 * completed. It repeated every day once they had slipped past midnight.
 *
 * The same mistake made them look punctual — 1 AM measured against a 10 PM
 * start on the same date is not late at all, it is twenty-one hours early.
 *
 * Day shifts must not move. That is asserted here as firmly as the fix itself,
 * because most of the company is on one.
 */
class ShiftDateTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftDateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->resolver = new ShiftDateResolver;
    }

    protected function employeeOn(string $start, string $end): Employee
    {
        $employee = Employee::factory()->create();

        $employee->assignSchedule(
            WorkSchedule::factory()->create(['start_time' => $start, 'end_time' => $end]),
            '2020-01-01',
        );

        return $employee->fresh();
    }

    protected function graveyard(): Employee
    {
        return $this->employeeOn('22:00', '06:00');
    }

    protected function dayShift(): Employee
    {
        return $this->employeeOn('09:00', '18:00');
    }

    #[Test]
    public function a_punch_after_midnight_belongs_to_the_shift_that_started_last_night(): void
    {
        // Three hours late for the 10 PM shift on the 25th.
        $this->assertSame(
            '2026-08-25',
            $this->resolver->forPunch($this->graveyard(), Carbon::parse('2026-08-26 01:00')),
        );
    }

    #[Test]
    public function a_punch_at_the_start_of_the_evening_belongs_to_tonight(): void
    {
        $this->assertSame(
            '2026-08-26',
            $this->resolver->forPunch($this->graveyard(), Carbon::parse('2026-08-26 22:05')),
        );
    }

    #[Test]
    public function coming_in_early_still_belongs_to_tonight(): void
    {
        // Nothing stops somebody punching in early, and people do — they come
        // to the office ahead of time and stay. An hour early is an hour from
        // tonight's start and twenty-three from last night's.
        $this->assertSame(
            '2026-08-26',
            $this->resolver->forPunch($this->graveyard(), Carbon::parse('2026-08-26 21:00')),
        );
    }

    #[Test]
    public function a_day_shift_never_moves(): void
    {
        $employee = $this->dayShift();

        foreach (['08:00', '09:00', '09:45', '13:00', '17:30'] as $time) {
            $this->assertSame(
                '2026-08-26',
                $this->resolver->forPunch($employee, Carbon::parse('2026-08-26 ' . $time)),
                "A day shift punch at {$time} was filed under the wrong date.",
            );
        }
    }

    #[Test]
    public function somebody_with_no_schedule_falls_back_to_the_calendar_date(): void
    {
        // Nothing to measure against, so the plain answer beats an invented one.
        $employee = Employee::factory()->create();

        $this->assertSame(
            '2026-08-26',
            $this->resolver->forPunch($employee, Carbon::parse('2026-08-26 01:00')),
        );
    }

    /**
     * The bug, end to end: the punch that used to be refused.
     */
    #[Test]
    public function a_late_night_arrival_does_not_lock_the_employee_out_of_that_evening(): void
    {
        $employee = $this->graveyard();

        // In at 1 AM, three hours late for the shift that began at 10 PM.
        $lateArrival = Carbon::parse('2026-08-26 01:00');
        $employee->attendanceDays()->create([
            'work_date' => $this->resolver->forPunch($employee, $lateArrival),
            'time_in' => $lateArrival,
        ]);

        // Out at 6 AM, closing that shift.
        $employee->attendanceDays()->latest('id')->first()
            ->update(['time_out' => Carbon::parse('2026-08-26 06:00')]);

        // In again at 10 PM the same evening. This is what used to be met with
        // "You have already completed your shift for today."
        $tonight = Carbon::parse('2026-08-26 22:00');
        $shiftDate = $this->resolver->forPunch($employee, $tonight);

        $this->assertSame('2026-08-26', $shiftDate);
        $this->assertFalse(
            $employee->attendanceDays()->where('work_date', $shiftDate)->whereNotNull('time_out')->exists(),
            'The evening shift is still blocked by the small hours of the same morning.',
        );
    }

    #[Test]
    public function lateness_is_measured_against_the_shift_that_was_actually_missed(): void
    {
        // The quiet half of the same bug: filed under the wrong date, 1 AM
        // against a 10 PM start is not late at all, so three hours late was
        // reported as nought minutes and deducted nothing.
        $employee = $this->graveyard();

        $arrival = Carbon::parse('2026-08-26 01:00');

        $day = $employee->attendanceDays()->create([
            'work_date' => $this->resolver->forPunch($employee, $arrival),
            'time_in' => $arrival,
        ]);

        $this->assertSame(180, $day->lateMinutes());
    }
}
