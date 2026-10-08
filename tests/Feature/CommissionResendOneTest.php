<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\CommissionSlipReady;
use App\Services\Commission\CommissionRunService;
use App\Services\Commission\CommissionSlipNotifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Correcting one agent after the commission run has gone out.
 *
 * Recomputing used to withdraw every slip in the run, so adding one agent who
 * had not been linked to the CRM meant emailing the other fifteen a second
 * copy of figures that had not moved. Agents learn to ignore a slip that
 * arrives twice.
 */
class CommissionResendOneTest extends TestCase
{
    use RefreshDatabase;

    protected User $ceo;

    protected CommissionRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ceo = User::factory()->create();
        $this->ceo->assignRole('Admin');
        $this->ceo->givePermissionTo(['commissions.runs.manage', 'commissions.runs.finalize', 'commissions.slips.send']);

        $this->run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'label' => 'September 2026',
            'status' => 'finalized',
            'finalized_at' => now(),
            'agent_count' => 2,
        ]);
    }

    protected function slipFor(string $last, float $net, bool $sent = true): CommissionSlip
    {
        $agent = Employee::factory()->create(['first_name' => 'Agent', 'last_name' => $last]);
        $agent->forceFill(['user_id' => User::factory()->create()->id])->save();

        return CommissionSlip::create([
            'commission_run_id' => $this->run->id,
            'employee_id' => $agent->id,
            'agent_name' => $agent->fullName(),
            'php_total' => $net,
            'net_commission' => $net,
            'notified_at' => $sent ? now()->subDay() : null,
        ]);
    }

    #[Test]
    public function one_slip_goes_to_one_agent(): void
    {
        Notification::fake();

        $maria = $this->slipFor('Santos', 50000);
        $rio = $this->slipFor('Moreno', 40000);

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $this->run])
            ->call('sendOneSlip', $maria->id)
            ->assertSee('Slip sent again to Agent Santos.');

        Notification::assertSentToTimes($maria->employee->user, CommissionSlipReady::class, 1);
        Notification::assertNotSentTo($rio->employee->user, CommissionSlipReady::class);
    }

    #[Test]
    public function a_slip_whose_figures_did_not_move_keeps_its_stamp(): void
    {
        // The whole point: the other agents were told the truth already.
        $unchanged = $this->slipFor('Moreno', 40000);
        $service = app(CommissionRunService::class);

        $reflection = new \ReflectionMethod($service, 'withdrawChangedSlips');
        $snapshot = (new \ReflectionMethod($service, 'figureSnapshot'))->invoke($service, $this->run);

        $reflection->invoke($service, $this->run, $snapshot);

        $this->assertNotNull($unchanged->fresh()->notified_at);
    }

    #[Test]
    public function a_slip_whose_figures_moved_is_withdrawn(): void
    {
        $changed = $this->slipFor('Santos', 50000);
        $service = app(CommissionRunService::class);

        $snapshot = (new \ReflectionMethod($service, 'figureSnapshot'))->invoke($service, $this->run);

        // A correction lands between the snapshot and the comparison.
        $changed->update(['net_commission' => 47500]);

        (new \ReflectionMethod($service, 'withdrawChangedSlips'))->invoke($service, $this->run, $snapshot);

        $this->assertNull($changed->fresh()->notified_at);
    }

    #[Test]
    public function the_agent_who_was_added_late_is_the_only_one_waiting(): void
    {
        $this->slipFor('Santos', 50000);
        $this->slipFor('Moreno', 40000);
        $added = $this->slipFor('Cruz', 12000, sent: false);

        $pending = app(CommissionSlipNotifier::class)->pendingCount($this->run);

        $this->assertSame(1, $pending);
        $this->assertNull($added->fresh()->notified_at);
    }

    #[Test]
    public function nothing_is_sent_before_the_run_is_finalized(): void
    {
        Notification::fake();

        $this->run->update(['status' => 'computed', 'finalized_at' => null]);
        $slip = $this->slipFor('Santos', 50000, sent: false);

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $this->run->fresh()])
            ->call('sendOneSlip', $slip->id)
            ->assertStatus(422);

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_agent_with_no_login_is_said_so_rather_than_failing_quietly(): void
    {
        Notification::fake();

        $slip = $this->slipFor('Santos', 50000, sent: false);
        $slip->employee->forceFill(['user_id' => null])->save();

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $this->run])
            ->call('sendOneSlip', $slip->id)
            ->assertSee('has no PHREMS login yet');

        $this->assertNull($slip->fresh()->notified_at);
    }

    #[Test]
    public function somebody_who_may_not_release_commission_cannot_send_one(): void
    {
        Notification::fake();

        $slip = $this->slipFor('Santos', 50000, sent: false);

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        Livewire::actingAs($employee)
            ->test('commissions.run-show', ['run' => $this->run])
            ->call('sendOneSlip', $slip->id)
            ->assertForbidden();

        Notification::assertNothingSent();
    }
}
