<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Models\Payslip;
use App\Models\User;
use App\Notifications\PayslipReady;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\PayslipNotifier;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;

/**
 * Correcting one person's pay after the run went out.
 *
 * Reopening a run used to withdraw every released payslip, so sending again
 * emailed the whole company a second copy of a figure that had not changed.
 * Employees learn to ignore a payslip email that arrives twice, which is
 * exactly the email they must not ignore.
 */
class ResendOnlyChangedPayslipsTest extends PayrollTestCase
{
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('Admin');
        $this->manager->givePermissionTo(['payroll.runs.manage', 'payroll.runs.finalize', 'payroll.runs.unlock']);
    }

    protected function service(): PayrollService
    {
        return app(PayrollService::class);
    }

    /** Two employees, computed, finalized and both sent their payslip. */
    protected function releasedRun(): \App\Models\PayrollRun
    {
        foreach ([['Maria', 'Santos'], ['Rio', 'Moreno']] as [$first, $last]) {
            $employee = $this->makeEmployee(20000, 'day', ['first_name' => $first, 'last_name' => $last]);
            $employee->forceFill(['user_id' => User::factory()->create()->id])->save();
            $this->fillAttendance($employee->fresh(), $this->period());
        }

        $period = $this->period();
        $run = $this->service()->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);

        $this->service()->compute($run, $this->manager);
        $this->service()->finalize($run->fresh(), $this->manager);
        app(PayslipNotifier::class)->sendForRun($run->fresh());

        return $run->fresh();
    }

    protected function payslipFor(string $lastName): Payslip
    {
        return Payslip::where('employee_id', Employee::where('last_name', $lastName)->sole()->id)->sole();
    }

    #[Test]
    public function reopening_alone_leaves_every_payslip_with_its_employee(): void
    {
        $run = $this->releasedRun();

        $this->service()->unfinalize($run, $this->manager, 'Maria was paid for a day she did not work.');

        $this->assertNotNull($this->payslipFor('Santos')->notified_at);
        $this->assertNotNull($this->payslipFor('Moreno')->notified_at);
    }

    #[Test]
    public function only_the_corrected_employee_is_sent_to_again(): void
    {
        $run = $this->releasedRun();

        // Faked only now: the first send above is real history, and what is
        // under test is who hears about the correction.
        Notification::fake();
        $maria = $this->payslipFor('Santos');

        $this->service()->unfinalize($run, $this->manager, 'Maria was paid for a day she did not work.');

        // The correction: a day of hers is taken off the record.
        \App\Models\AttendanceDay::where('employee_id', $maria->employee_id)
            ->orderBy('work_date')
            ->first()
            ->delete();

        $this->service()->compute($run->fresh(), $this->manager);

        $this->assertNull($this->payslipFor('Santos')->notified_at, 'her figures changed, so hers is withdrawn');
        $this->assertNotNull($this->payslipFor('Moreno')->notified_at, 'his did not change, so his stands');

        $this->service()->finalize($run->fresh(), $this->manager);
        $result = app(PayslipNotifier::class)->sendForRun($run->fresh());

        $this->assertSame(1, $result['sent']);
        Notification::assertSentToTimes($this->payslipFor('Santos')->employee->user, PayslipReady::class, 1);
        Notification::assertNotSentTo($this->payslipFor('Moreno')->employee->user, PayslipReady::class);
    }

    #[Test]
    public function a_recompute_that_changes_nothing_emails_nobody(): void
    {
        $run = $this->releasedRun();

        // Faked only now: the first send above is real history, and what is
        // under test is who hears about the correction.
        Notification::fake();

        $this->service()->unfinalize($run, $this->manager, 'Checking a figure.');
        $this->service()->compute($run->fresh(), $this->manager);
        $this->service()->finalize($run->fresh(), $this->manager);

        $this->assertSame(0, app(PayslipNotifier::class)->sendForRun($run->fresh())['sent']);
        Notification::assertNothingSent();
    }

    #[Test]
    public function an_adjustment_typed_in_by_hand_counts_as_a_change(): void
    {
        $run = $this->releasedRun();
        $maria = $this->payslipFor('Santos');

        $this->service()->unfinalize($run, $this->manager, 'Rice allowance was missed.');

        $maria->adjustments()->create([
            'type' => 'earning',
            'label' => 'Rice allowance',
            'amount' => 500,
            'note' => 'Missed on the first pass.',
        ]);

        $this->service()->compute($run->fresh(), $this->manager);

        $this->assertNull($this->payslipFor('Santos')->notified_at);
        $this->assertNotNull($this->payslipFor('Moreno')->notified_at);
    }
}
