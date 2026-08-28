<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The whole run as one CSV, for the bookkeeper and the bank file.
 *
 * Streamed rather than assembled in memory: on shared hosting a hundred rows is
 * nothing, but the same code will hold up if the company doubles.
 */
class PayrollRegisterExportController extends Controller
{
    public function __invoke(PayrollRun $run): StreamedResponse
    {
        abort_unless(request()->user()->can('payroll.runs.manage'), 403);

        $filename = 'payroll-register-' . $run->period_start->format('Y-m-d') . '-to-' . $run->period_end->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($run) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Employee ID', 'Name', 'Department', 'Position',
                'Days Worked', 'Days Absent', 'Late Minutes', 'Overtime Hours',
                'Basic Pay', 'Absences', 'Basic Earned', 'Overtime', 'Night Differential',
                'Holiday Premium', 'Allowance', 'Adjustments (+)', 'Gross Pay',
                'Late', 'Undertime', 'Over Break',
                'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Cash Advance', 'Adjustments (-)',
                'Total Deductions', 'Net Pay',
                'Employer SSS', 'Employer Work Injury', 'Employer PhilHealth', 'Employer Pag-IBIG',
            ]);

            $run->payslips()->with('employee')->lazy(100)->each(function ($p) use ($out) {
                $snap = $p->employee_snapshot ?? [];

                fputcsv($out, [
                    $p->employeeCode(),
                    $p->employeeName(),
                    $snap['department'] ?? '',
                    $snap['position'] ?? '',
                    $p->days_present, $p->days_absent, $p->late_minutes, $p->overtime_hours,
                    $p->basic_pay, $p->absence_deduction, $p->basic_earned, $p->overtime_pay, $p->night_differential_pay,
                    $p->holiday_premium_pay, $p->allowance, $p->adjustments_earning, $p->gross_pay,
                    $p->late_deduction, $p->undertime_deduction, $p->over_break_deduction,
                    $p->sss_employee, $p->philhealth_employee, $p->pagibig_employee,
                    $p->withholding_tax, $p->cash_advance_deduction, $p->adjustments_deduction,
                    $p->total_deductions, $p->net_pay,
                    $p->sss_employer, $p->sss_employee_compensation, $p->philhealth_employer, $p->pagibig_employer,
                ]);
            });

            // A totals row so the register can be checked against the run at a
            // glance without re-adding the column.
            fputcsv($out, array_merge(
                ['', 'TOTAL', '', '', '', '', '', '', '', '', '', '', '', '', '', $run->total_gross],
                array_fill(0, 9, ''),
                [$run->total_deductions, $run->total_net]
            ));

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }
}
