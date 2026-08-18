<?php

namespace App\Console\Commands\Crm;

use App\Models\Employee;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmClient;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Answers "is the CRM link working?" without anyone opening a browser.
 *
 * Worth having as a command rather than a page: the first thing that goes wrong
 * after a deploy is a mistyped URL or a token that never made it into the
 * server's .env, and this says which of those it is in one line. It also runs
 * on Hostinger over SSH, where there is no browser to check with.
 *
 * Read-only. It fetches one slip and prints what came back.
 */
#[AsCommand(name: 'crm:check', description: 'Check the CRM connection and fetch one commission slip')]
class CheckCrmConnectionCommand extends Command
{
    protected $signature = 'crm:check
                            {--employee= : HRIS employee ID, e.g. EMP-9372. Defaults to the first on file.}
                            {--month= : YYYY-MM. Defaults to this month.}';

    protected $description = 'Check the CRM connection and fetch one commission slip';

    public function handle(CrmClient $client, CommissionSlipService $slips): int
    {
        $this->line('');
        $this->line('  <options=bold>CRM connection</>');

        $base = (string) config('services.crm.base_url');
        $token = (string) config('services.crm.token');
        $header = strtolower((string) config('services.crm.auth_header')) === 'x-hris-token'
            ? 'X-HRIS-Token'
            : 'Authorization: Bearer';

        $this->line('  Base URL      ' . ($base !== '' ? $base : '<fg=red>not set</>'));
        // Length only. Printing the secret would put it in shell history and
        // in whatever the deploy log keeps.
        $this->line('  Token         ' . ($token !== '' ? '<fg=green>set</> (' . strlen($token) . ' chars)' : '<fg=red>not set</>'));
        $this->line('  Sent as       ' . $header);
        $this->line('  Timeout       ' . config('services.crm.timeout') . 's');
        $this->line('  Verify TLS    ' . (config('services.crm.verify_tls') ? 'yes' : '<fg=yellow>no</>'));

        if (! $client->isConfigured()) {
            $this->line('');
            $this->error('  Not configured. Set CRM_API_BASE_URL and CRM_HRIS_API_TOKEN in .env, then run:');
            $this->line('    php artisan config:clear');

            return self::FAILURE;
        }

        $employee = $this->option('employee')
            ? Employee::where('employee_id', $this->option('employee'))->first()
            : Employee::orderBy('employee_id')->first();

        if (! $employee) {
            $this->error('  No such employee in HRIS.');

            return self::FAILURE;
        }

        $month = (string) ($this->option('month') ?: now('Asia/Manila')->format('Y-m'));

        $this->line('');
        $this->line('  <options=bold>Fetching a slip</>');
        $this->line('  Employee      ' . $employee->employee_id . ' — ' . ($employee->fullName() ?: '(no name)'));
        $this->line('  Month         ' . $month);
        $this->line('');

        try {
            $slips->forget($employee, $month);
            $slip = $slips->forEmployee($employee, $month);
        } catch (CrmUnavailable $e) {
            $this->error('  ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('  The CRM answered.');
        $this->line('');

        $money = fn (?float $v, string $p = '') => $v === null ? '<fg=yellow>not sent</>' : $p . number_format($v, 2);

        foreach ([
            'Agent name' => $slip->agentName ?? '<fg=yellow>not sent</>',
            'Team' => $slip->team ?? '<fg=yellow>not sent</>',
            'MTD' => $money($slip->mtd, '$'),
            'Target' => $money($slip->target, '$'),
            'MTD %' => $slip->mtdPercent === null ? '<fg=yellow>not sent</>' : number_format($slip->mtdPercent, 2) . '%',
            'USD total' => $money($slip->usdTotal, '$'),
            'Exchange rate' => $slip->exchangeRate === null ? '<fg=yellow>not sent</>' : number_format($slip->exchangeRate, 4),
            'PHP total' => $money($slip->phpTotal, 'PHP '),
            'Card hold' => $money($slip->cardHoldAmount, 'PHP '),
            'Net commission' => $money($slip->netCommission, 'PHP '),
        ] as $label => $value) {
            $this->line('  ' . str_pad($label, 16) . $value);
        }

        $this->line('');

        // The distinction the whole slip page hangs on, so it is worth naming
        // here too: no rows and no statement are different problems.
        if (! $slip->statementSupplied) {
            $this->warn('  No "transactions" key in the response — the statement table will say so.');
        } elseif ($slip->transactions->isEmpty()) {
            $this->line('  Statement     <fg=yellow>0 rows</> (the CRM says this agent had no sales)');
        } else {
            $this->line('  Statement     <fg=green>' . $slip->transactions->count() . ' row(s)</>');

            $first = $slip->transactions->first();
            $this->line('  First row     ' . implode(' · ', array_filter([
                $first->soldDate,
                $first->brand,
                $first->bookTitle,
                $first->paymentMethod,
                $first->netCommission === null ? null : 'net PHP ' . number_format($first->netCommission, 2),
            ])));
        }

        $this->line('');

        return self::SUCCESS;
    }
}
