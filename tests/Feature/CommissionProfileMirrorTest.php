<?php

namespace Tests\Feature;

use App\Models\CommissionScheme;
use App\Models\Employee;
use App\Services\Commission\CommissionProfileMirror;
use App\Services\Crm\CommissionSlip as CrmCommissionSlip;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Keeping an agent's scheme and target in step with the CRM.
 *
 * The CRM owns both. What the employee record holds is a copy, and a copy
 * nobody refreshes is just a number that used to be true — which is exactly
 * what happened: the profile said Tier 2 at 5,000 while the CRM had moved to
 * Tier 1 at 10,000, and nothing in either system noticed.
 */
class CommissionProfileMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AppSettingSeeder::class);
    }

    protected function mirror(): CommissionProfileMirror
    {
        return app(CommissionProfileMirror::class);
    }

    protected function slip(?string $scheme, ?float $target): CrmCommissionSlip
    {
        return CrmCommissionSlip::fromCrm([
            'agent' => array_filter([
                'name' => 'Mia Santos',
                'commission_scheme' => $scheme === null ? null : ['name' => $scheme],
                'agent_target' => $target,
            ]),
        ], '2026-08');
    }

    #[Test]
    public function it_copies_the_scheme_and_target_onto_the_employee(): void
    {
        $employee = Employee::factory()->create([
            'commission_scheme' => 'Tier 2',
            'quota' => 5000,
        ]);

        $changed = $this->mirror()->apply($employee, $this->slip('Tier 1', 10000));

        $this->assertTrue($changed);
        $this->assertSame('Tier 1', $employee->fresh()->commission_scheme);
        $this->assertSame(10000.0, (float) $employee->fresh()->quota);
    }

    #[Test]
    public function it_writes_nothing_when_the_record_already_agrees(): void
    {
        $employee = Employee::factory()->create([
            'commission_scheme' => 'Tier 1',
            'quota' => 10000,
        ]);

        $before = $employee->updated_at;

        // A refresh that changes nothing must not stamp updated_at on every
        // agent whose profile happens to get opened.
        $this->assertFalse($this->mirror()->apply($employee, $this->slip('Tier 1', 10000)));
        $this->assertEquals($before, $employee->fresh()->updated_at);
    }

    #[Test]
    public function a_scheme_the_crm_names_is_added_to_the_list(): void
    {
        $employee = Employee::factory()->create(['commission_scheme' => 'Tier 2']);

        $this->mirror()->apply($employee, $this->slip('Senior Tier', null));

        // Otherwise the agent sits on a plan the employee form cannot offer,
        // and the next edit silently moves them off it.
        $this->assertTrue(CommissionScheme::where('name', 'Senior Tier')->exists());
        $this->assertArrayHasKey('Senior Tier', CommissionScheme::options());
    }

    #[Test]
    public function a_field_the_crm_did_not_send_is_left_alone(): void
    {
        $employee = Employee::factory()->create([
            'commission_scheme' => 'Tier 2',
            'quota' => 5000,
        ]);

        // Silence is not an instruction to blank the record.
        $this->mirror()->apply($employee, $this->slip(null, null));

        $this->assertSame('Tier 2', $employee->fresh()->commission_scheme);
        $this->assertSame(5000.0, (float) $employee->fresh()->quota);
    }

    #[Test]
    public function somebody_not_yet_marked_as_an_agent_is_still_asked_about(): void
    {
        // The old design only asked about people already believed to be
        // agents, so it could switch somebody off but never on — and
        // forgetting to switch somebody on is what costs them their pay.
        $employee = Employee::factory()->create([
            'commission_scheme' => null,
            'commission_frequency' => 'none',
        ]);

        $this->mirror()->applyDirectoryEntry($employee, [
            'eligible' => true, 'scheme' => 'Tier 2', 'target' => 10000.0,
        ]);

        $this->assertSame('monthly', $employee->fresh()->commission_frequency);
    }

    #[Test]
    public function a_crm_that_cannot_answer_leaves_the_record_untouched(): void
    {
        // Opening a profile must never fail because another system is down.
        config(['services.crm.base_url' => 'http://127.0.0.1:9']);

        $employee = Employee::factory()->create([
            'commission_scheme' => 'Tier 1',
            'quota' => 10000,
        ]);

        $this->assertFalse($this->mirror()->refresh($employee));
        $this->assertSame('Tier 1', $employee->fresh()->commission_scheme);
        $this->assertSame(10000.0, (float) $employee->fresh()->quota);
    }

    #[Test]
    public function nothing_happens_when_the_crm_is_not_set_up_at_all(): void
    {
        config(['services.crm.base_url' => '']);

        $employee = Employee::factory()->create(['commission_scheme' => 'Tier 1']);

        $this->assertFalse($this->mirror()->refresh($employee));
    }

    #[Test]
    public function the_crm_decides_who_earns_commission(): void
    {
        $employee = Employee::factory()->create(["commission_frequency" => "none"]);

        $mirror = $this->mirror();
        $mirror->applyDirectoryEntry($employee, ["eligible" => true, "scheme" => "Tier 2", "target" => 10000.0]);

        // The whole point: forgetting to switch somebody on cannot cost them
        // their commission, because PHREMS is not the one deciding.
        $this->assertSame("monthly", $employee->fresh()->commission_frequency);
        $this->assertSame("Tier 2", $employee->fresh()->commission_scheme);
        $this->assertSame(10000.0, (float) $employee->fresh()->quota);
    }

    #[Test]
    public function a_deliberate_bi_weekly_choice_survives_a_refresh(): void
    {
        // The CRM answers yes or no and has no concept of a run frequency, so
        // overwriting bi-weekly with monthly would be inventing an answer.
        $employee = Employee::factory()->create(["commission_frequency" => "biweekly"]);

        $this->mirror()->applyDirectoryEntry($employee, ["eligible" => true, "scheme" => null, "target" => null]);

        $this->assertSame("biweekly", $employee->fresh()->commission_frequency);
    }

    #[Test]
    public function somebody_the_crm_says_is_not_eligible_is_switched_off(): void
    {
        $employee = Employee::factory()->create(["commission_frequency" => "monthly"]);

        $this->mirror()->applyDirectoryEntry($employee, ["eligible" => false, "scheme" => "Tier 2", "target" => 10000.0]);

        $this->assertSame("none", $employee->fresh()->commission_frequency);
    }

    #[Test]
    public function a_scheme_is_not_copied_onto_somebody_who_is_not_an_agent(): void
    {
        // It would sit on a profile that then hides it, which reads as a bug.
        $employee = Employee::factory()->create(["commission_frequency" => "monthly", "commission_scheme" => null]);

        $this->mirror()->applyDirectoryEntry($employee, ["eligible" => false, "scheme" => "Tier 2", "target" => 10000.0]);

        $this->assertNull($employee->fresh()->commission_scheme);
    }

    #[Test]
    public function an_employee_the_crm_has_never_heard_of_is_left_alone(): void
    {
        // An unreachable CRM returns an empty directory too, so absence must
        // never be read as "no longer an agent".
        config(["services.crm.base_url" => "http://127.0.0.1:9"]);

        $employee = Employee::factory()->create([
            "commission_frequency" => "monthly",
            "commission_scheme" => "Tier 2",
        ]);

        $this->assertFalse($this->mirror()->refresh($employee));
        $this->assertSame("monthly", $employee->fresh()->commission_frequency);
        $this->assertSame("Tier 2", $employee->fresh()->commission_scheme);
    }

    #[Test]
    public function the_profile_hides_commission_rows_from_people_who_do_not_earn_it(): void
    {
        $source = file_get_contents(resource_path("views/components/employees/⚡show.blade.php"));

        $this->assertStringContainsString("commission_frequency !== \x27none\x27", $source,
            "the compensation card still shows commission rows to everybody");
    }
}