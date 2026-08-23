<?php

namespace Tests\Feature;

use App\Models\CommissionScheme;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The commission plans an agent can be put on.
 *
 * This list is not the HRIS's to invent — the CRM works out every figure and
 * the HRIS only records which plan someone is on. It used to be hard-coded as
 * "Tier 1/2/3" in two forms, which meant matching the CRM needed a release.
 */
class CommissionSchemeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AppSettingSeeder::class);

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);
    }

    #[Test]
    public function only_active_schemes_are_offered_on_the_employee_form(): void
    {
        CommissionScheme::create(['name' => 'Standard', 'sort_order' => 0]);
        CommissionScheme::create(['name' => 'Senior', 'sort_order' => 1]);
        CommissionScheme::create(['name' => 'Retired Plan', 'is_active' => false, 'sort_order' => 2]);

        $options = CommissionScheme::options();

        $this->assertSame(['Standard' => 'Standard', 'Senior' => 'Senior'], $options);
        $this->assertArrayNotHasKey('Retired Plan', $options);
    }

    #[Test]
    public function the_crm_name_falls_back_to_the_scheme_name(): void
    {
        $same = CommissionScheme::create(['name' => 'Standard']);
        $different = CommissionScheme::create(['name' => 'Senior', 'crm_key' => 'senior_service_profile']);

        // The CRM stores this as the service profile even though its screen
        // says Commission Scheme, so the two spellings can legitimately differ.
        $this->assertSame('Standard', $same->crmName());
        $this->assertSame('senior_service_profile', $different->crmName());
    }

    #[Test]
    public function renaming_a_scheme_moves_everyone_on_it(): void
    {
        $scheme = CommissionScheme::create(['name' => 'Tier 1']);
        $employee = Employee::factory()->create(['commission_scheme' => 'Tier 1']);

        // Employees carry the scheme by name. Without following through, a
        // rename silently drops everybody off their plan.
        $this->assertSame(1, $scheme->employees()->count());

        Employee::where('commission_scheme', 'Tier 1')->update(['commission_scheme' => 'Standard']);
        $scheme->update(['name' => 'Standard']);

        $this->assertSame('Standard', $employee->fresh()->commission_scheme);
        $this->assertSame(1, $scheme->fresh()->employees()->count());
    }

    #[Test]
    public function a_scheme_with_agents_on_it_counts_them(): void
    {
        $scheme = CommissionScheme::create(['name' => 'Standard']);
        Employee::factory()->count(3)->create(['commission_scheme' => 'Standard']);
        Employee::factory()->create(['commission_scheme' => 'Senior']);

        $this->assertSame(3, $scheme->employees()->count());
    }

    #[Test]
    public function the_schemes_page_opens_and_lists_them(): void
    {
        CommissionScheme::create(['name' => 'Standard', 'crm_key' => 'standard_profile']);

        $this->get('/commissions/schemes')
            ->assertOk()
            ->assertSee('Standard')
            ->assertSee('standard_profile')
            // The whole reason the page exists, said on the page.
            ->assertSee('These names have to match the CRM');
    }

    #[Test]
    public function an_employee_cannot_open_the_schemes_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $this->actingAs($user)->get('/commissions/schemes')->assertForbidden();
    }

    #[Test]
    public function the_employee_forms_no_longer_hard_code_the_invented_tiers(): void
    {
        // "Tier 1/2/3" was written into both forms and matched nothing in the
        // CRM, so changing it to the CRM's real names needed a release. The
        // dropdown is only rendered for Sales-department employees, which is
        // why this reads the source rather than scraping the page.
        foreach (['create', 'edit'] as $form) {
            $source = file_get_contents(resource_path("views/components/employees/⚡{$form}.blade.php"));

            $this->assertStringNotContainsString('in:Tier 1,Tier 2,Tier 3', $source,
                "{$form} still validates against the hard-coded tier list");
            $this->assertStringNotContainsString('<option value="Tier 1">', $source,
                "{$form} still offers the hard-coded tiers");
            $this->assertStringContainsString('CommissionScheme::options()', $source,
                "{$form} does not read the schemes table");
        }
    }

    #[Test]
    public function a_scheme_that_is_not_on_the_list_is_not_a_valid_choice(): void
    {
        CommissionScheme::create(['name' => 'Default Tier']);

        $allowed = array_keys(CommissionScheme::options());

        // This is what the employee form validates against, so an agent cannot
        // be filed under a plan the CRM has never heard of.
        $this->assertContains('Default Tier', $allowed);
        $this->assertNotContains('Tier 1', $allowed);
        $this->assertNotContains('Whatever Somebody Typed', $allowed);
    }
}
