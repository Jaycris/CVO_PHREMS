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

        $this->accidentalPunch();

        $this->actingAs($viewer)
            ->get('/dtr')
            ->assertOk()
            ->assertDontSee('wire:click="edit(', false);
    }

    #[Test]
    public function an_administrator_who_may_correct_sees_the_button(): void
    {
        $this->accidentalPunch();

        $this->actingAs($this->admin)
            ->get('/dtr')
            ->assertOk()
            ->assertSee('wire:click="edit(', false);
    }
}
