<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use App\Services\Commission\CommissionRunService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Amounts added to a commission slip by hand.
 *
 * Everything else on a slip is read fresh from the CRM on every compute, so a
 * bonus agreed verbally would be wiped each time the run was recomputed. These
 * are the one part that survives it.
 */
class CommissionSlipAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $ceo;

    protected CommissionSlip $slip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->ceo = User::factory()->create(['name' => 'Jay Cris']);
        $this->ceo->assignRole('Admin');
        $this->ceo->givePermissionTo(['commissions.runs.manage', 'commissions.runs.finalize']);

        $agent = Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'label' => 'September 2026',
            'status' => 'computed',
            'agent_count' => 1,
        ]);

        $this->slip = CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => $agent->id,
            'agent_name' => $agent->fullName(),
            'php_total' => 50000,
            'card_hold_amount' => 5000,
            'net_commission' => 45000,
        ]);
    }

    protected function panel()
    {
        return Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $this->slip->commissionRun])
            ->call('viewSlip', $this->slip->id);
    }

    #[Test]
    public function a_bonus_added_by_hand_raises_the_net(): void
    {
        $this->panel()
            ->set('adjustmentType', 'earning')
            ->set('adjustmentLabel', 'Top seller bonus')
            ->set('adjustmentAmount', '2500')
            ->call('addAdjustment')
            ->assertHasNoErrors();

        $slip = $this->slip->fresh();

        $this->assertSame('2500.00', $slip->adjustments_earning);
        $this->assertSame('47500.00', $slip->net_commission);
        $this->assertSame('Jay Cris', $slip->adjustments->first()->created_by_name);
    }

    #[Test]
    public function a_deduction_added_by_hand_lowers_the_net(): void
    {
        $this->panel()
            ->set('adjustmentType', 'deduction')
            ->set('adjustmentLabel', 'Equipment damage')
            ->set('adjustmentAmount', '1500')
            ->call('addAdjustment');

        $this->assertSame('43500.00', $this->slip->fresh()->net_commission);
    }

    #[Test]
    public function net_never_goes_below_nothing(): void
    {
        // An agent cannot be made to owe commission. A debt bigger than the
        // month belongs on an advance, which carries across runs.
        $this->panel()
            ->set('adjustmentType', 'deduction')
            ->set('adjustmentLabel', 'Overpayment')
            ->set('adjustmentAmount', '90000')
            ->call('addAdjustment');

        $this->assertSame('0.00', $this->slip->fresh()->net_commission);
    }

    #[Test]
    public function removing_it_puts_the_net_back(): void
    {
        $this->panel()
            ->set('adjustmentType', 'earning')
            ->set('adjustmentLabel', 'Top seller bonus')
            ->set('adjustmentAmount', '2500')
            ->call('addAdjustment');

        $id = $this->slip->fresh()->adjustments->first()->id;

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $this->slip->commissionRun])
            ->call('viewSlip', $this->slip->id)
            ->call('removeAdjustment', $id);

        $this->assertSame('45000.00', $this->slip->fresh()->net_commission);
        $this->assertSame('0.00', $this->slip->fresh()->adjustments_earning);
    }

    #[Test]
    public function a_locked_run_cannot_be_edited(): void
    {
        $this->slip->commissionRun->update(['status' => 'finalized', 'finalized_at' => now()]);

        $this->panel()
            ->set('adjustmentType', 'earning')
            ->set('adjustmentLabel', 'Late bonus')
            ->set('adjustmentAmount', '1000')
            ->call('addAdjustment')
            ->assertStatus(422);

        $this->assertSame(0, $this->slip->fresh()->adjustments()->count());
    }

    #[Test]
    public function somebody_who_only_releases_commission_cannot_add_one(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('Admin');
        $hr->givePermissionTo('commissions.slips.send');

        Livewire::actingAs($hr)
            ->test('commissions.run-show', ['run' => $this->slip->commissionRun])
            ->call('viewSlip', $this->slip->id)
            ->set('adjustmentLabel', 'Bonus')
            ->set('adjustmentAmount', '1000')
            ->call('addAdjustment')
            ->assertForbidden();

        $this->assertSame(0, $this->slip->fresh()->adjustments()->count());
    }

    #[Test]
    public function the_description_and_amount_are_required(): void
    {
        $this->panel()
            ->set('adjustmentLabel', '')
            ->set('adjustmentAmount', '')
            ->call('addAdjustment')
            ->assertHasErrors(['adjustmentLabel', 'adjustmentAmount']);
    }
}
