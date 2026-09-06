<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The CRM owns the commission setup, and this screen only shows it.
 *
 * The three fields were editable, and PHREMS mirrors them from the CRM every
 * time the page opens — so anything typed here was overwritten on the next
 * visit, silently and with no error. HR would set somebody to "Yes", come back
 * later, and find it back on "No" with nothing to explain why.
 */
class CommissionSetupReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');

        $this->employee = Employee::factory()->create([
            'employee_id' => 'EMP-9256',
            'personal_email' => 'maria@example.com',
            'commission_frequency' => 'none',
            'commission_scheme' => null,
            'quota' => null,
        ]);

        $this->actingAs($admin);

        config([
            'services.crm.base_url' => 'https://crm.example.test',
            'services.crm.token' => 'a-forty-character-test-token-value-here',
            'services.crm.agent_cache_ttl' => 0,
        ]);
    }

    protected function crmAnswers(array $agents): void
    {
        Http::fake(['crm.example.test/*' => Http::response(['agents' => $agents], 200)]);
    }

    #[Test]
    public function the_setup_is_shown_as_text_when_the_crm_knows_them(): void
    {
        $this->crmAnswers([[
            'hris_employee_id' => 'EMP-9256',
            'is_commission_eligible' => true,
            'commission_scheme' => 'Tier 2',
            'agent_target' => 4000,
        ]]);

        Livewire::test('employees.edit', ['employee' => $this->employee])
            ->assertSet('crmKnowsEmployee', true)
            ->assertSee('Yes — monthly')
            ->assertSee('Tier 2')
            ->assertSee('USD 4,000.00')
            // No inputs for any of the three.
            ->assertDontSee('wire:model="commission_scheme"', false)
            ->assertDontSee('wire:model="quota"', false)
            ->assertDontSee('wire:model.live="commission_frequency"', false);
    }

    #[Test]
    public function a_note_is_shown_when_the_crm_has_nothing_for_them(): void
    {
        // The CRM answered and simply does not have this person.
        $this->crmAnswers([[
            'hris_employee_id' => 'EMP-0001',
            'is_commission_eligible' => true,
            'commission_scheme' => 'Tier 1',
            'agent_target' => 5000,
        ]]);

        Livewire::test('employees.edit', ['employee' => $this->employee])
            ->assertSet('crmKnowsEmployee', false)
            ->assertSee('Nothing from the CRM for this employee')
            ->assertSee('EMP-9256');
    }

    #[Test]
    public function a_different_note_is_shown_when_no_crm_is_configured(): void
    {
        config(['services.crm.base_url' => null, 'services.crm.token' => null]);

        Livewire::test('employees.edit', ['employee' => $this->employee])
            ->assertSet('crmReachable', false)
            ->assertSee('The CRM is not set up');
    }

    #[Test]
    public function saving_never_writes_the_commission_fields(): void
    {
        /*
         * The whole point. Even a crafted request cannot push a figure back
         * over the CRM's — the fields are not rendered, but a form post could
         * still carry them.
         */
        $this->crmAnswers([[
            'hris_employee_id' => 'EMP-9256',
            'is_commission_eligible' => true,
            'commission_scheme' => 'Tier 2',
            'agent_target' => 4000,
        ]]);

        Livewire::test('employees.edit', ['employee' => $this->employee])
            ->set('commission_frequency', 'biweekly')
            ->set('commission_scheme', 'Tier 1')
            ->set('quota', '99999')
            ->set('first_name', 'Maria')
            ->call('save')
            ->assertHasNoErrors();

        $employee = $this->employee->fresh();

        // Mirrored from the CRM on open, and untouched by the save.
        $this->assertSame('monthly', $employee->commission_frequency);
        $this->assertSame('Tier 2', $employee->commission_scheme);
        $this->assertSame('4000.00', $employee->quota);

        // The rest of the form still saves.
        $this->assertSame('Maria', $employee->first_name);
    }

    #[Test]
    public function the_rest_of_the_form_saves_when_the_crm_is_down(): void
    {
        // A CRM that cannot answer must not stop HR editing a department or a
        // salary. It only means the commission panel has nothing to show.
        Http::fake(['crm.example.test/*' => Http::response([], 500)]);

        Livewire::test('employees.edit', ['employee' => $this->employee])
            ->set('first_name', 'Maria')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Maria', $this->employee->fresh()->first_name);
    }
}
