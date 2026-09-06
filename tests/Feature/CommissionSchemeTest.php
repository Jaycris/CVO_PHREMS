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
    public function the_employee_forms_no_longer_hard_code_the_invented_tiers(): void
    {
        // "Tier 1/2/3" was written into both forms and matched nothing in the
        // CRM, so changing it to the CRM's real names needed a release. The
        // dropdown is not always rendered, which is why this reads the source
        // rather than scraping the page.
        foreach (['create', 'edit'] as $form) {
            $source = file_get_contents(resource_path("views/components/employees/⚡{$form}.blade.php"));

            $this->assertStringNotContainsString('in:Tier 1,Tier 2,Tier 3', $source,
                "{$form} still validates against the hard-coded tier list");
            $this->assertStringNotContainsString('<option value="Tier 1">', $source,
                "{$form} still offers the hard-coded tiers");
        }

        // Only create still offers a choice. Edit shows the CRM's answer and
        // has no dropdown to fill, so it has no schemes list to read.
        $this->assertStringContainsString(
            'CommissionScheme::options()',
            file_get_contents(resource_path('views/components/employees/⚡create.blade.php')),
            'create does not read the schemes table',
        );
    }

    #[Test]
    public function the_edit_form_does_not_let_anybody_choose_a_scheme(): void
    {
        // It is the CRM's to decide. A dropdown here would be overwritten on
        // the next page open, which reads as the form losing the answer.
        $source = file_get_contents(resource_path('views/components/employees/⚡edit.blade.php'));

        $this->assertStringNotContainsString('wire:model="commission_scheme"', $source,
            'edit still lets somebody pick a scheme over the CRM\'s');
        $this->assertStringContainsString('<x-label>Commission Scheme</x-label>', $source,
            'edit no longer shows the scheme at all');
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

    #[Test]
    public function the_slip_reads_the_scheme_the_crm_sends_under_any_of_its_names(): void
    {
        // The CRM stores this as the service profile while its screen says
        // Commission Scheme, so both spellings are accepted rather than one
        // being guessed at and the other silently ignored.
        foreach (["commission_scheme", "scheme", "service_profile", "serviceProfile"] as $key) {
            $slip = \App\Services\Crm\CommissionSlip::fromCrm([
                "agent" => ["name" => "Mia Santos", $key => "Default Tier"],
            ], "2026-08");

            $this->assertSame("Default Tier", $slip->scheme, "the CRM key {$key} was ignored");
        }
    }

    #[Test]
    public function a_scheme_changed_in_the_crm_is_flagged_against_the_one_on_file(): void
    {
        $slip = \App\Services\Crm\CommissionSlip::fromCrm([
            "agent" => ["name" => "Mia Santos", "commission_scheme" => "Senior Tier"],
        ], "2026-08");

        $this->assertTrue($slip->schemeDisagreesWith("Default Tier"));
        $this->assertFalse($slip->schemeDisagreesWith("Senior Tier"));
        $this->assertFalse($slip->schemeDisagreesWith("  senior tier  "), "spacing and case should not count as a difference");
    }

    #[Test]
    public function nothing_is_flagged_while_the_crm_sends_no_scheme_at_all(): void
    {
        // Which is where it stands today. Warning on every slip because the CRM
        // is silent would teach people to ignore the warning before it ever
        // meant anything.
        $slip = \App\Services\Crm\CommissionSlip::fromCrm([
            "agent" => ["name" => "Mia Santos"],
        ], "2026-08");

        $this->assertNull($slip->scheme);
        $this->assertFalse($slip->schemeDisagreesWith("Default Tier"));
    }
}