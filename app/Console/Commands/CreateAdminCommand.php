<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as askPassword;
use function Laravel\Prompts\text;

/**
 * Creates the first administrator on a live install.
 *
 * The seeded demo account does not exist in production on purpose: its address
 * and password are both written in DatabaseSeeder, so anybody who can read the
 * repository could sign in to a system holding everyone's salary.
 *
 * This asks for the password instead of inventing one, and never echoes it.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'phrems:create-admin
                            {--name= : The person\'s name}
                            {--email= : The address they sign in with}';

    protected $description = 'Create an administrator account for this install';

    public function handle(): int
    {
        /*
         * Refused outright without a terminal.
         *
         * The password is asked for rather than passed as a flag, and the
         * prompt waits on standard input — so run from a deploy script or a
         * cron job this would hang forever rather than fail. A command that
         * never returns is worse than one that says no.
         */
        if (! $this->input->isInteractive()) {
            $this->error('This has to be run from a terminal, because it asks for a password.');
            $this->line('Sign in to the server over SSH and run: php artisan phrems:create-admin');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text(
            label: 'Their name',
            required: true,
        );

        $email = $this->option('email') ?: text(
            label: 'Email address to sign in with',
            required: true,
        );

        // Never passed as an option: anything typed as a flag lands in the
        // shell history and in the process list.
        $password = askPassword(
            label: 'Password',
            required: true,
        );

        $confirmation = askPassword(
            label: 'Again, to be sure',
            required: true,
        );

        if ($password !== $confirmation) {
            $this->error('Those did not match. Nothing was created.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'is_active' => true,
            'is_super_admin' => true,
        ]);

        $user->assignRole('Admin');

        $this->info("Created {$email} as an administrator.");
        $this->line('They can sign in now. Link them to an employee record from the Users page');
        $this->line('if they should also have a payslip and attendance of their own.');

        return self::SUCCESS;
    }
}
