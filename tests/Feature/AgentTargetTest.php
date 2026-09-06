<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\Crm\CommissionSlip;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The agent's target, which lives in two systems and must not drift.
 *
 * The CRM owns it — it is set per month in the commission profile there, and
 * every mtd_percent on a slip is worked out against that figure. What the HRIS
 * holds is a copy for HR to look at, so the one thing that must never happen
 * is the copy being shown in a different currency from the original.
 *
 * It was labelled "PHP" here while the CRM works in US dollars, which made a
 * $10,000 target read as ₱10,000 — out by roughly fifty-six times.
 */
class AgentTargetTest extends TestCase
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
    public function the_agent_target_is_never_shown_as_pesos(): void
    {
        // Every screen that prints this number, checked by reading the source
        // rather than rendering — two of the three are only reachable with a
        // signing link or a linked employee record.
        $screens = [
            'views/components/employees/⚡show.blade.php',
            'views/components/public/⚡onboarding-form.blade.php',
            'views/components/⚡my-profile.blade.php',
        ];

        foreach ($screens as $screen) {
            $source = file_get_contents(resource_path($screen));

            // Reads a window after each mention rather than matching a
            // statement: the value is written three different ways across
            // these screens, and on the profile it spans two lines because the
            // rows are only built for people who earn commission.
            $found = false;

            foreach (['Agent Target', 'Quota'] as $label) {
                $at = 0;

                while (($at = strpos($source, "'{$label}'", $at)) !== false) {
                    $found = true;
                    $window = substr($source, $at, 200);
                    $at += strlen($label);

                    $this->assertStringNotContainsString('PHP ', $window,
                        "{$screen} shows the agent target in pesos, but the CRM sets it in dollars");
                    $this->assertStringContainsString('USD ', $window,
                        "{$screen} does not say which currency the agent target is in");
                }
            }

            $this->assertTrue($found, "{$screen} no longer shows the agent target");
        }
    }

    #[Test]
    public function the_create_form_says_where_the_target_comes_from(): void
    {
        // Still typed on create, because an employee may be added before the
        // CRM has ever heard of them.
        $source = file_get_contents(resource_path('views/components/employees/⚡create.blade.php'));

        $this->assertStringContainsString('<x-label>Agent Target</x-label>', $source,
            'create still calls it Quota, which is not what the CRM calls it');
        $this->assertStringContainsString('In US dollars.', $source,
            'create does not say the target is in dollars');
    }

    #[Test]
    public function the_edit_form_shows_the_target_without_letting_anybody_change_it(): void
    {
        /*
         * The CRM owns it and PHREMS mirrors it on every page open, so a figure
         * typed here was overwritten on the next visit — silently, with no
         * error. Showing it and refusing the edit is the honest version.
         */
        $source = file_get_contents(resource_path('views/components/employees/⚡edit.blade.php'));

        $this->assertStringContainsString('<x-label>Agent Target</x-label>', $source,
            'edit no longer shows the agent target at all');
        $this->assertStringNotContainsString('wire:model="quota"', $source,
            'edit still lets somebody type over the CRM\'s agent target');
        $this->assertStringContainsString("USD ' . number_format", $source,
            'edit does not say which currency the target is in');
    }

    #[Test]
    public function the_slip_takes_the_target_from_the_crm_not_from_the_employee_record(): void
    {
        $employee = Employee::factory()->create(['quota' => 50000]);

        // The CRM's own figure for the month, which is what it measured the
        // agent against. The stale 50,000 on the employee record must not win.
        $slip = CommissionSlip::fromCrm([
            'summary' => ['mtd' => 3798, 'target' => 10000, 'mtd_percent' => 37.98],
        ], '2026-08');

        $this->assertSame(10000.0, $slip->target);
        $this->assertSame(37.98, $slip->mtdPercent);
        $this->assertNotSame((float) $employee->quota, $slip->target);
    }

    #[Test]
    public function a_missing_target_renders_as_nothing_rather_than_zero(): void
    {
        // A confident 0 reads to an agent as "your target is nothing", which is
        // a different claim from "the CRM did not tell us".
        $slip = CommissionSlip::fromCrm(['summary' => ['mtd' => 3798]], '2026-08');

        $this->assertNull($slip->target);
    }
}
