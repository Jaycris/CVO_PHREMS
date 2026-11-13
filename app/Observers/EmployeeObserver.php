<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\EmploymentPeriod;
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
        $this->closePeriodWhenTheyLeave($employee);

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

    /** A new employee starts their first period the moment they exist. */
    public function created(Employee $employee): void
    {
        if ($employee->hire_date === null) {
            return;
        }

        EmploymentPeriod::create([
            'employee_id' => $employee->id,
            'started_on' => $employee->hire_date->toDateString(),
            'recorded_by_user_id' => auth()->id(),
        ]);
    }

    /**
     * Closes the open period when somebody is given a last day.
     *
     * Only this direction is inferred. Clearing a last day could mean a
     * rehire or a mistake being undone, and guessing wrong either invents a
     * period that never happened or loses one that did — so reopening is an
     * explicit act, through Employee::rehire().
     */
    protected function closePeriodWhenTheyLeave(Employee $employee): void
    {
        if (! $employee->wasChanged('separation_date') || $employee->separation_date === null) {
            return;
        }

        $open = $employee->employmentPeriods()->open()->latest('started_on')->first();

        if (! $open) {
            return;
        }

        // A last day before the period began is somebody correcting a typo in
        // the wrong direction. Closing on it would leave a period that ends
        // before it starts, which no report could make sense of.
        if ($open->started_on->gt($employee->separation_date)) {
            return;
        }

        $open->update([
            'ended_on' => $employee->separation_date->toDateString(),
            'end_reason' => $employee->separation_type,
            'recorded_by_user_id' => auth()->id() ?? $open->recorded_by_user_id,
        ]);
    }
}
