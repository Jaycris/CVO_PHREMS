<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Attendance\AttendanceCorrectionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use App\Models\AttendanceBreak;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR correcting a day that was clocked wrongly.
 *
 * The case this exists for: somebody tapped Time In and Time Out within a
 * minute of each other at 5:33 AM. One row exists per employee per day and the
 * punch clock refuses to reopen a day that already has a time out, so they
 * could not clock their real shift and the day would have paid as zero hours.
 *
 * Two things have to hold. Clearing the time out must genuinely let them punch
 * again, and a day inside a payroll run that has already been paid must not be
 * editable at all — a payslip somebody is holding cannot quietly stop matching
 * the system it came from.
 */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected AttendanceCorrectionService $corrections;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->corrections = new AttendanceCorrectionService;

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');

        $this->employee = Employee::factory()->create();
    }

    protected function accidentalPunch(string $date = '2026-08-26'): AttendanceDay
    {
        return AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $date . ' 05:33:00',
            'time_out' => $date . ' 05:34:00',
        ]);
    }

    #[Test]
    public function an_overlong_break_can_be_corrected(): void
    {
        /*
         * Somebody who forgot to end a break shows four hours of it and loses
         * the worked time to match — 8 hours in the office paying as 4.2. The
         * total is the number on the screen and the number that is wrong, so
         * that is what HR sets.
         */
        $day = AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-26',
            'time_in' => '2026-08-26 21:53:00',
            'time_out' => '2026-08-27 06:00:00',
        ]);

        AttendanceBreak::create([
            'attendance_day_id' => $day->id,
            'break_start' => '2026-08-26 23:00:00',
            'break_end' => '2026-08-27 02:57:00',
        ]);

        $this->assertSame(237, $day->fresh()->totalBreakMinutes());

        /*
         * Typed as the times HR was told — "she went at eleven and came back at
         * midnight" — not as a total. Both are after the 21:53 start on a night
         * shift, so both belong to the same calendar day.
         */
        $this->corrections->apply(
            $this->employee, '2026-08-26', '21:53', '06:00',
            'Break was an hour; the end was never tapped', $this->admin,
            breaks: [['start' => '23:00', 'end' => '00:00']],
        );

        $this->assertSame(60, $day->fresh()->totalBreakMinutes());
    }

    #[Test]
    public function a_break_after_midnight_belongs_to_the_following_morning(): void
    {
        /*
         * The night shift trap. A break at 01:00 on a shift that began at 21:53
         * is four hours in, not twenty hours before the employee arrived —
         * which would price the break as negative and the day as far longer
         * than anybody worked.
         */
        $day = AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-26',
            'time_in' => '2026-08-26 21:53:00',
            'time_out' => '2026-08-27 06:00:00',
        ]);

        $this->corrections->apply(
            $this->employee, '2026-08-26', '21:53', '06:00',
            'Break times from the supervisor', $this->admin,
            breaks: [['start' => '01:00', 'end' => '02:00']],
        );

        $break = $day->fresh()->breaks->sole();

        $this->assertSame('2026-08-27 01:00:00', $break->break_start->toDateTimeString());
        $this->assertSame(60, $day->fresh()->totalBreakMinutes());
    }

    #[Test]
    public function a_lunch_and_a_coffee_break_both_survive(): void
    {
        // The schedules carry both, so collapsing a day to one stretch would
        // hand the employee back time they did not work.
        $day = AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-26',
            'time_in' => '2026-08-26 21:00:00',
            'time_out' => '2026-08-27 06:00:00',
        ]);

        $this->corrections->apply(
            $this->employee, '2026-08-26', '21:00', '06:00',
            'Both breaks logged late', $this->admin,
            breaks: [
                ['start' => '23:00', 'end' => '00:00'],
                ['start' => '03:00', 'end' => '03:15'],
            ],
        );

        $this->assertSame(2, $day->fresh()->breaks->count());
        $this->assertSame(75, $day->fresh()->totalBreakMinutes());
    }

    #[Test]
    public function correcting_a_break_is_recorded_with_what_it_was(): void
    {
        // Attendance decides pay, so an untraceable edit is a dispute waiting
        // to happen — the old figure has to survive the correction.
        $day = $this->accidentalPunch();

        AttendanceBreak::create([
            'attendance_day_id' => $day->id,
            'break_start' => '2026-08-26 05:33:00',
            'break_end' => '2026-08-26 07:33:00',
        ]);

        // An empty list is how "the break was never taken" is expressed.
        $this->corrections->apply(
            $this->employee, '2026-08-26', '21:00', null,
            'Break logged against the wrong day', $this->admin,
            breaks: [],
        );

        $correction = AttendanceCorrection::latest('id')->first();

        $this->assertSame(120, $correction->before['break_minutes']);
        $this->assertSame([['start' => '05:33', 'end' => '07:33']], $correction->before['breaks']);
        $this->assertSame(0, $correction->after['break_minutes']);
        $this->assertSame(0, $day->fresh()->totalBreakMinutes());
    }

    #[Test]
    public function leaving_the_break_alone_does_not_touch_it(): void
    {
        // Fixing a time out must not silently wipe the day's breaks.
        $day = $this->accidentalPunch();

        AttendanceBreak::create([
            'attendance_day_id' => $day->id,
            'break_start' => '2026-08-26 05:33:00',
            'break_end' => '2026-08-26 06:33:00',
        ]);

        $this->corrections->apply(
            $this->employee, '2026-08-26', '21:00', null,
            'Punched out by mistake', $this->admin,
        );

        $this->assertSame(60, $day->fresh()->totalBreakMinutes());
    }

    #[Test]
    public function a_break_cannot_be_changed_once_the_payroll_is_paid(): void
    {
        $day = $this->accidentalPunch();

        PayrollRun::create([
            'run_type' => 'regular',
            'cutoff' => 'second',
            'period_start' => '2026-08-11',
            'period_end' => '2026-08-25',
            'pay_date' => '2026-08-30',
            'status' => 'paid',
        ]);

        PayrollRun::create([
            'run_type' => 'regular',
            'cutoff' => 'first',
            'period_start' => '2026-08-26',
            'period_end' => '2026-09-10',
            'pay_date' => '2026-09-15',
            'status' => 'paid',
        ]);

        $this->expectException(ValidationException::class);

        $this->corrections->apply(
            $this->employee, '2026-08-26', '21:00', '06:00',
            'Too late', $this->admin, breaks: [],
        );
    }

    #[Test]
    public function clearing_the_time_out_reopens_the_day(): void
    {
        $day = $this->accidentalPunch();

        $this->corrections->apply($this->employee, '2026-08-26', '21:00', null, 'Punched out by mistake', $this->admin);

        $day->refresh();

        $this->assertNull($day->time_out);
        $this->assertSame('21:00', $day->time_in->format('H:i'));

        // This is the exact condition the punch clock refuses on.
        $this->assertFalse(
            $this->employee->attendanceDays()->where('work_date', '2026-08-26')->whereNotNull('time_out')->exists(),
            'The day is still closed, so the employee still cannot punch.'
        );
    }

    #[Test]
    public function the_previous_values_are_kept(): void
    {
        $this->accidentalPunch();

        $this->corrections->apply($this->employee, '2026-08-26', '21:00', null, 'Punched out by mistake', $this->admin);

        $correction = AttendanceCorrection::sole();

        $this->assertSame('2026-08-26 05:33:00', $correction->before['time_in']);
        $this->assertSame('2026-08-26 05:34:00', $correction->before['time_out']);
        $this->assertSame('2026-08-26 21:00:00', $correction->after['time_in']);
        $this->assertNull($correction->after['time_out']);
        $this->assertSame($this->admin->id, $correction->user_id);
        $this->assertSame('Punched out by mistake', $correction->reason);
    }

    #[Test]
    public function a_change_that_changes_nothing_is_not_recorded(): void
    {
        // Otherwise an HR user who opens the form and saves without touching it
        // buries the real corrections in noise.
        $this->accidentalPunch();

        $this->corrections->apply($this->employee, '2026-08-26', '05:33', '05:34', 'No change', $this->admin);

        $this->assertSame(0, AttendanceCorrection::count());
    }

    #[Test]
    public function a_day_with_no_record_at_all_can_be_filled_in(): void
    {
        // Somebody who forgot to punch entirely still has to be paid for the
        // day they worked.
        $this->corrections->apply($this->employee, '2026-08-26', '09:00', '18:00', 'Forgot to clock in', $this->admin);

        $day = AttendanceDay::sole();

        $this->assertSame($this->employee->id, $day->employee_id);
        $this->assertSame('09:00', $day->time_in->format('H:i'));
        $this->assertSame('18:00', $day->time_out->format('H:i'));
    }

    #[Test]
    public function a_shift_ending_before_it_starts_runs_past_midnight(): void
    {
        // A graveyard shift: in at 10 PM, out at 6 AM the following morning.
        // Read literally this is a negative day, which would price as zero.
        $this->corrections->apply($this->employee, '2026-08-26', '22:00', '06:00', 'Night shift', $this->admin);

        $day = AttendanceDay::sole();

        $this->assertSame('2026-08-27 06:00:00', $day->time_out->toDateTimeString());
        $this->assertSame(480, $day->totalWorkedMinutes());
    }

    #[Test]
    public function a_date_inside_a_paid_payroll_run_is_refused(): void
    {
        $this->accidentalPunch();

        PayrollRun::create([
            'run_type' => 'regular',
            'cutoff' => 'second',
            'period_start' => '2026-08-11',
            'period_end' => '2026-08-25',
            'pay_date' => '2026-08-30',
            'status' => 'paid',
        ]);

        $this->expectException(ValidationException::class);

        $this->corrections->apply($this->employee, '2026-08-25', '09:00', '18:00', 'Too late', $this->admin);
    }

    #[Test]
    public function a_date_inside_a_draft_run_is_still_editable(): void
    {
        // A draft has not paid anybody. Locking it would stop HR fixing
        // attendance in the very window they are preparing.
        PayrollRun::create([
            'run_type' => 'regular',
            'cutoff' => 'second',
            'period_start' => '2026-08-11',
            'period_end' => '2026-08-25',
            'pay_date' => '2026-08-30',
            'status' => 'draft',
        ]);

        $this->corrections->apply($this->employee, '2026-08-25', '09:00', '18:00', 'Still open', $this->admin);

        $this->assertSame(1, AttendanceDay::count());
    }

    #[Test]
    public function a_time_out_with_no_time_in_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->corrections->apply($this->employee, '2026-08-26', null, '18:00', 'Nonsense', $this->admin);
    }

    #[Test]
    public function reopen_keeps_the_time_in_and_only_clears_the_time_out(): void
    {
        $day = $this->accidentalPunch();

        $this->corrections->reopen($day, 'Reopened for the real shift', $this->admin);

        $day->refresh();

        $this->assertSame('05:33', $day->time_in->format('H:i'));
        $this->assertNull($day->time_out);
    }

    #[Test]
    public function the_dtr_page_hides_correcting_from_someone_who_may_only_view(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Admin');
        $viewer->givePermissionTo('attendance.view_all');

        // Dated today, because the DTR opens on the current month. A fixed date
        // in August passed until September arrived and the filter moved on.
        $this->accidentalPunch(now()->toDateString());

        $this->actingAs($viewer)
            ->get('/dtr')
            ->assertOk()
            ->assertDontSee('wire:click="edit(', false);
    }

    #[Test]
    public function an_administrator_who_may_correct_sees_the_button(): void
    {
        // Today's date for the same reason as above: the DTR filters to the
        // current month, so a hard-coded one silently stops being shown.
        $this->accidentalPunch(now()->toDateString());

        $this->actingAs($this->admin)
            ->get('/dtr')
            ->assertOk()
            ->assertSee('wire:click="edit(', false);
    }
}
