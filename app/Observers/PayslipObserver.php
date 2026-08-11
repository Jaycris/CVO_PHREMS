<?php

namespace App\Observers;

use App\Models\Payslip;
use App\Models\PayslipAdjustment;
use RuntimeException;

/**
 * The last line of defence on a locked run.
 *
 * The service methods already refuse to touch a finalised run, but they are not
 * the only way to reach these rows — a future screen, a command, or a tinker
 * session would each bypass that check. This one does not care how the write
 * arrived.
 */
class PayslipObserver
{
    public function updating(Payslip $payslip): void
    {
        // notified_at records that the payslip was emailed. It says nothing
        // about the money, and has to remain writable after locking or a
        // finalised run could never send its payslips.
        if ($payslip->isDirty('notified_at') && count($payslip->getDirty()) === 1) {
            return;
        }

        $this->guard($payslip);
    }

    public function deleting(Payslip $payslip): void
    {
        $this->guard($payslip);
    }

    protected function guard(Payslip $payslip): void
    {
        if ($payslip->isLocked()) {
            throw new RuntimeException(
                'This payslip belongs to a ' . $payslip->payrollRun->status . ' payroll run and cannot be changed.'
            );
        }
    }
}
