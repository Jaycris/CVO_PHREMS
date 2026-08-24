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
    public function only_commission_agents_are_asked_about(): void
    {
        // Most of the company is not on commission. Asking the CRM about them
        // would be an HTTP call per profile view to learn nothing.
        $agent = Employee::factory()->create(['commission_scheme' => 'Tier 1']);
        $byFrequency = Employee::factory()->create(['commission_frequency' => 'monthly']);
        $notAnAgent = Employee::factory()->create(['commission_scheme' => null, 'commission_frequency' => 'none']);

        $this->assertTrue($this->mirror()->tracks($agent));
        $this->assertTrue($this->mirror()->tracks($byFrequency));
        $this->assertFalse($this->mirror()->tracks($notAnAgent));
    }

    #[Test]
    public function a_non_agent_is_never_sent_to_the_crm(): void
    {
        $employee = Employee::factory()->create(['commission_scheme' => null, 'commission_frequency' => 'none']);

        $this->assertFalse($this->mirror()->refresh($employee));
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
}
