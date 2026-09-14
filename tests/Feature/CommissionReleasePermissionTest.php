<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\CommissionSlipReady;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sending commission slips, without being able to decide the figures.
 *
 * The same split as payroll, for the same reason. Releasing slips was bundled
 * into commissions.runs.finalize — the permission to lock a run and to reopen
 * one — so letting HR press Send meant letting them unlock a month's commission
 * and change who is on it.
 *
 * Whoever finalizes can still send. Nothing about the existing arrangement
 * changes; the new permission only adds a narrower grant.
 */
class CommissionReleasePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    protected function hrWhoOnlyReleases(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo('commissions.slips.send');

        return $user;
    }

    protected function commissionManager(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo('commissions.runs.manage');
        $user->givePermissionTo('commissions.runs.finalize');

        return $user;
    }

    /**
     * A locked run with one slip nobody has been told about.
     *
     * Built directly rather than computed, because what is under test is who
     * may press the button — not the CRM figures behind it, which have their
     * own tests and would need the CRM faking here for no gain.
     */
    protected function finalizedRun(): CommissionRun
    {
        $employee = Employee::factory()->create(['commission_frequency' => 'monthly']);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'label' => 'August 2026',
            'status' => 'finalized',
            'finalized_at' => now(),
            'agent_count' => 1,
        ]);

        CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => $employee->id,
            'agent_name' => $employee->fullName(),
            'net_commission' => 5000,
        ]);

        return $run->fresh();
    }

    #[Test]
    public function hr_can_reach_a_run_without_being_able_to_compute_one(): void
    {
        $run = $this->finalizedRun();

        $this->actingAs($this->hrWhoOnlyReleases())
            ->get(route('commissions.run-show', $run))
            ->assertOk();
    }

    #[Test]
    public function hr_can_send_the_slips(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('commissions.run-show', ['run' => $run])
            ->call('send')
            ->assertSet('errorMessage', null);

        Notification::assertSentTimes(CommissionSlipReady::class, 1);
    }

    #[Test]
    public function hr_is_not_offered_the_controls_that_change_figures(): void
    {
        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('commissions.run-show', ['run' => $run])
            ->assertViewHas('canManageRuns', false)
            ->assertViewHas('canSendSlips', true)
            ->assertViewHas('canFinalize', false);
    }

    #[Test]
    public function hr_cannot_recompute_even_by_calling_the_action(): void
    {
        // A recompute reads the CRM again and rewrites every figure on the run.
        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('commissions.run-show', ['run' => $run])
            ->call('compute')
            ->assertForbidden();
    }

    #[Test]
    public function hr_cannot_change_who_is_on_the_run(): void
    {
        // Who is on a run decides who is paid commission at all.
        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('commissions.run-show', ['run' => $run])
            ->call('chooseAgents')
            ->assertForbidden();
    }

    #[Test]
    public function hr_cannot_reopen_a_locked_run(): void
    {
        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('commissions.run-show', ['run' => $run])
            ->call('unlock')
            ->assertForbidden();
    }

    #[Test]
    public function hr_cannot_open_a_new_run(): void
    {
        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('commissions.runs')
            ->call('openForm')
            ->assertForbidden();
    }

    #[Test]
    public function whoever_finalizes_can_still_send(): void
    {
        // Nothing about today's arrangement breaks on deploy.
        Notification::fake();

        $run = $this->finalizedRun();

        Livewire::actingAs($this->commissionManager())
            ->test('commissions.run-show', ['run' => $run])
            ->assertViewHas('canSendSlips', true)
            ->call('send')
            ->assertSet('errorMessage', null);

        Notification::assertSentTimes(CommissionSlipReady::class, 1);
    }

    #[Test]
    public function somebody_with_neither_permission_cannot_open_commissions(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $this->actingAs($employee)->get('/commissions')->assertForbidden();
    }
}
