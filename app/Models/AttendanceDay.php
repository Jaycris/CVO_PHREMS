<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AttendanceDay extends Model
{
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

        return $this->time_in->gt($scheduledStart)
            ? $scheduledStart->diffInMinutes($this->time_in)
            : 0;
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
            ? $this->time_out->diffInMinutes($scheduledEnd)
            : 0;
    }

    public function totalBreakMinutes(): int
    {
        return $this->breaks->sum(function (AttendanceBreak $break) {
            $end = $break->break_end ?? now();

            return $break->break_start->diffInMinutes($end);
        });
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

        return max(0, $this->time_in->diffInMinutes($this->time_out) - $this->totalBreakMinutes());
    }
}
