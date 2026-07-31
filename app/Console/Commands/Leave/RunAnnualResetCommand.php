<?php

namespace App\Console\Commands\Leave;

use App\Services\LeaveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('leave:run-annual-reset')]
#[Description('Runs the Jan 1 annual leave reset: resets annual_upfront types (e.g. SL) to their default credits, and settles carry-over/cash-out disposition for types like VL.')]
class RunAnnualResetCommand extends Command
{
    public function handle(LeaveService $leaveService): void
    {
        $count = $leaveService->runAnnualReset(Carbon::today());

        $this->info("Annual reset processed for {$count} employee/leave-type combination(s).");
    }
}
