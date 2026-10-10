<?php

namespace Tests\Feature\Payroll;

use App\Models\Employee;
use App\Services\Payroll\AttendanceAggregator;
use App\Services\Payroll\FinalPayService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The days a leaver is actually paid for.
 *
 * Abigail left on 5 October with her last payslip ending 25 September. She
 * worked the weekdays between, and the settlement said nine days.
 */
class FinalPayDaysTest extends PayrollTestCase
{
    #[Test]
    public function the_window_counts_only_the_days_she_was_scheduled_for(): void
    {
        $employee = $this->makeEmployee(20000, 'day', [
            'hire_date' => '2026-06-01',
            'separation_date' => '2026-10-05',
        ]);

        // 26 Sep - 5 Oct 2026: Sat, Sun, then Mon-Fri, then Sat, Sun, Mon.
        // Six scheduled weekdays, all worked.
        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
            \App\Models\AttendanceDay::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'time_in' => $date . ' 09:00:00',
                'time_out' => $date . ' 18:00:00',
            ]);
        }

        $counters = (new AttendanceAggregator)->aggregate(
            collect([$employee]),
            Carbon::parse('2026-09-26'),
            Carbon::parse('2026-10-05')->endOfDay(),
        )[$employee->id];


        $this->assertSame(6, $counters['days_present'], 'she worked six scheduled days');
        $this->assertSame(6, $counters['days_expected']);
    }

    #[Test]
    public function the_settlement_pays_those_days_and_no_more(): void
    {
        $employee = $this->makeEmployee(20000, 'day', [
            'hire_date' => '2026-06-01',
            'separation_date' => '2026-10-05',
        ]);

        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-05'] as $date) {
            \App\Models\AttendanceDay::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'time_in' => $date . ' 09:00:00',
                'time_out' => $date . ' 18:00:00',
            ]);
        }

        // Her last payslip covered up to 25 September, as it did in real life.
        $paid = \App\Models\PayrollRun::create([
            'run_type' => 'regular',
            'cutoff' => 'second',
            'period_start' => '2026-09-11',
            'period_end' => '2026-09-25',
            'pay_date' => '2026-09-30',
            'status' => 'paid',
        ]);

        \App\Models\Payslip::create([
            'payroll_run_id' => $paid->id,
            'employee_id' => $employee->id,
            'basic_salary' => 20000,
            'basic_earned' => 10000,
            'gross_pay' => 10000,
            'net_pay' => 10000,
        ]);

        $preview = app(FinalPayService::class)->preview($employee->fresh());


        // Six days at 909.09 a day.
        $this->assertSame(6.0, $preview['unpaid_days']);
        $this->assertSame(5454.55, $preview['unpaid_salary']);
    }
}
