<?php

namespace Tests\Feature\Payroll;

use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cancelling a payroll run after it has been computed.
 *
 * Before this, only a draft could be thrown away, so a run computed for the
 * wrong cutoff or with the wrong people in it could only be recomputed, never
 * removed. Finalized and paid runs still cannot be cancelled — they have to be
 * reopened first, which asks for a reason.
 */
class PayrollCancelTest extends PayrollTestCase
{
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Admin');
        $this->manager->givePermissionTo(['payroll.runs.manage', 'payroll.runs.finalize']);
    }

    protected function computedRun(): PayrollRun
    {
        $this->makeEmployee(salary: 30000);
        $period = $this->period();

        $service = app(PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->manager);

        return $run->fresh();
    }

    #[Test]
    public function a_computed_run_can_be_cancelled_from_its_page(): void
    {
        $run = $this->computedRun();
        $this->assertSame('computed', $run->status);
        $this->assertSame(1, Payslip::count());

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run])
            ->assertSee('Cancel Run')
            ->call('cancelRun')
            ->assertSet('errorMessage', null)
            ->assertRedirect(route('payroll.index'));

        $this->assertSame(0, PayrollRun::count());
        $this->assertSame(0, Payslip::count());
    }

    #[Test]
    public function a_computed_run_can_be_cancelled_from_the_list(): void
    {
        $run = $this->computedRun();

        Livewire::actingAs($this->manager)
            ->test('payroll.index')
            ->assertSee('Cancel')
            ->call('cancelRun', $run->id)
            ->assertSee('cancelled');

        $this->assertSame(0, PayrollRun::count());
    }

    #[Test]
    public function the_same_cutoff_can_be_started_again_after_cancelling(): void
    {
        $run = $this->computedRun();

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run])
            ->call('cancelRun');

        $this->assertSame('computed', $this->computedRun()->status);
    }

    #[Test]
    public function a_finalized_run_cannot_be_cancelled(): void
    {
        $run = $this->computedRun();
        app(PayrollService::class)->finalize($run, $this->manager);

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run->fresh()])
            ->assertDontSee('Cancel Run')
            ->call('cancelRun')
            ->assertSet('errorMessage', 'A finalized or paid payroll run cannot be cancelled.');

        $this->assertSame(1, PayrollRun::count());
    }

    #[Test]
    public function somebody_who_only_sends_payslips_cannot_cancel_a_run(): void
    {
        $run = $this->computedRun();

        $hr = User::factory()->create();
        $hr->assignRole('Admin');
        $hr->givePermissionTo('payroll.payslips.send');

        Livewire::actingAs($hr)
            ->test('payroll.show', ['run' => $run])
            ->assertDontSee('Cancel Run')
            ->call('cancelRun')
            ->assertSet('errorMessage', 'You cannot cancel a payroll run.');

        $this->assertSame(1, PayrollRun::count());
    }
}
