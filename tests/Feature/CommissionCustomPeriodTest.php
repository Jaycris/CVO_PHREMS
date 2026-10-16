<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\CommissionSlipLine;
use App\Models\Employee;
use App\Models\User;
use App\Services\Commission\CommissionRunService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A run for part of a month, against a CRM that only understands months.
 *
 * The agreed CRM API takes `month` and nothing finer. A custom range sends
 * `from` and `to` as well, hoping the CRM narrows it — and a CRM that does not
 * answers with the whole month, totals included. Those totals are what an
 * agent is paid, so an Oct 1–10 run would hand over all of October.
 *
 * Nothing here can make the CRM behave. What it can do is refuse to pretend.
 */
class CommissionCustomPeriodTest extends TestCase
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

    /**
     * @param  list<string>  $soldDates  what the CRM sent back
     */
    protected function runWith(string $start, string $end, array $soldDates): CommissionRun
    {
        $employee = Employee::factory()->create(['commission_frequency' => 'monthly']);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $run = CommissionRun::create([
            'run_type' => 'custom',
            'period_start' => $start,
            'period_end' => $end,
            'status' => 'computed',
            'agent_count' => 1,
        ]);

        $slip = CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => $employee->id,
            'agent_name' => $employee->fullName(),
            'net_commission' => 50000,
        ]);

        foreach ($soldDates as $index => $date) {
            CommissionSlipLine::create([
                'commission_slip_id' => $slip->id,
                'sort_order' => $index,
                'sold_date' => $date,
                'brand' => 'Inkspire Media House',
                'net_commission' => 1000,
            ]);
        }

        return $run->fresh();
    }

    #[Test]
    public function sales_from_after_the_period_are_spotted(): void
    {
        $run = $this->runWith('2026-10-01', '2026-10-10', [
            '2026-10-09', '2026-10-14', '2026-10-16',
        ]);

        $strays = $run->salesOutsideThePeriod();

        $this->assertCount(2, $strays);
        $this->assertSame('2026-10-14', $strays->min('sold_date'));
        $this->assertSame('2026-10-16', $strays->max('sold_date'));
    }

    #[Test]
    public function sales_from_before_the_period_count_too(): void
    {
        $run = $this->runWith('2026-10-11', '2026-10-20', ['2026-10-02', '2026-10-15']);

        $this->assertCount(1, $run->salesOutsideThePeriod());
    }

    #[Test]
    public function a_whole_month_run_is_never_flagged(): void
    {
        // A month is the one thing the CRM does narrow correctly, so every
        // date it returns belongs by definition.
        $run = $this->runWith('2026-10-01', '2026-10-31', [
            '2026-10-01', '2026-10-16', '2026-10-31',
        ]);

        $this->assertTrue($run->coversWholeMonth());
        $this->assertCount(0, $run->salesOutsideThePeriod());
    }

    #[Test]
    public function finalizing_is_refused_while_the_figures_are_for_the_whole_month(): void
    {
        $run = $this->runWith('2026-10-01', '2026-10-10', ['2026-10-09', '2026-10-16']);

        try {
            app(CommissionRunService::class)->finalize($run, $this->ceo);
            $this->fail('the run was finalized with a month of figures behind a ten-day period');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertStringContainsString('whole month', $e->getMessage());
        }

        $this->assertSame('computed', $run->fresh()->status);
    }

    #[Test]
    public function a_clean_custom_period_finalizes_normally(): void
    {
        $run = $this->runWith('2026-10-01', '2026-10-10', ['2026-10-02', '2026-10-09']);

        app(CommissionRunService::class)->finalize($run, $this->ceo);

        $this->assertSame('finalized', $run->fresh()->status);
    }

    #[Test]
    public function the_run_page_says_so_before_anybody_presses_finalize(): void
    {
        $run = $this->runWith('2026-10-01', '2026-10-10', ['2026-10-09', '2026-10-14']);

        Livewire::actingAs($this->ceo)
            ->test('commissions.run-show', ['run' => $run])
            ->assertSee('for the whole month')
            ->assertSee('2026-10-14');
    }
}
