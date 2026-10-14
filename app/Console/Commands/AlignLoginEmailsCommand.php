<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Brings every login address back to its employee's company email.
 *
 * The two drifted apart because a login was only ever given its address once,
 * when the account was registered, and nothing kept it up afterwards. The
 * observer handles it from here, but only when the company email changes —
 * which never happens for a pair that is already wrong and already correct on
 * the profile side. This is for those.
 */
class AlignLoginEmailsCommand extends Command
{
    protected $signature = 'phrems:align-login-emails {--apply : Write the changes, instead of only listing them}';

    protected $description = 'Point each login at its employee company email';

    public function handle(): int
    {
        $employees = Employee::query()
            ->whereNotNull('user_id')
            ->whereNotNull('company_email')
            ->with('user')
            ->get()
            ->filter(fn (Employee $e) => $e->user && $e->user->email !== $e->company_email);

        if ($employees->isEmpty()) {
            $this->info('Every login already matches its company email.');

            return self::SUCCESS;
        }

        $rows = [];
        $movable = [];

        foreach ($employees as $employee) {
            // Another account already answers to the address. Moving this one
            // onto it would collide on the unique index, and the two people
            // behind those accounts need sorting out by a person, not here.
            $taken = User::where('email', $employee->company_email)
                ->whereKeyNot($employee->user_id)
                ->exists();

            $rows[] = [
                $employee->employee_id,
                $employee->fullName() ?: '—',
                $employee->user->email,
                $employee->company_email,
                $taken ? 'SKIPPED — another account uses it' : 'move',
            ];

            if (! $taken) {
                $movable[] = $employee;
            }
        }

        $this->table(['Employee', 'Name', 'Logs in with', 'Company email', ''], $rows);

        if (! $this->option('apply')) {
            $this->warn(count($movable) . ' login(s) would move. Nothing has been changed.');
            $this->line('Run it again with --apply to write them.');

            return self::SUCCESS;
        }

        foreach ($movable as $employee) {
            $employee->user->update(['email' => $employee->company_email]);
        }

        $this->info(count($movable) . ' login(s) moved.');

        if ($skipped = count($rows) - count($movable)) {
            $this->warn($skipped . ' skipped because another account already uses the address.');
        }

        return self::SUCCESS;
    }
}
