<?php

namespace App\Observers;

use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Refuses to change attendance for a period payroll has already settled.
 *
 * Once a run is finalized the payslip is a snapshot: editing the attendance
 * underneath it makes the record and the payslip disagree, with nothing to say
 * which is right. Worse, nobody notices until an employee queries a figure
 * months later and the attendance no longer supports it.
 *
 * The correction belongs on the next run as an adjustment, where it is visible
 * and carries a reason.
 *
 * Guarding at the model rather than in a screen means it holds for the punch
 * clock, any future DTR editor, a command, or a tinker session alike.
 */
class AttendanceLockObserver
{
    /**
     * Runs already looked up in this request, keyed by date. Attendance is
     * usually saved a row at a time, but seeding or a bulk correction would
     * otherwise repeat the same query per row.
     *
     * @var array<string, PayrollRun|null>
     */
    protected static array $cache = [];

    public function saving(AttendanceDay $day): void
    {
        $this->guard($day->work_date ?? $day->getOriginal('work_date'));
    }

    public function deleting(AttendanceDay $day): void
    {
        $this->guard($day->work_date);
    }

    public function savingBreak(AttendanceBreak $break): void
    {
        // A break changes the over-break minutes, which is a pay figure.
        $this->guard($break->attendanceDay?->work_date);
    }

    public function deletingBreak(AttendanceBreak $break): void
    {
        $this->guard($break->attendanceDay?->work_date);
    }

    protected function guard(Carbon|string|null $date): void
    {
        if ($date === null) {
            return;
        }

        $run = $this->runFor($date);

        if (! $run) {
            return;
        }

        throw new RuntimeException(
            'Payroll for ' . $run->periodLabel() . ' is already ' . $run->status
            . '. Attendance for that period cannot be changed — put the correction on the next payroll instead.'
        );
    }

    protected function runFor(Carbon|string $date): ?PayrollRun
    {
        $key = Carbon::parse($date)->toDateString();

        return static::$cache[$key] ??= PayrollRun::whereIn('status', ['finalized', 'paid'])
            ->whereDate('period_start', '<=', $key)
            ->whereDate('period_end', '>=', $key)
            ->first();
    }

    /** Called whenever a run's status changes, so the memo cannot go stale. */
    public static function flush(): void
    {
        static::$cache = [];
    }
}
