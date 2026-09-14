<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\PayslipReady;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * Running the payroll and releasing it are two different jobs.
 *
 * The CEO or COO locks the figures; HR sends them out. Before this, sending
 * meant holding payroll.runs.manage — which is also the permission to open a
 * run, recompute it and change what somebody is paid. Handing that to HR to let
 * them press one button was the only option, and it was far too much.
 *
 * Whoever runs the payroll can still release it. Withholding that would break
 * the existing arrangement and buy nothing, since they can download the
 * register and send it by hand regardless.
 */
class PayslipReleasePermissionTest extends PayrollTestCase
{
    protected function hrWhoOnlyReleases(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo('payroll.payslips.send');

        return $user;
    }

    protected function payrollManager(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $user->givePermissionTo('payroll.runs.manage');
        $user->givePermissionTo('payroll.runs.finalize');

        return $user;
    }

    /** A finalized run with one payslip nobody has been told about yet. */
    protected function finalizedRun(): PayrollRun
    {
        $this->seed(RoleSeeder::class);

        $employee = $this->makeEmployee(salary: 30000);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $manager = $this->payrollManager();
        $period = $this->period();

        $service = app(\App\Services\Payroll\PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $manager);
        $service->finalize($run->fresh(), $manager);

        return $run->fresh();
    }

    #[Test]
    public function hr_can_reach_a_run_without_being_able_to_run_payroll(): void
    {
        // The door has to open, or there is no button to press.
        $run = $this->finalizedRun();

        $this->actingAs($this->hrWhoOnlyReleases())
            ->get(route('payroll.show', $run))
            ->assertOk();
    }

    #[Test]
    public function hr_can_send_the_payslips(): void
    {
        Notification::fake();

        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('payroll.show', ['run' => $run])
            ->call('sendPayslips')
            ->assertSet('errorMessage', null);

        Notification::assertSentTimes(PayslipReady::class, 1);
    }

    #[Test]
    public function hr_is_not_offered_the_controls_that_change_money(): void
    {
        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('payroll.show', ['run' => $run])
            ->assertViewHas('canManageRuns', false)
            ->assertViewHas('canSendPayslips', true)
            ->assertDontSee('wire:click="compute"', false)
            ->assertSee('wire:click="sendPayslips"', false);
    }

    #[Test]
    public function hr_cannot_recompute_even_by_calling_the_action(): void
    {
        /*
         * The buttons not being rendered is not the same as the method being
         * closed. A recompute on a finalized run is the path from "send the
         * payslips" to "change what everybody is paid".
         */
        $run = $this->finalizedRun();

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('payroll.show', ['run' => $run])
            ->call('compute')
            ->assertSet('errorMessage', 'You cannot compute a payroll run.');
    }

    #[Test]
    public function hr_cannot_start_or_discard_a_run(): void
    {
        $this->seed(RoleSeeder::class);

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('payroll.index')
            ->assertViewHas('canManageRuns', false)
            ->call('openForm')
            ->assertForbidden();
    }

    #[Test]
    public function hr_cannot_download_the_whole_company_register(): void
    {
        // One person's payslip is their business. Every person's pay in one
        // spreadsheet is a different thing entirely.
        $run = $this->finalizedRun();

        $this->actingAs($this->hrWhoOnlyReleases())
            ->get(route('payroll.export', $run))
            ->assertForbidden();
    }

    #[Test]
    public function whoever_runs_the_payroll_can_still_release_it(): void
    {
        // Nothing about today's arrangement breaks on deploy.
        Notification::fake();

        $run = $this->finalizedRun();

        Livewire::actingAs($this->payrollManager())
            ->test('payroll.show', ['run' => $run])
            ->assertViewHas('canSendPayslips', true)
            ->call('sendPayslips')
            ->assertSet('errorMessage', null);

        Notification::assertSentTimes(PayslipReady::class, 1);
    }

    #[Test]
    public function nothing_goes_out_before_the_figures_are_locked(): void
    {
        /*
         * The rule that makes this delegation safe. An employee emailed a draft
         * figure who then sees a different one has every reason to distrust the
         * next payslip.
         */
        Notification::fake();
        $this->seed(RoleSeeder::class);

        $employee = $this->makeEmployee(salary: 30000);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $manager = $this->payrollManager();
        $period = $this->period();

        $service = app(\App\Services\Payroll\PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $manager);

        Livewire::actingAs($this->hrWhoOnlyReleases())
            ->test('payroll.show', ['run' => $run->fresh()])
            ->call('sendPayslips')
            ->assertSet('errorMessage', 'Payslips can only be sent once the payroll is finalized.');

        Notification::assertNothingSent();
    }

    #[Test]
    public function somebody_with_neither_permission_cannot_open_payroll_at_all(): void
    {
        $this->seed(RoleSeeder::class);

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $this->actingAs($employee)->get('/payroll')->assertForbidden();
    }
}
