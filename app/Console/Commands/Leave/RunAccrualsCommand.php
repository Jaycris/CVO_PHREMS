<?php

namespace App\Console\Commands\Leave;

use App\Services\LeaveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('leave:run-monthly-accrual')]
#[Description('Grants monthly-accrual leave credits (e.g. VL) for Regular employees whose leave type accrual day matches today.')]
class RunAccrualsCommand extends Command
{
    public function handle(LeaveService $leaveService): void
    {
        $count = $leaveService->runMonthlyAccrual(Carbon::today());

        $this->info("Monthly accrual applied to {$count} employee/leave-type combination(s).");
    }
}
