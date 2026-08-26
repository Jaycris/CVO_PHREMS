<?php

namespace App\Services\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Concerns\SerialisesConcurrentWrites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR correcting a day somebody clocked wrongly.
 *
 * The case this was built for: an employee tapped Time In and Time Out within
 * a minute of each other early in the morning. One row exists per employee per
 * day, and the punch clock refuses to reopen a day that already has a time out,
 * so they were locked out of their own shift for the rest of the day and the
 * day would have been paid as zero hours worked.
 *
 * Two rules hold here. A day inside a finalised or paid payroll run cannot be
 * touched at all — that money is already out, and a correction belongs on the
 * next run as an adjustment rather than as a quiet rewrite of a payslip
 * somebody is holding. And every change that does go through is recorded with
 * its previous values, because attendance decides pay and an untraceable edit
 * is a dispute waiting to happen.
 */
class AttendanceCorrectionService
{
    use SerialisesConcurrentWrites;

    /**
     * Applies a correction to one day, creating the row if it never existed.
     *
     * $timeIn and $timeOut are wall-clock times ("21:00"), or null to clear.
     * Clearing the time out is what reopens a day for punching.
     */
    public function apply(
        Employee $employee,
        Carbon|string $workDate,
        ?string $timeIn,
        ?string $timeOut,
        string $reason,
        User $actor,
    ): AttendanceDay {
        $date = Carbon::parse($workDate)->startOfDay();

        $this->guardAgainstSettledPayroll($date);

        $in = $this->moment($date, $timeIn);
        $out = $this->moment($date, $timeOut);

        /*
         * A shift ending before it started is a night shift that ran past
         * midnight, not a mistake. Ending on the same clock time is, though —
         * that would be a zero-length shift somebody typed by accident.
         */
        if ($in && $out && $out->lessThanOrEqualTo($in)) {
            $out->addDay();
        }

        if ($out && ! $in) {
            throw ValidationException::withMessages([
                'timeOut' => 'Set a time in before setting a time out.',
            ]);
        }

        return DB::transaction(function () use ($employee, $date, $in, $out, $reason, $actor) {
            $this->lockEmployee($employee);

            // Re-read inside the lock: the employee may have punched between
            // the form being opened and this being saved.
            $day = AttendanceDay::query()
                ->where('employee_id', $employee->id)
                ->whereDate('work_date', $date)
                ->lockForUpdate()
                ->first();

            $before = [
                'time_in' => $day?->time_in?->toDateTimeString(),
                'time_out' => $day?->time_out?->toDateTimeString(),
            ];

            if ($day) {
                $day->update(['time_in' => $in, 'time_out' => $out]);
            } else {
                $day = AttendanceDay::create([
                    'employee_id' => $employee->id,
                    'work_date' => $date->toDateString(),
                    'time_in' => $in,
                    'time_out' => $out,
                ]);
            }

            $after = [
                'time_in' => $in?->toDateTimeString(),
                'time_out' => $out?->toDateTimeString(),
            ];

            // Nothing moved, so there is nothing worth recording. Writing a
            // correction here would bury the real ones in noise.
            if ($before !== $after) {
                AttendanceCorrection::create([
                    'attendance_day_id' => $day->id,
                    'employee_id' => $employee->id,
                    'work_date' => $date->toDateString(),
                    'user_id' => $actor->id,
                    'before' => $before,
                    'after' => $after,
                    'reason' => $reason,
                ]);
            }

            return $day->fresh();
        });
    }

    /**
     * Clears the time out so the person can carry on punching.
     *
     * The narrow fix for the common case, kept separate because it is the one
     * HR will reach for in a hurry and it needs no times typed in.
     */
    public function reopen(AttendanceDay $day, string $reason, User $actor): AttendanceDay
    {
        return $this->apply(
            $day->employee,
            $day->work_date,
            $day->time_in?->format('H:i'),
            null,
            $reason,
            $actor,
        );
    }

    /**
     * Whether a date is inside a payroll run that has been finalised or paid.
     */
    public function isSettled(Carbon|string $date): bool
    {
        $on = Carbon::parse($date)->startOfDay();

        return PayrollRun::query()->settledOver($on, $on)->exists();
    }

    protected function guardAgainstSettledPayroll(Carbon $date): void
    {
        if (! $this->isSettled($date)) {
            return;
        }

        throw ValidationException::withMessages([
            'workDate' => 'That date is inside a payroll run that has already been finalised. '
                . 'Correct it on the next run as an adjustment instead.',
        ]);
    }

    /** Turns "21:00" on a work date into a full moment, or null. */
    protected function moment(Carbon $date, ?string $time): ?Carbon
    {
        if (blank($time)) {
            return null;
        }

        return Carbon::parse($date->toDateString() . ' ' . $time);
    }
}
