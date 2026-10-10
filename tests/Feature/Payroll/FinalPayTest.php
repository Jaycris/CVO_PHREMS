<?php

namespace Tests\Feature\Payroll;

use App\Mail\FinalPayStatementMail;
use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\FinalPay;
use App\Models\User;
use App\Services\Payroll\FinalPayService;
use App\Services\Payroll\PayrollService;
use App\Services\Payroll\ThirteenthMonthService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settling up with somebody who has left.
 *
 * The thirteenth month is only run in December, so somebody who leaves in
 * October would never be paid the part they earned. Their final salary is not
 * in here — the payroll run already paid the days they worked.
 */
class FinalPayTest extends PayrollTestCase
{
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->hr = User::factory()->create();
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo(['payroll.final_pay.manage', 'payroll.runs.manage', 'payroll.runs.finalize']);
    }

    /** Somebody who left mid-October with one finalized cutoff behind them. */
    protected function leaver(float $salary = 20000): Employee
    {
        $employee = $this->makeEmployee($salary, 'day', [
            'first_name' => 'Rio',
            'last_name' => 'Moreno',
            'personal_email' => 'rio.moreno@gmail.com',
            'hire_date' => '2024-01-05',
        ]);

        $period = $this->period();
        $this->fillAttendance($employee, $period);

        $service = app(PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->hr);
        $service->finalize($run->fresh(), $this->hr);

        $employee->update(['separation_date' => '2026-10-16']);

        return $employee->fresh();
    }

    protected function service(): FinalPayService
    {
        return app(FinalPayService::class);
    }

    #[Test]
    public function the_thirteenth_month_is_what_they_earned_up_to_their_last_day(): void
    {
        $employee = $this->leaver();

        $paid = app(ThirteenthMonthService::class)->basicEarnedFor($employee, 2026)['total'];
        $preview = $this->service()->preview($employee);

        // The days since their last payslip are basic pay too, and no payroll
        // run will ever record them — so they count towards the 13th month.
        $this->assertSame(round($paid + $preview['unpaid_salary'], 2), $preview['basic_earned']);
        $this->assertSame(round($preview['basic_earned'] / 12, 2), $preview['thirteenth_month']);
        $this->assertSame(2026, $preview['for_year']);
        $this->assertGreaterThan(0, $preview['unpaid_salary'], 'the cutoff they left in is settled here');
    }

    #[Test]
    public function what_they_still_owe_comes_off(): void
    {
        $employee = $this->leaver();

        CashAdvance::create([
            'employee_id' => $employee->id,
            'reference_no' => 'CV-CA-TEST-001',
            'principal_amount' => 300,
            'amount_per_cutoff' => 300,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $preview = $this->service()->preview($employee->fresh());

        $owed = round($preview['thirteenth_month'] + $preview['unpaid_salary']
            + $preview['unpaid_night_differential'] + $preview['unpaid_overtime'], 2);

        $this->assertSame(300.00, $preview['cash_advance_balance']);
        $this->assertSame(round($owed - 300, 2), $preview['net_amount']);
    }

    #[Test]
    public function a_settlement_is_never_a_bill(): void
    {
        // Owing more than the thirteenth month leaves nothing to pay, not a
        // negative figure to collect on a statement.
        $employee = $this->leaver();

        CashAdvance::create([
            'employee_id' => $employee->id,
            'reference_no' => 'CV-CA-TEST-002',
            'principal_amount' => 999999,
            'amount_per_cutoff' => 1000,
            'start_date' => '2026-09-01',
            'status' => 'active',
        ]);

        $this->assertSame(0.0, $this->service()->preview($employee->fresh())['net_amount']);
    }

    #[Test]
    public function it_is_held_until_clearance_and_cannot_be_released_before(): void
    {
        $employee = $this->leaver();
        $final = $this->service()->record($employee, $this->hr);

        $this->assertSame(FinalPay::HELD, $final->status);
        $this->assertFalse($final->isPayable());

        $this->expectExceptionMessage('has not been cleared yet');
        $this->service()->release($final, now());
    }

    #[Test]
    public function clearing_it_makes_it_payable(): void
    {
        $final = $this->service()->record($this->leaver(), $this->hr);

        $cleared = $this->service()->clear($final, $this->hr);

        $this->assertSame(FinalPay::CLEARED, $cleared->status);
        $this->assertSame($this->hr->id, $cleared->cleared_by_user_id);
        $this->assertTrue($cleared->isPayable());
    }

    #[Test]
    public function releasing_it_emails_the_statement_to_their_personal_address(): void
    {
        // Their company mailbox and their login are both gone by now.
        Mail::fake();

        $employee = $this->leaver();
        $final = $this->service()->clear($this->service()->record($employee, $this->hr), $this->hr);

        Livewire::actingAs($this->hr)
            ->test('payroll.final-pay')
            ->call('release', $final->id)
            ->assertSee('Statement sent to rio.moreno@gmail.com');

        Mail::assertQueued(FinalPayStatementMail::class, fn ($mail) => $mail->hasTo('rio.moreno@gmail.com'));
        $this->assertSame(FinalPay::RELEASED, $final->fresh()->status);
        $this->assertNotNull($final->fresh()->emailed_at);
    }

    #[Test]
    public function nothing_is_sent_to_somebody_with_no_personal_email(): void
    {
        Mail::fake();

        $employee = $this->leaver();
        $employee->update(['personal_email' => null]);

        $final = $this->service()->clear($this->service()->record($employee->fresh(), $this->hr), $this->hr);
        $result = $this->service()->emailStatement($final);

        $this->assertFalse($result['sent']);
        $this->assertStringContainsString('no personal email on file', $result['message']);
        Mail::assertNothingQueued();
    }

    #[Test]
    public function december_does_not_pay_the_thirteenth_month_twice(): void
    {
        $employee = $this->leaver();
        $this->service()->record($employee, $this->hr);

        $rows = app(ThirteenthMonthService::class)->preview(2026);

        $this->assertFalse($rows->contains(fn (array $row) => $row['employee']->is($employee)));
    }

    #[Test]
    public function a_cancelled_settlement_puts_them_back_in_december(): void
    {
        $employee = $this->leaver();
        $final = $this->service()->record($employee, $this->hr);

        $this->service()->cancel($final);

        $rows = app(ThirteenthMonthService::class)->preview(2026);

        $this->assertTrue($rows->contains(fn (array $row) => $row['employee']->is($employee)));
    }

    #[Test]
    public function recalculating_picks_up_a_payroll_run_finalized_since(): void
    {
        // The usual order: they leave, the settlement is worked out, and their
        // last cutoff is finalized a few days later.
        $employee = $this->leaver();
        $final = $this->service()->record($employee, $this->hr);
        $before = (float) $final->thirteenth_month;

        $period = $this->period(2026, 9, 'second');
        $this->fillAttendance($employee, $period);
        $service = app(PayrollService::class);
        $later = $service->openRun(2026, 9, 'second');
        $service->compute($later, $this->hr);
        $service->finalize($later->fresh(), $this->hr);

        $updated = $this->service()->recalculate($final->fresh());

        $this->assertGreaterThan($before, (float) $updated->thirteenth_month);
    }

    #[Test]
    public function a_leaver_drops_out_of_the_run_for_the_cutoff_they_left_in(): void
    {
        // Their remaining days are settled in Final Pay instead, so paying
        // part of the fortnight here would pay it twice.
        $employee = $this->makeEmployee(20000, 'day', ['separation_date' => '2026-08-16']);
        $period = $this->period();                       // Aug 11-25
        $this->fillAttendance($employee, $period);

        // Somebody has to stay, or the run has nobody in it at all.
        $this->fillAttendance($this->makeEmployee(20000, 'day'), $period);

        $service = app(PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->hr);

        $this->assertSame(0, \App\Models\Payslip::where('employee_id', $employee->id)->count());
    }

    #[Test]
    public function recomputing_takes_a_leaver_back_out_of_the_run(): void
    {
        // The usual order: the run is computed, then HR records that somebody
        // left. Recomputing used to leave their payslip exactly where it was.
        $employee = $this->makeEmployee(20000, 'day');
        $period = $this->period();
        $this->fillAttendance($employee, $period);
        $this->fillAttendance($this->makeEmployee(20000, 'day'), $period);

        $service = app(PayrollService::class);
        $run = $service->openRun((int) $period['start']->year, (int) $period['start']->month, $period['cutoff']);
        $service->compute($run, $this->hr);

        $this->assertSame(1, \App\Models\Payslip::where('employee_id', $employee->id)->count());

        $employee->update(['separation_date' => '2026-08-16']);
        $service->compute($run->fresh(), $this->hr);

        $this->assertSame(0, \App\Models\Payslip::where('employee_id', $employee->id)->count());
    }

    #[Test]
    public function the_settlement_is_due_thirty_days_after_their_last_day(): void
    {
        // The company's default, and negotiable — so it is a date on the
        // record rather than a rule nobody can change.
        $final = $this->service()->record($this->leaver(), $this->hr);

        $this->assertSame('2026-11-15', $final->expected_release_on->toDateString());
    }

    #[Test]
    public function the_settlement_has_its_own_page_that_finalizes_and_sends(): void
    {
        // The same shape as a payroll run: work it out, lock it, send it.
        Mail::fake();

        $employee = $this->leaver();
        $final = $this->service()->record($employee, $this->hr);

        $page = Livewire::actingAs($this->hr)->test('payroll.final-pay-slip', ['finalPay' => $final]);

        $page->assertSee('Net final pay')
            ->assertSee('Finalize')
            ->call('finalize')
            ->assertSet('errorMessage', null);

        $this->assertSame(FinalPay::CLEARED, $final->fresh()->status);

        Livewire::actingAs($this->hr)
            ->test('payroll.final-pay-slip', ['finalPay' => $final->fresh()])
            ->call('send')
            ->assertSee('Statement sent to rio.moreno@gmail.com');

        $this->assertSame(FinalPay::RELEASED, $final->fresh()->status);
        Mail::assertQueued(FinalPayStatementMail::class);
    }

    #[Test]
    public function a_finalized_settlement_cannot_be_edited_until_it_is_reopened(): void
    {
        $final = $this->service()->clear($this->service()->record($this->leaver(), $this->hr), $this->hr);

        Livewire::actingAs($this->hr)
            ->test('payroll.final-pay-slip', ['finalPay' => $final])
            ->call('recalculate')
            ->assertSet('errorMessage', null)
            ->call('reopen');

        $this->assertSame(FinalPay::HELD, $final->fresh()->status);
    }

    #[Test]
    public function somebody_without_the_permission_cannot_open_a_settlement(): void
    {
        $final = $this->service()->record($this->leaver(), $this->hr);

        $clerk = User::factory()->create();
        $clerk->assignRole('Admin');
        $clerk->givePermissionTo('payroll.runs.manage');

        $this->actingAs($clerk)->get(route('payroll.final-pay-slip', $final))->assertForbidden();
    }

    #[Test]
    public function somebody_without_the_permission_cannot_settle_anybody(): void
    {
        $employee = $this->leaver();

        $clerk = User::factory()->create();
        $clerk->assignRole('Admin');
        $clerk->givePermissionTo('payroll.runs.manage');

        $this->actingAs($clerk)->get('/payroll/final-pay')->assertForbidden();

        Livewire::actingAs($clerk)
            ->test('payroll.final-pay')
            ->call('start', $employee->id)
            ->assertForbidden();

        $this->assertSame(0, FinalPay::count());
    }

    #[Test]
    public function one_settlement_per_person_per_year(): void
    {
        $employee = $this->leaver();
        $this->service()->record($employee, $this->hr);

        $this->expectExceptionMessage('already has a final pay recorded');
        $this->service()->record($employee->fresh(), $this->hr);
    }
}
