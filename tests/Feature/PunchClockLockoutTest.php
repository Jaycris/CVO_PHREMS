<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Forgetting to clock out must not lock somebody out for good.
 *
 * The punch clock refused a time in whenever any day anywhere was left open,
 * and a forgotten time out is ordinary — clock in at 2:39 AM, go home without
 * clocking out, and every punch from then on is met with "You are already timed
 * in". Forever, because the only thing that closed a day was the button the
 * person could no longer reach.
 *
 * The stale day is left open on purpose. Inventing an end time would put hours
 * nobody worked into somebody's pay, so it stays on the DTR for HR to correct.
 */
class PunchClockLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Employee');

        $this->employee = Employee::factory()->create([
            'user_id' => $user->id,
            'tracks_attendance' => true,
        ]);

        $this->employee->assignSchedule(
            WorkSchedule::factory()->create(['start_time' => '22:00', 'end_time' => '06:00']),
            '2020-01-01',
        );

        $this->actingAs($user);
    }

    protected function clock(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test('attendance.punch-clock');
    }

    #[Test]
    public function a_forgotten_time_out_does_not_block_the_next_shift(): void
    {
        // Clocked in for the shift that began on the 26th, never clocked out.
        AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-26',
            'time_in' => '2026-08-27 02:39:00',
            'time_out' => null,
        ]);

        // Arriving for the next night's shift.
        $this->travelTo(Carbon::parse('2026-08-27 21:52:00'));

        $this->clock()->call('timeIn')->assertSet('errorMessage', null);

        $this->assertTrue(
            AttendanceDay::whereDate('work_date', '2026-08-27')
                ->where('employee_id', $this->employee->id)
                ->exists(),
            'The next shift was never started.'
        );
    }

    #[Test]
    public function the_forgotten_day_is_left_open_for_hr_rather_than_guessed_at(): void
    {
        AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-26',
            'time_in' => '2026-08-27 02:39:00',
        ]);

        $this->travelTo(Carbon::parse('2026-08-27 21:52:00'));
        $this->clock()->call('timeIn');

        $stale = AttendanceDay::whereDate('work_date', '2026-08-26')->sole();

        $this->assertNull($stale->time_out, 'An end time was invented for a shift nobody recorded.');
    }

    #[Test]
    public function clocking_out_closes_tonight_and_not_the_forgotten_day(): void
    {
        // The one that would quietly cost money: closing the stale day here
        // would bill 19 hours nobody worked and leave tonight still open.
        AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-26',
            'time_in' => '2026-08-27 02:39:00',
        ]);

        $this->travelTo(Carbon::parse('2026-08-27 21:52:00'));
        $this->clock()->call('timeIn');

        $this->travelTo(Carbon::parse('2026-08-28 06:00:00'));
        $this->clock()->call('timeOut');

        $this->assertNull(AttendanceDay::whereDate('work_date', '2026-08-26')->sole()->time_out);
        $this->assertNotNull(AttendanceDay::whereDate('work_date', '2026-08-27')->sole()->time_out);
    }

    #[Test]
    public function punching_in_twice_on_the_same_shift_is_still_refused(): void
    {
        // The guard still has to do its real job.
        $this->travelTo(Carbon::parse('2026-08-27 22:00:00'));

        $this->clock()->call('timeIn')->assertSet('errorMessage', null);
        $this->clock()->call('timeIn')->assertSet('errorMessage', 'You are already timed in.');

        $this->assertSame(1, AttendanceDay::count());
    }

    #[Test]
    public function a_shift_already_finished_cannot_be_started_again(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 22:00:00'));
        $this->clock()->call('timeIn');

        $this->travelTo(Carbon::parse('2026-08-28 06:00:00'));
        $this->clock()->call('timeOut');

        // Back at the same shift date, an hour later.
        $this->travelTo(Carbon::parse('2026-08-28 07:00:00'));
        $this->clock()->call('timeIn')
            ->assertSet('errorMessage', 'You have already completed your shift for today.');

        $this->assertSame(1, AttendanceDay::count());
    }
}
