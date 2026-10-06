<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Throwing away a commission run that has not been finalized.
 *
 * The month is unique per run type, so a run opened by mistake — or computed
 * against the wrong agents — blocks the corrected one forever until it is
 * deleted. The service could already do it; nothing offered it.
 */
class CommissionRunCancelTest extends TestCase
{
    use RefreshDatabase;

    protected User $ceo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ceo = User::factory()->create();
        $this->ceo->assignRole('Admin');
        $this->ceo->givePermissionTo(['commissions.runs.manage', 'commissions.runs.finalize']);
    }

    protected function makeRun(string $status = 'computed'): CommissionRun
    {
        $employee = Employee::factory()->create(['commission_frequency' => 'monthly']);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'label' => 'September 2026',
            'status' => $status,
            'agent_count' => 1,
            'finalized_at' => in_array($status, ['finalized', 'sent'], true) ? now() : null,
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
    public function a_computed_run_can_be_cancelled_and_its_slips_go_with_it(): void
    {
        $run = $this->makeRun();

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $run])
            ->assertSee('Cancel Run')
            ->call('cancelRun')
            ->assertRedirect(route('commissions.runs'));

        $this->assertSame(0, CommissionRun::count());
        $this->assertSame(0, CommissionSlip::count());
    }

    #[Test]
    public function the_month_is_free_to_run_again_afterwards(): void
    {
        // The point of deleting rather than marking it cancelled: the unique
        // key on run type and period would otherwise block the corrected run.
        $run = $this->makeRun();

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $run])
            ->call('cancelRun');

        $this->assertSame(1, CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'label' => 'September 2026',
            'status' => 'draft',
        ])->exists ? 1 : 0);
    }

    #[Test]
    public function a_finalized_run_cannot_be_cancelled(): void
    {
        $run = $this->makeRun('finalized');

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $run])
            ->assertDontSee('Cancel Run')
            ->call('cancelRun')
            ->assertForbidden();

        $this->assertSame(1, CommissionRun::count());
    }

    #[Test]
    public function somebody_who_only_sends_slips_cannot_cancel_a_run(): void
    {
        $run = $this->makeRun();

        $hr = User::factory()->create();
        $hr->assignRole('Admin');
        $hr->givePermissionTo('commissions.slips.send');

        Livewire::actingAs($hr)
            ->test('commissions.run-show', ['run' => $run])
            ->assertDontSee('Cancel Run')
            ->call('cancelRun')
            ->assertForbidden();

        $this->assertSame(1, CommissionRun::count());
    }
}
