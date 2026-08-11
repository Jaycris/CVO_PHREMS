<?php

namespace App\Observers;

use App\Models\PayslipAdjustment;
use RuntimeException;

class PayslipAdjustmentObserver
{
    public function saving(PayslipAdjustment $adjustment): void
    {
        $this->guard($adjustment);
    }

    public function deleting(PayslipAdjustment $adjustment): void
    {
        $this->guard($adjustment);
    }

    protected function guard(PayslipAdjustment $adjustment): void
    {
        if ($adjustment->payslip?->isLocked()) {
            throw new RuntimeException('This payroll run is locked. Add the correction to the next run instead.');
        }
    }
}
