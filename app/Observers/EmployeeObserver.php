<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\User;

class EmployeeObserver
{
    /**
     * Keeps the login address with the company address.
     *
     * A login is created with the employee's company email and was never
     * touched again, so changing the company email on the profile left them
     * signing in with an address that no longer exists — and the Users
     * directory still showing the old one.
     *
     * This first only moved a login that still matched the old company email,
     * on the theory that a different one had been set deliberately. In
     * practice the pairs that most needed fixing were the ones already out of
     * step, and that rule skipped exactly those: changing the profile did
     * nothing, and no amount of re-saving would ever catch them up. The
     * company email is the address of record, so the login follows it.
     */
    public function updated(Employee $employee): void
    {
        if (! $employee->wasChanged('company_email')) {
            return;
        }

        $user = $employee->user;

        if (! $user || $user->email === $employee->company_email) {
            return;
        }

        $taken = User::where('email', $employee->company_email)
            ->whereKeyNot($user->id)
            ->exists();

        // Another account already answers to it. Left alone rather than
        // failing the profile save, because the email on the profile is the
        // change that was asked for and the login still works.
        if ($taken) {
            return;
        }

        $user->update(['email' => $employee->company_email]);
    }
}
