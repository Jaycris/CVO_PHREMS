<?php

namespace App\Console\Commands\Leave;

use App\Services\LeaveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Grants the accruals people earned before PHREMS was keeping count.
 *
 * Accrual only ever ran forward, from the day the scheduler first worked, so
 * somebody hired in May had nothing for May, June or July. This walks each
 * employee from their hire date and grants what has already been earned.
 *
 * Deliberately not scheduled. It is a one-off correction, and the monthly
 * accrual takes over from here.
 */
#[Signature('leave:backfill-accrual {--dry-run : Show what would be granted without writing anything}')]
#[Description('Grants monthly leave accruals missed between each employee\'s hire date and today')]
class BackfillAccrualsCommand extends Command
{
    public function handle(LeaveService $leaveService): int
    {
        if ($this->option('dry-run')) {
            /*
             * Run it properly and roll it back, rather than reimplementing the
             * counting. A second implementation of "what would this grant?"
             * would eventually disagree with the real one, and the whole point
             * of a dry run is that it tells you the truth.
             */
            DB::beginTransaction();
            $count = $leaveService->backfillAccruals();
            DB::rollBack();

            $this->info("Dry run: {$count} credit(s) would be granted. Nothing was written.");

            return self::SUCCESS;
        }

        $count = $leaveService->backfillAccruals();

        $this->info("Granted {$count} missed leave credit(s).");

        if ($count === 0) {
            $this->line('Everybody was already up to date.');
        }

        return self::SUCCESS;
    }
}
