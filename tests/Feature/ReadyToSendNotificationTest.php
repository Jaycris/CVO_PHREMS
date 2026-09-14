<?php

namespace Tests\Feature;

use App\Models\CommissionRun;
use App\Models\CommissionSlip;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\CommissionReadyToSend;
use App\Notifications\PayrollReadyToSend;
use App\Services\Commission\CommissionRunService;
use App\Services\Payroll\PayrollService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payroll\PayrollTestCase;

/**
 * Closing the handoff between locking a run and sending it out.
 *
 * Splitting the permissions left a gap: somebody who may only release payslips
 * has no reason to open the payroll screen, because there is nothing else there
 * they can do. Without a message, the CEO finalizes and then has to remember to
 * tell HR by hand — the step that gets forgotten on the one afternoon everybody
 * is busy, and the employees wait.
 *
 * Only holders of the send permission are told. Anyone else would be reading
 * about work they cannot do.
 */
class ReadyToSendNotificationTest extends PayrollTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    protected function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    protected function computedPayrollRun(User $manager)
    {
        $employee = $this->makeEmployee(salary: 30000);
        $employee->forceFill(['user_id' => User::factory()->create()->id])->save();

        $period = $this->period();
        $service = app(PayrollService::class);

        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $manager);

        return $run->fresh();
    }

    #[Test]
    public function hr_is_told_when_payroll_is_locked(): void
    {
        Notification::fake();

        $hr = $this->userWith('payroll.payslips.send');
        $manager = $this->userWith('payroll.runs.manage', 'payroll.runs.finalize');

        $run = $this->computedPayrollRun($manager);

        app(PayrollService::class)->finalize($run, $manager);

        Notification::assertSentTo($hr, PayrollReadyToSend::class);
    }

    #[Test]
    public function nobody_without_the_send_permission_is_told(): void
    {
        /*
         * The whole point of asking. A notification about work you are not
         * allowed to do is noise, and it is also a quiet leak of what the
         * company is doing with its payroll to people outside that job.
         */
        Notification::fake();

        $manager = $this->userWith('payroll.runs.manage', 'payroll.runs.finalize');
        $bystander = $this->userWith('employees.manage');
        $plainEmployee = User::factory()->create();
        $plainEmployee->assignRole('Employee');

        $run = $this->computedPayrollRun($manager);

        app(PayrollService::class)->finalize($run, $manager);

        Notification::assertNotSentTo($bystander, PayrollReadyToSend::class);
        Notification::assertNotSentTo($plainEmployee, PayrollReadyToSend::class);
    }

    #[Test]
    public function whoever_locked_the_run_is_not_told_about_their_own_click(): void
    {
        Notification::fake();

        // Holds both, so without the exclusion they would be telling themselves.
        $both = $this->userWith('payroll.runs.manage', 'payroll.runs.finalize', 'payroll.payslips.send');

        $run = $this->computedPayrollRun($both);

        app(PayrollService::class)->finalize($run, $both);

        Notification::assertNotSentTo($both, PayrollReadyToSend::class);
    }

    #[Test]
    public function nothing_is_sent_when_there_is_nothing_left_to_release(): void
    {
        // A run whose payslips have all gone out already has nothing to ask
        // anybody for.
        Notification::fake();

        $hr = $this->userWith('payroll.payslips.send');
        $manager = $this->userWith('payroll.runs.manage', 'payroll.runs.finalize');

        $run = $this->computedPayrollRun($manager);
        $run->payslips()->update(['notified_at' => now()]);

        app(PayrollService::class)->finalize($run->fresh(), $manager);

        Notification::assertNotSentTo($hr, PayrollReadyToSend::class);
    }

    #[Test]
    public function hr_is_told_when_commission_is_locked(): void
    {
        Notification::fake();

        $hr = $this->userWith('commissions.slips.send');
        $manager = $this->userWith('commissions.runs.manage', 'commissions.runs.finalize');

        $employee = Employee::factory()->create(['commission_frequency' => 'monthly']);

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'label' => 'August 2026',
            'status' => 'computed',
            'agent_count' => 1,
        ]);

        CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => $employee->id,
            'agent_name' => $employee->fullName(),
            'net_commission' => 5000,
        ]);

        app(CommissionRunService::class)->finalize($run->fresh(), $manager);

        Notification::assertSentTo($hr, CommissionReadyToSend::class);
    }

    #[Test]
    public function an_agent_the_crm_could_not_be_read_for_is_not_counted_as_ready(): void
    {
        /*
         * A slip with a fetch error has no figures on it and is never sent, so
         * counting it would tell HR there are three slips waiting when only two
         * can go anywhere.
         */
        Notification::fake();

        $hr = $this->userWith('commissions.slips.send');
        $manager = $this->userWith('commissions.runs.manage', 'commissions.runs.finalize');

        $run = CommissionRun::create([
            'run_type' => 'monthly',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'label' => 'August 2026',
            'status' => 'computed',
            'agent_count' => 2,
            'failed_count' => 1,
        ]);

        CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => Employee::factory()->create()->id,
            'agent_name' => 'Worked',
            'net_commission' => 5000,
        ]);

        CommissionSlip::create([
            'commission_run_id' => $run->id,
            'employee_id' => Employee::factory()->create()->id,
            'agent_name' => 'Failed',
            'fetch_error' => 'The CRM has no commission record for this agent and month.',
        ]);

        app(CommissionRunService::class)->finalize($run->fresh(), $manager);

        Notification::assertSentTo($hr, CommissionReadyToSend::class, function ($notification) {
            return $notification->pending === 1;
        });
    }
}
