<?php

namespace Tests\Feature;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Recording a day nobody clocked.
 *
 * Three people in one fortnight worked days PHREMS had no record of — two
 * started before their account existed, one came back from termination. Each
 * one was a console job, because the DTR could correct a day that existed but
 * had no way to add one that did not. The only thing on screen was marking
 * them off-site, which pays the day but says something untrue on a payslip and
 * quietly drops the night differential.
 */
class MissingAttendanceDayTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->hr = User::factory()->create();
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo('attendance.manage');

        $schedule = WorkSchedule::factory()->create([
            'start_time' => '22:00',
            'end_time' => '06:00',
            'work_days' => [1, 2, 3, 4, 5],
        ]);

        $this->employee = Employee::factory()->create([
            'first_name' => 'Christian Joy',
            'last_name' => 'Villardo',
            'hire_date' => '2026-10-19',
        ]);
        $this->employee->assignSchedule($schedule, '2026-10-01');
        $this->employee->refresh();
    }

    protected function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($this->hr)->test('attendance.dtr');
    }

    #[Test]
    public function a_day_that_was_never_punched_can_be_recorded(): void
    {
        $this->assertSame(0, AttendanceDay::count());

        $this->form()
            ->call('addMissingDay')
            ->set('editEmployeeId', (string) $this->employee->id)
            ->set('editDate', '2026-10-19')
            ->set('editTimeIn', '22:00')
            ->set('editTimeOut', '06:00')
            ->set('editReason', 'Worked before his PHREMS account existed')
            ->call('save')
            ->assertHasNoErrors();

        $day = AttendanceDay::sole();

        $this->assertSame('2026-10-19', $day->work_date->toDateString());
        $this->assertSame($this->employee->id, $day->employee_id);
        // The shift crosses midnight, so the time out belongs to the 20th.
        // Landing it on the 19th is what turned a full night into twelve
        // hours of undertime the last time this went wrong.
        $this->assertSame('2026-10-20 06:00:00', $day->time_out->toDateTimeString());
        $this->assertSame(0, $day->undertimeMinutes());
        $this->assertSame(0, $day->lateMinutes());
    }

    #[Test]
    public function it_leaves_a_correction_on_the_record_like_any_other_change(): void
    {
        $this->form()
            ->call('addMissingDay')
            ->set('editEmployeeId', (string) $this->employee->id)
            ->set('editDate', '2026-10-19')
            ->set('editTimeIn', '22:00')
            ->set('editTimeOut', '06:00')
            ->set('editReason', 'No account until Oct 21')
            ->call('save')
            ->assertHasNoErrors();

        $correction = AttendanceCorrection::sole();

        // Added by hand, with a name and a reason against it. A day that
        // appears from nowhere with no trail is worse than no day at all.
        $this->assertSame($this->hr->id, $correction->user_id);
        $this->assertSame('No account until Oct 21', $correction->reason);
        $this->assertNull($correction->before['time_in']);
        $this->assertSame('2026-10-19 22:00:00', $correction->after['time_in']);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->form()
            ->call('addMissingDay')
            ->set('editEmployeeId', (string) $this->employee->id)
            ->set('editDate', '2026-10-19')
            ->set('editTimeIn', '22:00')
            ->call('save')
            ->assertHasErrors('editReason');

        $this->assertSame(0, AttendanceDay::count());
    }

    #[Test]
    public function the_employee_and_the_date_are_both_required(): void
    {
        $this->form()
            ->call('addMissingDay')
            ->set('editTimeIn', '22:00')
            ->set('editReason', 'Worked but could not punch')
            ->call('save')
            ->assertHasErrors(['editEmployeeId', 'editDate']);

        $this->assertSame(0, AttendanceDay::count());
    }

    #[Test]
    public function a_day_inside_a_settled_payroll_is_refused(): void
    {
        /*
         * The whole reason Christian Joy's correction had to go on the next
         * payroll as an adjustment. A payslip somebody is holding cannot
         * quietly stop matching the attendance behind it.
         */
        PayrollRun::create([
            'run_type' => 'regular',
            'cutoff' => 'second',
            'period_start' => '2026-10-11',
            'period_end' => '2026-10-25',
            'pay_date' => '2026-10-30',
            'status' => 'paid',
        ]);

        $this->form()
            ->call('addMissingDay')
            ->set('editEmployeeId', (string) $this->employee->id)
            ->set('editDate', '2026-10-19')
            ->set('editTimeIn', '22:00')
            ->set('editTimeOut', '06:00')
            ->set('editReason', 'Worked before his account existed')
            ->call('save')
            ->assertHasErrors('editDate');

        $this->assertSame(0, AttendanceDay::count());
    }

    #[Test]
    public function somebody_without_the_permission_cannot_record_one(): void
    {
        $plain = User::factory()->create();
        $plain->assignRole('Admin');

        Livewire::actingAs($plain)->test('attendance.dtr')
            ->assertDontSee('Record a missing day')
            ->call('addMissingDay')
            ->assertStatus(403);
    }

    #[Test]
    public function recording_a_day_that_already_exists_updates_it_rather_than_doubling_it(): void
    {
        AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-10-19',
            'time_in' => '2026-10-19 22:30:00',
        ]);

        $this->form()
            ->call('addMissingDay')
            ->set('editEmployeeId', (string) $this->employee->id)
            ->set('editDate', '2026-10-19')
            ->set('editTimeIn', '22:00')
            ->set('editTimeOut', '06:00')
            ->set('editReason', 'Punch clock was unreachable')
            ->call('save')
            ->assertHasNoErrors();

        // One row per employee per day, always. Two would double the day in
        // every count payroll makes.
        $this->assertSame(1, AttendanceDay::count());
        $this->assertSame('2026-10-19 22:00:00', AttendanceDay::sole()->time_in->toDateTimeString());
    }
}
