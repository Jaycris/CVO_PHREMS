<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\OffsiteAssignment;
use App\Models\User;
use App\Notifications\OffsiteWorkScheduled;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Setting up a whole event in one go.
 *
 * The company is at an exhibit from the 8th to the 13th and six people are on
 * the booth. Typing that six times, once per person, is how it gets typed five
 * times and somebody loses a day's pay.
 */
class OffsiteWorkScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->hr = User::factory()->create();
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo('attendance.offsite.manage');

        $this->actingAs($this->hr);
    }

    #[Test]
    public function one_form_covers_everybody_on_the_booth(): void
    {
        $team = Employee::factory()->count(3)->create();

        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-08')
            ->set('endDate', '2026-09-13')
            ->set('reason', 'Trade exhibit — booth duty')
            ->set('employeeIds', $team->pluck('id')->all())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3, OffsiteAssignment::count());

        foreach ($team as $employee) {
            $row = OffsiteAssignment::where('employee_id', $employee->id)->sole();

            $this->assertSame('2026-09-08', $row->start_date->toDateString());
            $this->assertSame('2026-09-13', $row->end_date->toDateString());
            $this->assertSame('Trade exhibit — booth duty', $row->reason);
            $this->assertSame($this->hr->id, $row->created_by_user_id);
            $this->assertSame(6, $row->dayCount(), 'Both ends should be included.');
        }
    }

    #[Test]
    public function one_person_can_be_taken_off_without_touching_the_rest(): void
    {
        // Why these are stored per employee rather than per event.
        $team = Employee::factory()->count(3)->create();

        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-08')
            ->set('endDate', '2026-09-13')
            ->set('reason', 'Trade exhibit')
            ->set('employeeIds', $team->pluck('id')->all())
            ->call('save');

        $dropped = OffsiteAssignment::where('employee_id', $team[1]->id)->sole();

        Livewire::test('attendance.offsite-work')->call('delete', $dropped->id);

        $this->assertSame(2, OffsiteAssignment::count());
        $this->assertSame(0, OffsiteAssignment::where('employee_id', $team[1]->id)->count());
    }

    #[Test]
    public function the_last_day_cannot_be_before_the_first(): void
    {
        $employee = Employee::factory()->create();

        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-13')
            ->set('endDate', '2026-09-08')
            ->set('reason', 'Trade exhibit')
            ->set('employeeIds', [$employee->id])
            ->call('save')
            ->assertHasErrors('endDate');

        $this->assertSame(0, OffsiteAssignment::count());
    }

    #[Test]
    public function nobody_ticked_is_refused(): void
    {
        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-08')
            ->set('endDate', '2026-09-13')
            ->set('reason', 'Trade exhibit')
            ->set('employeeIds', [])
            ->call('save')
            ->assertHasErrors('employeeIds');
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        // It answers "why was this day paid with no time in", months later.
        $employee = Employee::factory()->create();

        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-08')
            ->set('endDate', '2026-09-13')
            ->set('reason', '')
            ->set('employeeIds', [$employee->id])
            ->call('save')
            ->assertHasErrors('reason');
    }

    #[Test]
    public function everybody_added_is_told_they_need_not_clock_in(): void
    {
        /*
         * Staff know a missing punch costs them a day's pay, so an employee
         * sent to an exhibit will either try to clock in from a stand or spend
         * the week wondering whether they are being marked absent. Saying so in
         * advance is the whole point.
         */
        Notification::fake();

        $team = collect(range(1, 2))->map(function () {
            $user = User::factory()->create();
            $user->assignRole('Employee');

            return Employee::factory()->create(['user_id' => $user->id]);
        });

        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-08')
            ->set('endDate', '2026-09-13')
            ->set('reason', 'Trade exhibit')
            ->set('employeeIds', $team->pluck('id')->all())
            ->call('save');

        foreach ($team as $employee) {
            Notification::assertSentTo(
                $employee->user,
                OffsiteWorkScheduled::class,
                fn (OffsiteWorkScheduled $n) => $n->change === OffsiteWorkScheduled::ADDED
                    && str_contains($n->toArray($employee->user)['message'], 'No need to clock in'),
            );
        }
    }

    #[Test]
    public function taking_somebody_off_the_list_tells_them_to_clock_in_again(): void
    {
        // The message that matters most. Somebody told not to punch, then
        // quietly removed, loses a day's pay following the last thing they
        // heard.
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $employee = Employee::factory()->create(['user_id' => $user->id]);

        $assignment = OffsiteAssignment::create([
            'employee_id' => $employee->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-13',
            'reason' => 'Trade exhibit',
        ]);

        Notification::fake();

        Livewire::test('attendance.offsite-work')->call('delete', $assignment->id);

        Notification::assertSentTo(
            $user,
            OffsiteWorkScheduled::class,
            fn (OffsiteWorkScheduled $n) => $n->change === OffsiteWorkScheduled::REMOVED
                && str_contains($n->toArray($user)['message'], 'must clock in'),
        );
    }

    #[Test]
    public function somebody_with_no_login_is_recorded_anyway(): void
    {
        // Not everybody has an account. HR tells them in person, and a missing
        // inbox must not stop the record being made.
        Notification::fake();

        $employee = Employee::factory()->create(['user_id' => null]);

        Livewire::test('attendance.offsite-work')
            ->set('startDate', '2026-09-08')
            ->set('endDate', '2026-09-13')
            ->set('reason', 'Trade exhibit')
            ->set('employeeIds', [$employee->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, OffsiteAssignment::where('employee_id', $employee->id)->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function the_page_opens_for_somebody_who_may_manage_it(): void
    {
        Employee::factory()->create();

        $this->get('/offsite-work')
            ->assertOk()
            ->assertSee('Off-Site Work')
            ->assertSee('Add Off-Site Days');
    }

    #[Test]
    public function somebody_without_the_permission_cannot_reach_it(): void
    {
        $other = User::factory()->create();
        $other->assignRole('Admin');
        $other->givePermissionTo('attendance.view_all');

        $this->actingAs($other)->get('/offsite-work')->assertForbidden();
    }

    #[Test]
    public function separated_staff_are_not_offered(): void
    {
        // They cannot be sent to an exhibit.
        $current = Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $gone = Employee::factory()->create([
            'first_name' => ' Former',
            'last_name' => 'Colleague',
            'separation_date' => '2026-06-30',
        ]);

        Livewire::test('attendance.offsite-work')
            ->assertViewHas('employees', fn ($employees) => $employees->contains($current)
                && ! $employees->contains($gone));
    }
}
