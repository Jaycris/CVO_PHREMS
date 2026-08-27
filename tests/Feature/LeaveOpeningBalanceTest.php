<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The days somebody arrived with, decided by the CEO or COO.
 *
 * Back-filled accrual covers anybody whose whole history is in PHREMS. It
 * cannot know about leave taken, granted or carried over on paper beforehand,
 * so somebody has to be able to state what a person actually starts with.
 *
 * Deliberately not HR's to set, on the same reasoning as approving a bank
 * change: whoever maintains an employee record should not also decide how many
 * days that record begins with, and vacation leave becomes cash at year end.
 */
class LeaveOpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected LeaveType $vacation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->employee = Employee::factory()->create(['hire_date' => '2026-05-14']);
        $this->vacation = LeaveType::where('code', 'VL')->sole();
    }

    protected function chief(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo('leave.opening_balance.manage');

        return $user;
    }

    protected function hrOfficer(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo(['employees.manage', 'leave.types.manage']);

        return $user;
    }

    protected function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test('employees.show', ['employee' => $this->employee]);
    }

    #[Test]
    public function the_chief_can_set_a_starting_balance(): void
    {
        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $this->vacation->id)
            ->set('openingDays', '9')
            ->set('openingNote', 'Carried over from the 2025 spreadsheet')
            ->call('saveOpeningBalance')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(9.0, (float) $this->employee->leaveBalance($this->vacation), 0.001);
    }

    #[Test]
    public function hr_cannot(): void
    {
        $this->actingAs($this->hrOfficer());

        $this->page()->call('openOpeningBalance', $this->vacation->id)->assertForbidden();
    }

    #[Test]
    public function hr_cannot_reach_it_by_posting_straight_to_the_save(): void
    {
        // The button is not drawn for them, which is not the same as it being
        // refused.
        $this->actingAs($this->hrOfficer());

        $this->page()
            ->set('openingDays', '99')
            ->set('openingNote', 'Trying it on')
            ->call('saveOpeningBalance')
            ->assertForbidden();

        $this->assertSame(0.0, (float) $this->employee->leaveBalance($this->vacation));
    }

    #[Test]
    public function the_difference_is_recorded_rather_than_the_whole_balance(): void
    {
        // The ledger is the balance. Overwriting it with one number would bury
        // the accruals and the leave already taken.
        (new LeaveService)->backfillAccruals(Carbon::parse('2026-08-27'));

        $before = (float) $this->employee->leaveBalance($this->vacation);
        $this->assertEqualsWithDelta(2.499, $before, 0.001);

        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $this->vacation->id)
            ->set('openingDays', '9')
            ->set('openingNote', 'Carried over from the 2025 spreadsheet')
            ->call('saveOpeningBalance')
            ->assertHasNoErrors();

        // A centavo of tolerance: the difference is rounded to two places
        // because that is all the amount column holds, while the accrual rate
        // carries three.
        $this->assertEqualsWithDelta(9.0, (float) $this->employee->leaveBalance($this->vacation), 0.01);

        // The accruals survive, and the correction is its own visible line.
        $this->assertSame(3, LeaveCreditTransaction::where('reason', 'monthly_accrual')->count());

        $adjustment = LeaveCreditTransaction::where('reason', 'initial_grant')->sole();
        $this->assertEqualsWithDelta(6.5, (float) $adjustment->amount, 0.01);
    }

    #[Test]
    public function it_is_recorded_against_the_person_who_set_it(): void
    {
        $chief = $this->chief();
        $this->actingAs($chief);

        $this->page()
            ->call('openOpeningBalance', $this->vacation->id)
            ->set('openingDays', '5')
            ->set('openingNote', 'Agreed at hiring')
            ->call('saveOpeningBalance');

        $entry = LeaveCreditTransaction::where('reason', 'initial_grant')->sole();

        $this->assertSame($chief->id, $entry->created_by_user_id);
        $this->assertSame('Agreed at hiring', $entry->note);
    }

    #[Test]
    public function a_reason_is_required(): void
    {
        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $this->vacation->id)
            ->set('openingDays', '9')
            ->set('openingNote', '')
            ->call('saveOpeningBalance')
            ->assertHasErrors('openingNote');
    }

    #[Test]
    public function hr_cannot_revert_a_balance_the_chief_set(): void
    {
        /*
         * Both are written as initial_grant, so Revert Grant would happily have
         * deleted the CEO's decision — which would have made restricting that
         * decision to the CEO pointless.
         */
        $sick = LeaveType::where('code', 'SL')->sole();

        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $sick->id)
            ->set('openingDays', '5')
            ->set('openingNote', 'Agreed at hiring')
            ->call('saveOpeningBalance')
            ->assertHasNoErrors();

        $this->actingAs($this->hrOfficer());

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->call('revertGrant', $sick->id)
            ->assertNotFound();

        $this->assertEqualsWithDelta(5.0, (float) $this->employee->leaveBalance($sick), 0.01);
    }

    #[Test]
    public function hr_cannot_grant_on_top_of_a_balance_the_chief_set(): void
    {
        // Otherwise the employee would end up with ten sick days instead of
        // the five they were given.
        $sick = LeaveType::where('code', 'SL')->sole();

        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $sick->id)
            ->set('openingDays', '5')
            ->set('openingNote', 'Agreed at hiring')
            ->call('saveOpeningBalance');

        $this->actingAs($this->hrOfficer());

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->call('grantInitialCredits', $sick->id)
            ->assertForbidden();

        $this->assertEqualsWithDelta(5.0, (float) $this->employee->leaveBalance($sick), 0.01);
    }

    #[Test]
    public function hr_cannot_grant_again_once_the_year_is_spent(): void
    {
        /*
         * The hole the balance check left. Somebody given five sick days who
         * then uses all five is back to nought, and a guard reading "balance
         * is nought, so grant" hands them five more — ten days for the year.
         */
        $sick = LeaveType::where('code', 'SL')->sole();

        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $sick->id)
            ->set('openingDays', '5')
            ->set('openingNote', 'Agreed at hiring')
            ->call('saveOpeningBalance');

        // They take all five.
        LeaveCreditTransaction::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $sick->id,
            'transaction_date' => now()->toDateString(),
            'amount' => -5,
            'reason' => 'leave_taken',
        ]);

        $this->assertSame(0.0, (float) $this->employee->leaveBalance($sick));

        $this->actingAs($this->hrOfficer());

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->call('grantInitialCredits', $sick->id)
            ->assertForbidden();

        $this->assertSame(0.0, (float) $this->employee->leaveBalance($sick));
    }

    #[Test]
    public function hr_can_still_revert_its_own_grant(): void
    {
        // The guard must not break the button's actual purpose: undoing an
        // accidental click.
        $sick = LeaveType::where('code', 'SL')->sole();

        $this->actingAs($this->hrOfficer());

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->call('grantInitialCredits', $sick->id);

        $this->assertEqualsWithDelta(5.0, (float) $this->employee->leaveBalance($sick), 0.01);

        Livewire::test('employees.show', ['employee' => $this->employee])
            ->call('revertGrant', $sick->id);

        $this->assertSame(0.0, (float) $this->employee->leaveBalance($sick));
    }

    #[Test]
    public function setting_it_to_what_it_already_is_writes_nothing(): void
    {
        $this->actingAs($this->chief());

        $this->page()
            ->call('openOpeningBalance', $this->vacation->id)
            ->set('openingDays', '0')
            ->set('openingNote', 'No change')
            ->call('saveOpeningBalance')
            ->assertHasNoErrors();

        $this->assertSame(0, LeaveCreditTransaction::count());
    }
}
