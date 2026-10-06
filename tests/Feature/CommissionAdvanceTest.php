<?php

namespace Tests\Feature;

use App\Models\CommissionAdvance;
use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Services\Commission\CommissionAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commission advanced before it is earned, and taken back out of the runs.
 *
 * The balance is never stored — it is the principal less what the runs took.
 * A stored balance and a list of repayments eventually disagree, and then
 * nobody can say what the agent actually owes.
 */
class CommissionAdvanceTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $agent;

    protected CommissionAdvanceService $advances;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Employee::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $this->advances = new CommissionAdvanceService;
    }

    protected function advance(float $principal = 10000, ?float $perRun = 2500): CommissionAdvance
    {
        return $this->advances->open($this->agent, $principal, $perRun, '2026-09-01', 'Advance against October commission.');
    }

    protected function slip(float $net, string $month = '2026-10'): CommissionSlip
    {
        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => $month . '-01',
            'period_end' => date('Y-m-t', strtotime($month . '-01')),
            'label' => date('F Y', strtotime($month . '-01')),
            'status' => 'computed',
            'agent_count' => 1,
        ]);

        return CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => $this->agent->id,
            'agent_name' => $this->agent->fullName(),
            'net_commission' => $net,
        ]);
    }

    #[Test]
    public function an_advance_starts_owing_all_of_it(): void
    {
        $advance = $this->advance();

        $this->assertSame(10000.00, $advance->balance());
        $this->assertStringStartsWith('CV-CA-', $advance->reference_no);
    }

    #[Test]
    public function a_run_takes_one_instalment(): void
    {
        $advance = $this->advance();
        $slip = $this->slip(20000);

        $payment = $this->advances->applyToSlip($advance, $slip, '2026-10-31', 20000);

        $this->assertSame('2500.00', $payment->amount);
        $this->assertSame(7500.00, $advance->fresh()->balance());
    }

    #[Test]
    public function the_instalment_shrinks_to_what_the_slip_can_bear(): void
    {
        // A thin month does not drive the slip negative; the rest waits.
        $advance = $this->advance();
        $slip = $this->slip(900);

        $payment = $this->advances->applyToSlip($advance, $slip, '2026-10-31', 900);

        $this->assertSame('900.00', $payment->amount);
        $this->assertSame(9100.00, $advance->fresh()->balance());
    }

    #[Test]
    public function a_month_with_no_commission_costs_them_nothing(): void
    {
        $advance = $this->advance();

        $this->assertNull($this->advances->applyToSlip($advance, $this->slip(0), '2026-10-31', 0));
        $this->assertSame(10000.00, $advance->fresh()->balance());
    }

    #[Test]
    public function recomputing_the_same_run_does_not_charge_twice(): void
    {
        $advance = $this->advance();
        $slip = $this->slip(20000);

        $this->advances->applyToSlip($advance, $slip, '2026-10-31', 20000);
        $this->advances->applyToSlip($advance->fresh(), $slip, '2026-10-31', 20000);

        $this->assertSame(1, $advance->fresh()->payments()->count());
        $this->assertSame(7500.00, $advance->fresh()->balance());
    }

    #[Test]
    public function cancelling_a_run_gives_the_debt_back(): void
    {
        $advance = $this->advance();
        $slip = $this->slip(20000);

        $this->advances->applyToSlip($advance, $slip, '2026-10-31', 20000);
        $this->advances->reverseForRun($slip->commission_run_id);

        $this->assertSame(10000.00, $advance->fresh()->balance());
        $this->assertSame(CommissionAdvance::ACTIVE, $advance->fresh()->status);
    }

    #[Test]
    public function it_closes_itself_once_it_is_repaid(): void
    {
        $advance = $this->advance(principal: 2000, perRun: 2000);

        $this->advances->applyToSlip($advance, $this->slip(20000), '2026-10-31', 20000);

        $advance = $advance->fresh();

        $this->assertSame(0.0, $advance->balance());
        $this->assertSame(CommissionAdvance::PAID, $advance->status);
        $this->assertSame('Fully repaid', $advance->statusLabel());
    }

    #[Test]
    public function an_advance_on_hold_collects_nothing(): void
    {
        // Pausing collection is not forgiving the debt.
        $advance = $this->advance();
        $this->advances->setHold($advance, true);

        $this->assertNull($this->advances->applyToSlip($advance->fresh(), $this->slip(20000), '2026-10-31', 20000));
        $this->assertSame(10000.00, $advance->fresh()->balance());
    }

    #[Test]
    public function with_no_instalment_set_it_takes_whatever_it_can(): void
    {
        $advance = $this->advance(principal: 5000, perRun: null);

        $payment = $this->advances->applyToSlip($advance, $this->slip(20000), '2026-10-31', 20000);

        $this->assertSame('5000.00', $payment->amount);
        $this->assertSame(CommissionAdvance::PAID, $advance->fresh()->status);
    }

    #[Test]
    public function cancelling_the_advance_keeps_what_was_already_repaid(): void
    {
        $advance = $this->advance();
        $this->advances->applyToSlip($advance, $this->slip(20000), '2026-10-31', 20000);

        $this->advances->cancel($advance->fresh());

        $this->assertSame(CommissionAdvance::CANCELLED, $advance->fresh()->status);
        $this->assertSame(1, $advance->fresh()->payments()->count());
    }
}
