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
 * The only place an onboarding answer can be corrected.
 *
 * An employee fills these in once and cannot touch them again — the onboarding
 * form refuses a second submission, and My Profile shows them as plain text. So
 * a birthdate typed wrongly, or somebody who has moved house, has to be fixable
 * here or it is not fixable at all.
 */
class EmployeeEditTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');

        $this->employee = Employee::factory()->create([
            // The form requires it, and the factory leaves it empty.
            'personal_email' => 'maria@example.com',
            // These only become editable once the employee has answered them,
            // so most of these tests need a submitted onboarding form.
            'onboarding_completed_at' => now(),
            'birthdate' => '1990-05-14',
            'civil_status' => 'Single',
            'address' => '12 Old Street, Cebu',
            'emergency_contact_name' => 'Rosa Santos',
        ]);

        $this->actingAs($this->admin);
    }

    protected function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test('employees.edit', ['employee' => $this->employee]);
    }

    #[Test]
    public function the_form_opens_with_what_the_employee_filled_in(): void
    {
        $this->form()
            ->assertSet('birthdate', '1990-05-14')
            ->assertSet('civil_status', 'Single')
            ->assertSet('address', '12 Old Street, Cebu')
            ->assertSet('emergency_contact_name', 'Rosa Santos');
    }

    #[Test]
    public function hr_can_correct_the_onboarding_answers(): void
    {
        $this->form()
            ->set('birthdate', '1990-05-15')
            ->set('civil_status', 'Married')
            ->set('address', '88 New Road, Mandaue')
            ->set('personal_contact_number', '0917 555 0000')
            ->set('emergency_contact_name', 'Mateo Santos')
            ->set('emergency_contact_number', '0918 555 1111')
            ->set('tin_number', '123-456-789-000')
            ->set('sss_number', '34-1234567-8')
            ->set('philhealth_number', '12-345678901-2')
            ->set('pagibig_number', '1234-5678-9012')
            ->call('save')
            ->assertHasNoErrors();

        $this->employee->refresh();

        $this->assertSame('1990-05-15', $this->employee->birthdate->toDateString());
        $this->assertSame('Married', $this->employee->civil_status);
        $this->assertSame('88 New Road, Mandaue', $this->employee->address);
        $this->assertSame('0917 555 0000', $this->employee->personal_contact_number);
        $this->assertSame('Mateo Santos', $this->employee->emergency_contact_name);
        $this->assertSame('0918 555 1111', $this->employee->emergency_contact_number);
        $this->assertSame('123-456-789-000', $this->employee->tin_number);
        $this->assertSame('34-1234567-8', $this->employee->sss_number);
        $this->assertSame('12-345678901-2', $this->employee->philhealth_number);
        $this->assertSame('1234-5678-9012', $this->employee->pagibig_number);
    }

    #[Test]
    public function clearing_an_optional_field_stores_nothing_rather_than_an_empty_string(): void
    {
        // civil_status is an ENUM and birthdate a DATE. Writing '' to either
        // fails in MySQL rather than storing a blank, and SQLite would quietly
        // keep the empty string — so neither driver tells you off in testing.
        $this->form()
            ->set('birthdate', '')
            ->set('civil_status', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->employee->refresh();

        $this->assertNull($this->employee->birthdate);
        $this->assertNull($this->employee->civil_status);
    }

    #[Test]
    public function a_birthdate_in_the_future_is_refused(): void
    {
        $this->form()
            ->set('birthdate', now()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors('birthdate');
    }

    #[Test]
    public function the_personal_section_is_not_shown_before_onboarding_is_submitted(): void
    {
        $waiting = Employee::factory()->create([
            'personal_email' => 'juan@example.com',
            'first_name' => 'Juan',
            'onboarding_completed_at' => null,
        ]);

        Livewire::test('employees.edit', ['employee' => $waiting])
            ->assertSee('Waiting on Juan')
            ->assertDontSee('Emergency Contact Number');
    }

    #[Test]
    public function hr_cannot_answer_the_onboarding_questions_on_the_employees_behalf(): void
    {
        /*
         * The fields are not rendered, but a crafted request could still post
         * them. Somebody's TIN and address must come from them, not from what
         * HR believes it to be — a record filled in this way reads as answered
         * when nobody was ever asked.
         */
        $waiting = Employee::factory()->create([
            'personal_email' => 'juan@example.com',
            'onboarding_completed_at' => null,
        ]);

        Livewire::test('employees.edit', ['employee' => $waiting])
            ->set('birthdate', '1990-01-01')
            ->set('address', 'Typed in by HR')
            ->set('tin_number', '999-999-999-999')
            ->call('save')
            ->assertHasNoErrors();

        $waiting->refresh();

        $this->assertNull($waiting->birthdate);
        $this->assertNull($waiting->address);
        $this->assertNull($waiting->tin_number);
    }

    #[Test]
    public function the_rest_of_the_form_still_saves_while_onboarding_is_outstanding(): void
    {
        // Locking the employee's own answers must not stop HR doing its own
        // job — a new hire's department and salary are set before they have
        // opened the onboarding link at all.
        $waiting = Employee::factory()->create([
            'personal_email' => 'juan@example.com',
            'onboarding_completed_at' => null,
        ]);

        Livewire::test('employees.edit', ['employee' => $waiting])
            ->set('first_name', 'Juanito')
            ->set('basic_salary', '25000')
            ->call('save')
            ->assertHasNoErrors();

        $waiting->refresh();

        $this->assertSame('Juanito', $waiting->first_name);
        $this->assertSame('25000.00', $waiting->basic_salary);
    }

    #[Test]
    public function an_employee_cannot_reopen_their_onboarding_form(): void
    {
        // The other half of the rule the user asked for: once submitted, only
        // HR changes these. A signed link that still works is not a way back in.
        $this->employee->update(['onboarding_completed_at' => now()]);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'onboarding.show',
            now()->addDays(7),
            ['employee' => $this->employee->id],
        );

        $this->get($url)->assertForbidden();
    }
}
