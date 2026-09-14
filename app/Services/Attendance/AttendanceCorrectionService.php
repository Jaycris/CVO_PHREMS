<?php

namespace App\Services\Attendance;

use App\Models\AttendanceBreak;
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
        ?array $breaks = null,
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

        return DB::transaction(function () use ($employee, $date, $in, $out, $reason, $actor, $breaks) {
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
                'break_minutes' => $day?->totalBreakMinutes(),
                'breaks' => $this->describeBreaks($day),
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

            if ($breaks !== null) {
                $this->setBreaks($day, $breaks);
            }

            $day->load('breaks');

            $after = [
                'time_in' => $in?->toDateTimeString(),
                'time_out' => $out?->toDateTimeString(),
                'break_minutes' => $day->totalBreakMinutes(),
                'breaks' => $this->describeBreaks($day),
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
     * Replaces the day's breaks with the ones HR typed.
     *
     * Times, not a total, because times are what anybody actually knows: "she
     * went on break at one and came back at two". A total asks HR to do
     * arithmetic on somebody's pay, which is how the wrong figure gets typed.
     *
     * A list rather than one pair, because the schedules here carry a lunch and
     * a coffee break — collapsing a day to a single stretch would silently lose
     * the other one and hand the employee back time they did not work.
     *
     * Break rows stay the single source of truth, so totalBreakMinutes(),
     * overBreakMinutes() and workedMinutes() carry on untouched. An override
     * column on the day would mean two places disagreeing about the same fact
     * and payroll having to pick one.
     *
     * @param  list<array{start: ?string, end: ?string}>  $breaks
     */
    protected function setBreaks(AttendanceDay $day, array $breaks): void
    {
        // Deleted one at a time rather than in a single query, so the lock
        // observer still gets its say on each row.
        $day->breaks()->get()->each->delete();

        $shiftStart = $day->time_in;

        foreach ($breaks as $break) {
            $start = $this->breakMoment($day->work_date, $shiftStart, $break['start'] ?? null);

            if ($start === null) {
                continue;
            }

            $end = $this->breakMoment($day->work_date, $shiftStart, $break['end'] ?? null);

            // A break that ends before it starts crossed midnight, the same way
            // a night shift does.
            if ($end && $end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            $day->breaks()->create([
                // An unrecognised kind is stored as none rather than refused:
                // the times are what decide pay, and losing a correction over
                // a label would be the wrong trade.
                'kind' => AttendanceBreak::isKind($break['kind'] ?? null) ? $break['kind'] : null,
                'break_start' => $start,
                // Left open when there is no end time — which is what an
                // employee still on break looks like, and HR may be correcting
                // the start of one that is genuinely still running.
                'break_end' => $end,
            ]);
        }
    }

    /**
     * A wall-clock time placed on the right calendar day.
     *
     * The subtlety is the night shift. A break at 01:00 on a shift that began
     * at 21:53 belongs to the following morning, not to thirteen hours before
     * the employee arrived — which would price the break as negative and the
     * day as far longer than it was.
     */
    protected function breakMoment(Carbon|string $date, ?Carbon $shiftStart, ?string $time): ?Carbon
    {
        if (blank($time)) {
            return null;
        }

        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        $moment = Carbon::parse($date)->startOfDay()->setTime((int) $hour, (int) $minute);

        if ($shiftStart && $moment->lessThan($shiftStart)) {
            $moment->addDay();
        }

        return $moment;
    }

    /**
     * The day's breaks as plain times, for the correction record.
     *
     * @return list<array{start: string, end: ?string}>
     */
    protected function describeBreaks(?AttendanceDay $day): array
    {
        if (! $day) {
            return [];
        }

        return $day->breaks
            ->map(fn ($break) => [
                'kind' => $break->kind,
                'start' => $break->break_start->format('H:i'),
                'end' => $break->break_end?->format('H:i'),
            ])
            ->values()
            ->all();
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
