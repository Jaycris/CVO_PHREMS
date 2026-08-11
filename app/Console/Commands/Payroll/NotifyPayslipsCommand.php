<?php

namespace App\Console\Commands\Payroll;

use App\Models\PayrollRun;
use App\Services\Payroll\PayslipNotifier;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Picks up any finalised run with payslips still unsent.
 *
 * Scheduled rather than manual because sending is resumable by design: only
 * payslips without a notified_at stamp are picked up, so a batch cut short by a
 * mail outage finishes itself on the next tick without anyone noticing.
 *
 * Deliberately does NOT compute, finalise or pay anything. Money moves only
 * when a human clicks.
 */
#[AsCommand(name: 'payroll:notify-payslips', description: 'Send any payslips still waiting to go out')]
class NotifyPayslipsCommand extends Command
{
    protected $signature = 'payroll:notify-payslips {--run= : Only this run}';

    protected $description = 'Send any payslips still waiting to go out';

    public function handle(PayslipNotifier $notifier): int
    {
        $runs = PayrollRun::query()
            ->whereIn('status', ['finalized', 'paid'])
            ->when($this->option('run'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('payslips', fn ($q) => $q->whereNull('notified_at'))
            ->get();

        if ($runs->isEmpty()) {
            $this->info('Nothing waiting to send.');

            return self::SUCCESS;
        }

        $totals = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($runs as $run) {
            $result = $notifier->sendForRun($run);

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            $this->line("  {$run->periodLabel()}: {$result['sent']} sent, {$result['skipped']} skipped, {$result['failed']} failed");
        }

        $this->info("Sent {$totals['sent']}, skipped {$totals['skipped']}, failed {$totals['failed']}.");

        return self::SUCCESS;
    }
}
