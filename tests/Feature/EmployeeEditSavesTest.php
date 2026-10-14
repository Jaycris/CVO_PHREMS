<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Changes made on the employee profile actually landing.
 *
 * Two things looked broken from the HR side: setting somebody to clock in and
 * saving changed nothing, and a new company email never reached their login.
 * The first was a validation refusal nobody could see; the second was a copy
 * made once when the account was created and never kept up.
 */
class EmployeeEditSavesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    /** Somebody whose record is complete enough for the form to accept it. */
    protected function employee(array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'personal_email' => 'someone@gmail.com',
            'onboarding_completed_at' => now(),
        ], $attributes));
    }

    #[Test]
    public function turning_clocking_back_on_sticks(): void
    {
        $employee = $this->employee(['tracks_attendance' => false]);

        Livewire::test('employees.edit', ['employee' => $employee])
            ->assertSet('tracks_attendance', '0')
            ->set('tracks_attendance', '1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue((bool) $employee->fresh()->tracks_attendance);
    }

    /**
     * The one that mattered, and the one the tests above could never catch.
     *
     * No option carried `selected`, so the browser showed the first — Yes —
     * for somebody whose record said No. Picking the option already on screen
     * fires no change event, so nothing reached the server and Save wrote the
     * old value back. Setting the property in a test skips the markup
     * entirely, which is why every earlier test passed on a form that was
     * broken in front of HR all along.
     */
    #[Test]
    public function the_clocks_in_dropdown_opens_on_the_value_the_record_holds(): void
    {
        $fixed = $this->employee(['tracks_attendance' => false]);

        Livewire::test('employees.edit', ['employee' => $fixed])
            ->assertSeeHtml('<option value="0" selected>')
            ->assertDontSeeHtml('<option value="1" selected>');

        $punches = $this->employee(['tracks_attendance' => true]);

        Livewire::test('employees.edit', ['employee' => $punches])
            ->assertSeeHtml('<option value="1" selected>')
            ->assertDontSeeHtml('<option value="0" selected>');
    }

    #[Test]
    public function the_same_dropdown_on_the_new_employee_form_starts_on_yes(): void
    {
        Livewire::test('employees.create')
            ->assertSeeHtml('<option value="1" selected>')
            ->assertDontSeeHtml('<option value="0" selected>');
    }

    #[Test]
    public function a_blank_field_elsewhere_on_the_form_says_so_instead_of_going_quiet(): void
    {
        // Plenty of live records predate these fields being required, so a save
        // could be refused over something HR never filled in and never saw.
        $employee = $this->employee(['tracks_attendance' => false, 'personal_email' => null]);

        Livewire::test('employees.edit', ['employee' => $employee])
            ->set('tracks_attendance', '1')
            ->call('save')
            ->assertHasErrors('personal_email')
            ->assertSee('Nothing was saved')
            ->assertSee('personal email field is required');

        $this->assertFalse((bool) $employee->fresh()->tracks_attendance);
    }

    #[Test]
    public function a_new_company_email_moves_the_login_with_it(): void
    {
        $login = User::factory()->create(['email' => 'travis@inkspiremediallc.com']);
        $employee = $this->employee([
            'company_email' => 'travis@inkspiremediallc.com',
            'user_id' => $login->id,
        ]);

        Livewire::test('employees.edit', ['employee' => $employee])
            ->set('company_email', 'travis@inkspiremediahouse.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('travis@inkspiremediahouse.com', $login->fresh()->email);
    }

    /**
     * A pair that had already drifted apart still catches up.
     *
     * The first version of this only moved a login that still matched the old
     * company email. That skipped every pair already out of step — the ones
     * that most needed it — so changing the profile did nothing and no amount
     * of re-saving would ever fix them.
     */
    #[Test]
    public function a_login_that_had_already_drifted_is_brought_back_in_line(): void
    {
        $login = User::factory()->create(['email' => 'travis@somewhere-else.com']);
        $employee = $this->employee([
            'company_email' => 'travis@inkspiremediallc.com',
            'user_id' => $login->id,
        ]);

        Livewire::test('employees.edit', ['employee' => $employee])
            ->set('company_email', 'travis@inkspiremediahouse.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('travis@inkspiremediahouse.com', $login->fresh()->email);
    }

    #[Test]
    public function the_login_is_left_alone_when_another_account_already_answers_to_the_address(): void
    {
        User::factory()->create(['email' => 'taken@inkspiremediahouse.com']);

        $login = User::factory()->create(['email' => 'travis@inkspiremediallc.com']);
        $employee = $this->employee([
            'company_email' => 'travis@inkspiremediallc.com',
            'user_id' => $login->id,
        ]);

        Livewire::test('employees.edit', ['employee' => $employee])
            ->set('company_email', 'taken@inkspiremediahouse.com')
            ->call('save')
            ->assertHasNoErrors();

        // The profile change is what was asked for and it went through; the
        // login keeps working on the old address rather than colliding.
        $this->assertSame('taken@inkspiremediahouse.com', $employee->fresh()->company_email);
        $this->assertSame('travis@inkspiremediallc.com', $login->fresh()->email);
    }
}
