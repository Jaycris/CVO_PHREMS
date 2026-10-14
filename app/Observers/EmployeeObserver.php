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
     * Only an address that still matched the old company email is moved. One
     * somebody deliberately set to something else is theirs, and dragging it
     * along would quietly lock them out.
     */
    public function updated(Employee $employee): void
    {
        if (! $employee->wasChanged('company_email')) {
            return;
        }

        $user = $employee->user;
        $was = $employee->getOriginal('company_email');

        if (! $user || $user->email !== $was) {
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
