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

            preg_match_all("/'(?:Quota|Agent Target)' => .*/", $source, $matches);

            $this->assertNotEmpty($matches[0], "{$screen} no longer shows the agent target");

            foreach ($matches[0] as $line) {
                $this->assertStringNotContainsString('PHP', $line,
                    "{$screen} shows the agent target in pesos, but the CRM sets it in dollars");
                $this->assertStringContainsString('USD', $line,
                    "{$screen} does not say which currency the agent target is in");
            }
        }
    }

    #[Test]
    public function both_employee_forms_say_where_the_target_comes_from(): void
    {
        foreach (['create', 'edit'] as $form) {
            $source = file_get_contents(resource_path("views/components/employees/⚡{$form}.blade.php"));

            $this->assertStringContainsString('<x-label>Agent Target</x-label>', $source,
                "{$form} still calls it Quota, which is not what the CRM calls it");
            $this->assertStringContainsString('In US dollars.', $source,
                "{$form} does not say the target is in dollars");
        }
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
