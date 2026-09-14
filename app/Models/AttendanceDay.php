<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AttendanceDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_date',
        'time_in',
        'time_out',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'time_in' => 'datetime',
            'time_out' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class);
    }

    public function openBreak(): ?AttendanceBreak
    {
        return $this->breaks()->whereNull('break_end')->first();
    }

    public function scheduleAssignment(): ?EmployeeScheduleAssignment
    {
        return $this->employee->scheduleAssignmentForDate($this->work_date);
    }

    /*
     * The methods below accept an already-resolved schedule assignment.
     *
     * Left to themselves they each look the assignment up, which is fine for a
     * single row on screen but is a query per call — payroll walking a hundred
     * employees across a cutoff would issue thousands. The aggregator resolves
     * each day's assignment once in memory and passes it in; screens that call
     * these without an argument behave exactly as before.
     *
     * Every minute count is floored rather than rounded. Carbon returns these
     * as floats, and a part-minute should not be charged to the employee —
     * 5 minutes 40 seconds late is 5 minutes, not 6. Flooring is also what the
     * int return type was already doing implicitly, so this is the behaviour
     * that produced every figure to date, now stated rather than assumed.
     */

    /**
     * Minutes late against the schedule's start time, or null when there is no
     * schedule or no time in to compare against.
     */
    public function lateMinutes(?EmployeeScheduleAssignment $assignment = null): ?int
    {
        $assignment ??= $this->scheduleAssignment();

        if (! $assignment || ! $this->time_in) {
            return null;
        }

        $scheduledStart = Carbon::parse($this->work_date->toDateString() . ' ' . $assignment->workSchedule->start_time->format('H:i:s'));

        if (! $this->time_in->gt($scheduledStart)) {
            return 0;
        }

        $minutes = (int) floor($scheduledStart->diffInMinutes($this->time_in));

        /*
         * The grace period forgives the whole lateness, it does not shave it.
         * With 15 minutes' grace, arriving 14 minutes late is not late at all;
         * arriving 20 minutes late is 20 minutes, not 5.
         *
         * That is the usual reading — grace covers traffic and the lift, and
         * once someone is past it the allowance was not the point.
         */
        $grace = (int) PayrollSetting::number('late_grace_minutes', 0);

        return $minutes <= $grace ? 0 : $minutes;
    }

    /**
     * Minutes left early against the schedule's end time.
     *
     * Counted and shown regardless; whether it actually deducts is a company
     * setting, since some payrolls treat it as a discipline matter rather than
     * a pay matter.
     */
    public function undertimeMinutes(?EmployeeScheduleAssignment $assignment = null): ?int
    {
        $assignment ??= $this->scheduleAssignment();

        if (! $assignment || ! $this->time_out) {
            return null;
        }

        $schedule = $assignment->workSchedule;
        $scheduledEnd = Carbon::parse($this->work_date->toDateString() . ' ' . $schedule->end_time->format('H:i:s'));

        if ($schedule->crossesMidnight()) {
            $scheduledEnd->addDay();
        }

        return $this->time_out->lt($scheduledEnd)
            ? (int) floor($this->time_out->diffInMinutes($scheduledEnd))
            : 0;
    }

    public function totalBreakMinutes(): int
    {
        return $this->breaks->sum(fn (AttendanceBreak $break) => $break->minutes());
    }

    /**
     * The day's break split by what it was for.
     *
     * Every kind is present even at zero, so a row of figures lines up across
     * employees and an empty column reads as "none" rather than as missing.
     * Breaks punched before kinds existed land under null.
     *
     * @return array<string, int> kind => minutes, plus null for unlabelled
     */
    public function breakMinutesByKind(): array
    {
        $totals = array_fill_keys(array_keys(AttendanceBreak::KINDS), 0);

        foreach ($this->breaks as $break) {
            $key = AttendanceBreak::isKind($break->kind) ? $break->kind : 'unlabelled';

            $totals[$key] = ($totals[$key] ?? 0) + $break->minutes();
        }

        return $totals;
    }

    /** How many separate times they went, by kind — the count, not the length. */
    public function breakCountsByKind(): array
    {
        $counts = array_fill_keys(array_keys(AttendanceBreak::KINDS), 0);

        foreach ($this->breaks as $break) {
            $key = AttendanceBreak::isKind($break->kind) ? $break->kind : 'unlabelled';

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function allowedBreakMinutes(?EmployeeScheduleAssignment $assignment = null): ?int
    {
        $assignment ??= $this->scheduleAssignment();

        if (! $assignment) {
            return null;
        }

        return $assignment->workSchedule->lunch_break_minutes + $assignment->workSchedule->coffee_break_minutes;
    }

    public function overBreakMinutes(?EmployeeScheduleAssignment $assignment = null): int
    {
        $allowed = $this->allowedBreakMinutes($assignment);

        if ($allowed === null) {
            return 0;
        }

        return max(0, $this->totalBreakMinutes() - $allowed);
    }

    public function totalWorkedMinutes(): ?int
    {
        if (! $this->time_in || ! $this->time_out) {
            return null;
        }

        return max(0, (int) floor($this->time_in->diffInMinutes($this->time_out)) - $this->totalBreakMinutes());
    }
}
