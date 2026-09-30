<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayslipReady;
use App\Services\Payroll\PayrollService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sending one payslip to one person.
 *
 * The bulk send skips anybody already told, which is what stops a second click
 * emailing a hundred people twice. That left no way to reach the one employee
 * whose figure was corrected after the run went out — the only options were to
 * email nobody or to email everybody.
 */
class SendOnePayslipTest extends PayrollTestCase
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

    /** A finalized run with two employees who both have logins. */
    protected function finalizedRun(): \App\Models\PayrollRun
    {
        foreach ([['Maria', 'Santos'], ['Rio', 'Moreno']] as [$first, $last]) {
            $employee = $this->makeEmployee(20000, 'day', ['first_name' => $first, 'last_name' => $last]);
            $employee->forceFill(['user_id' => User::factory()->create()->id])->save();
        }

        $period = $this->period();
        $service = app(PayrollService::class);

        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->manager);
        $service->finalize($run->fresh(), $this->manager);

        return $run->fresh();
    }

    protected function payslipFor(string $lastName): Payslip
    {
        $employee = Employee::where('last_name', $lastName)->sole();

        return Payslip::where('employee_id', $employee->id)->sole();
    }

    #[Test]
    public function one_payslip_goes_to_one_person(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();
        $payslip = $this->payslipFor('Santos');

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run])
            ->call('sendOnePayslip', $payslip->id)
            ->assertSet('errorMessage', null)
            ->assertSee('Payslip sent to Maria Santos.');

        Notification::assertSentToTimes($payslip->employee->user, PayslipReady::class, 1);
        Notification::assertNotSentTo($this->payslipFor('Moreno')->employee->user, PayslipReady::class);
        $this->assertNotNull($payslip->fresh()->notified_at);
    }

    #[Test]
    public function a_corrected_payslip_can_be_sent_again_without_emailing_everybody(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();
        $payslip = $this->payslipFor('Santos');

        // Everybody has had theirs.
        Livewire::actingAs($this->manager)->test('payroll.show', ['run' => $run])->call('sendPayslips');
        Notification::assertSentToTimes($payslip->employee->user, PayslipReady::class, 1);

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run])
            ->call('sendOnePayslip', $payslip->id)
            ->assertSee('Payslip sent again to Maria Santos.');

        Notification::assertSentToTimes($payslip->employee->user, PayslipReady::class, 2);
        // The other employee still has exactly the one from the bulk send.
        Notification::assertSentToTimes($this->payslipFor('Moreno')->employee->user, PayslipReady::class, 1);
    }

    #[Test]
    public function hr_who_only_releases_pay_can_send_one(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();

        $hr = User::factory()->create();
        $hr->assignRole('Admin');
        $hr->givePermissionTo('payroll.payslips.send');

        Livewire::actingAs($hr)
            ->test('payroll.show', ['run' => $run])
            ->call('sendOnePayslip', $this->payslipFor('Santos')->id)
            ->assertSet('errorMessage', null);

        Notification::assertSentTimes(PayslipReady::class, 1);
    }

    #[Test]
    public function somebody_who_may_not_release_pay_cannot_send_one(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        Livewire::actingAs($employee)
            ->test('payroll.show', ['run' => $run])
            ->call('sendOnePayslip', $this->payslipFor('Santos')->id)
            ->assertSet('errorMessage', 'You cannot send payslips to employees.');

        // Finalizing tells whoever may release pay, which is a different
        // notification and nothing to do with this.
        Notification::assertNotSentTo($this->payslipFor('Santos')->employee->user, PayslipReady::class);
    }

    #[Test]
    public function nothing_goes_out_before_the_figures_are_locked(): void
    {
        Notification::fake();

        $this->makeEmployee(20000, 'day', ['first_name' => 'Maria', 'last_name' => 'Santos'])
            ->forceFill(['user_id' => User::factory()->create()->id])->save();

        $period = $this->period();
        $service = app(PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->manager);

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run->fresh()])
            ->call('sendOnePayslip', $this->payslipFor('Santos')->id)
            ->assertSet('errorMessage', 'Payslips can only be sent once the payroll is finalized.');

        Notification::assertNothingSent();
    }

    #[Test]
    public function an_employee_with_no_login_is_said_so_rather_than_failing_quietly(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();
        $payslip = $this->payslipFor('Moreno');
        $payslip->employee->forceFill(['user_id' => null])->save();

        Livewire::actingAs($this->manager)
            ->test('payroll.show', ['run' => $run])
            ->call('sendOnePayslip', $payslip->id)
            ->assertSee('has no PHREMS login yet');

        $this->assertNull($payslip->fresh()->notified_at);
    }
}
