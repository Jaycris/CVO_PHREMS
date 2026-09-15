<?php

namespace App\Services\Payroll;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\EmployeeScheduleAssignment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OffsiteAssignment;
use App\Models\OvertimeRequest;
use App\Models\PayrollSetting;
use App\Models\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns a cutoff's worth of attendance into the handful of counters a payslip
 * needs, for every employee at once.
 *
 * The whole thing is seven queries regardless of headcount. Everything after that
 * is a loop in memory over employees × dates. The naive version — asking each
 * attendance row for its own schedule — is a query per row per method, which on
 * a hundred people across a cutoff is thousands of round trips on shared
 * hosting. That is why schedule assignments are resolved here and handed to the
 * model rather than looked up by it.
 */
class AttendanceAggregator
{
    public function __construct(
        protected PayrollPeriodResolver $periods = new PayrollPeriodResolver(),
    ) {}

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>> keyed by employee id
     */
    public function aggregate(Collection $employees, Carbon $start, Carbon $end): array
    {
        $employeeIds = $employees->pluck('id')->all();
        $dates = $this->periods->datesIn($start, $end);

        $attendance = $this->loadAttendance($employeeIds, $start, $end);
        $assignments = $this->loadAssignments($employeeIds);
        $leave = $this->loadLeaveDays($employeeIds, $start, $end);
        $offsite = $this->loadOffsiteDays($employeeIds, $start, $end);
        $overtime = $this->loadOvertimeHours($employeeIds, $start, $end);

        // The same for everyone, so it is loaded once rather than per employee.
        $holidays = Holiday::payProtectedBetween($start, $end);

        $results = [];

        foreach ($employees as $employee) {
            $results[$employee->id] = $this->aggregateOne(
                $employee,
                $dates,
                $attendance[$employee->id] ?? [],
                $assignments[$employee->id] ?? collect(),
                $leave[$employee->id] ?? [],
                $offsite[$employee->id] ?? [],
                (float) ($overtime[$employee->id] ?? 0),
                $holidays,
                $start,
                $end,
            );
        }

        return $results;
    }

    /**
     * @param  list<string>  $dates
     * @param  array<string, AttendanceDay>  $attendance
     * @param  Collection<int, EmployeeScheduleAssignment>  $assignments
     * @param  array<string, string>  $leave  date => paid|lwop
     * @param  array<string, string>  $offsite  date => reason
     * @param  array<string, Holiday>  $holidays  date => holiday
     * @return array<string, mixed>
     */
    protected function aggregateOne(
        Employee $employee,
        array $dates,
        array $attendance,
        Collection $assignments,
        array $leave,
        array $offsite,
        float $approvedOvertimeHours,
        array $holidays,
        Carbon $start,
        Carbon $end,
    ): array {
        $counters = [
            'days_present' => 0,
            'days_absent' => 0,
            'days_on_paid_leave' => 0,
            'days_lwop' => 0,
            'days_rest' => 0,
            // Days worked away from the clock. Counted inside days_present too;
            // held separately so a payslip can say why a day with no time in
            // was paid.
            'days_offsite' => 0,
            // Of those, the ones given off in lieu rather than worked.
            'days_offsite_day_off' => 0,
            'days_holiday' => 0,
            'days_holiday_worked' => 0,
            // Days' worth of holiday premium earned, summed from each holiday's
            // own setting. 0.3 for a special non-working day, 1.0 for a double.
            'holiday_premium_units' => 0.0,
            'days_expected' => 0,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'over_break_minutes' => 0,
            'night_diff_days' => 0,
            // Night differential is paid per hour, so this is what prices it.
            'night_diff_minutes' => 0,
            'overtime_hours' => round($approvedOvertimeHours, 2),
            'unclosed_days' => [],
            'unscheduled_days' => [],
        ];

        // Someone hired mid-cutoff is not absent for the days before they
        // joined, and someone who left is not absent afterwards.
        $from = $employee->hire_date ? max($start->timestamp, $employee->hire_date->startOfDay()->timestamp) : $start->timestamp;
        $to = $employee->separation_date ? min($end->timestamp, $employee->separation_date->endOfDay()->timestamp) : $end->timestamp;

        $skipThirtyFirst = PayrollSetting::flag('payroll_skip_31st', true);

        // Minutes earned on each qualifying night, capped after the loop.
        $nights = [];

        foreach ($dates as $date) {
            $day = Carbon::parse($date);

            if ($day->timestamp < $from || $day->timestamp > $to) {
                continue;
            }

            /*
             * Accounting counts every cutoff as 11 days and every month as 22,
             * so the 31st is not a payroll day at all: no night differential,
             * no absence, no lateness, no holiday premium. Basic pay is a fixed
             * half of the salary and already covers it.
             *
             * Approved overtime on the 31st is still paid — it is loaded apart
             * from this loop, and it is extra work somebody signed off on.
             */
            if ($skipThirtyFirst && $day->day === 31) {
                continue;
            }

            $assignment = $this->assignmentFor($assignments, $date);
            $row = $attendance[$date] ?? null;
            $leaveKind = $leave[$date] ?? null;

            if (! $assignment) {
                // No schedule covering this date. Flagged for preflight rather
                // than guessed at — treating it as a workday would invent an
                // absence, treating it as a rest day would hide one.
                $counters['unscheduled_days'][] = $date;

                if ($row) {
                    $counters['days_present']++;
                }

                continue;
            }

            $schedule = $assignment->workSchedule;
            $isWorkDay = $schedule->isWorkDay($day);

            if (! $isWorkDay) {
                $counters['days_rest']++;

                // Rest day work is captured entirely as approved overtime.
                // Counting it as a day present as well would pay it twice,
                // because basic pay is a fixed half of the monthly salary and
                // already covers the scheduled days.
                continue;
            }

            $counters['days_expected']++;

            /*
             * Someone who does not use the punch clock counts as present for
             * every scheduled day.
             *
             * There is no attendance in their arrangement, so there is nothing
             * to measure — and measuring anyway means reading every day as an
             * absence and deducting for it.
             *
             * Counted present rather than skipped, because the day still has to
             * feed the daily rate: that rate is the cutoff's half salary
             * divided by days_expected, and skipping would leave it at zero.
             *
             * Leave is still honoured below. Unpaid leave is a decision
             * somebody made on purpose, not a missing punch.
             */
            if (! $employee->tracks_attendance && $leaveKind === null) {
                $counters['days_present']++;

                continue;
            }

            /*
             * Working for the company away from the clock — a trade stand, a
             * client visit. Paid as a normal working day.
             *
             * Same treatment as somebody who does not punch at all, and for the
             * same reason: there is no attendance to measure, and measuring
             * anyway reads the day as an absence and deducts for it.
             *
             * Deliberately no night differential. The days these are used for
             * are daytime work, whatever the person's usual shift says, and
             * paying a night premium for a day spent on a stand would be
             * inventing a fact nobody recorded.
             *
             * Leave still wins, as above. Somebody who filed leave for a day
             * they were also listed for spent a credit on purpose, and the
             * aggregator is not the place to hand it back.
             */
            if (isset($offsite[$date]) && $leaveKind === null) {
                $counters['days_present']++;
                $counters['days_offsite']++;

                // Counted apart only so the payslip can say which it was. Both
                // are paid days with no punch, and payroll does nothing
                // different with them.
                if ($offsite[$date] === OffsiteAssignment::DAY_OFF) {
                    $counters['days_offsite_day_off']++;
                }

                continue;
            }

            if ($leaveKind === 'lwop') {
                $counters['days_lwop']++;

                continue;
            }

            if ($leaveKind === 'paid') {
                $counters['days_on_paid_leave']++;

                continue;
            }

            // Leave is settled before this on purpose. A regular holiday and a
            // paid leave day pay exactly the same, so the payslip is identical
            // either way — the only difference is that a leave credit was spent
            // on a day nobody had to work. Refunding that credit belongs to the
            // leave module, not to a read-only aggregator.
            $holiday = $holidays[$date] ?? null;

            if ($holiday) {
                $counters['days_holiday']++;

                if ($row) {
                    $counters['days_holiday_worked']++;

                    /*
                     * Added up as days' worth of premium, taken from each
                     * holiday's own setting rather than from its Labor Code
                     * type. Working two special non-working days is 0.6 of a
                     * day's pay; working a regular holiday is a whole one.
                     *
                     * Per holiday because that is where the company's own
                     * decision lives. Their list has no Regular Holidays on it
                     * at all — Christmas Day is entered as an American paid day
                     * off — so a rule keyed off the type would have paid
                     * nothing for working it.
                     */
                    $counters['holiday_premium_units'] += $holiday->premiumFraction();
                }
            }

            if (! $row) {
                // A holiday nobody was expected to work is not an absence. This
                // is the whole point of the holiday list: without it, Christmas
                // Day quietly took a day's pay off everyone who stayed home.
                if ($holiday) {
                    continue;
                }

                $counters['days_absent']++;

                continue;
            }

            $counters['days_present']++;

            if (! $row->time_out) {
                // Punched in and never out. The day cannot be measured, so it
                // is surfaced for preflight instead of being silently counted
                // as a full day or a zero.
                $counters['unclosed_days'][] = $date;
            }

            $lateMinutes = (int) ($row->lateMinutes($assignment) ?? 0);
            $undertimeMinutes = (int) ($row->undertimeMinutes($assignment) ?? 0);
            $overBreakMinutes = $row->overBreakMinutes($assignment);

            $counters['late_minutes'] += $lateMinutes;
            $counters['undertime_minutes'] += $undertimeMinutes;
            $counters['over_break_minutes'] += $overBreakMinutes;

            // Only days actually worked on a qualifying shift earn it, so a day
            // shift earns nothing and someone moved onto graveyard mid-cutoff
            // earns it only for the days after the move.
            if ($schedule->qualifiesForNightDifferential()) {
                $nights[] = $this->nightMinutes($schedule, $lateMinutes, $undertimeMinutes, $overBreakMinutes);
            }
        }

        /*
         * Accounting counts every cutoff as exactly 11 days — 22 a month —
         * whatever the calendar says. Absences always count.
         *
         * A cutoff with 12 weekdays loses a day worked: all 12 worked is
         * 11 / 11, 11 worked and 1 missed is 10 / 11. Night differential loses
         * the same nights, the shortest first.
         *
         * A short cutoff, like 9 weekdays around February, gains days worked:
         * all 9 worked is 11 / 11, 8 worked and 1 missed is 10 / 11. Night
         * differential is not topped up — it pays only nights really worked.
         *
         * Somebody hired or leaving partway through is not topped up; 3 days
         * worked in their first cutoff is 3 / 3, not 11 / 11.
         */
        $days = (int) PayrollSetting::number('payroll_max_days_per_cutoff', 11);
        $wholeCutoff = $from === $start->timestamp && $to === $end->timestamp;
        $extra = 0;

        if ($days > 0 && $counters['days_expected'] > $days) {
            $extra = $counters['days_expected'] - $days;

            $counters['days_present'] = max(0, $counters['days_present'] - $extra);
            $counters['days_expected'] = $days;
        } elseif ($days > 0 && $wholeCutoff && $counters['days_expected'] > 0 && $counters['days_expected'] < $days) {
            $counters['days_present'] += $days - $counters['days_expected'];
            $counters['days_expected'] = $days;
        }

        rsort($nights);
        $nights = array_slice($nights, 0, max(0, count($nights) - $extra));

        $counters['night_diff_days'] = count($nights);
        $counters['night_diff_minutes'] = array_sum($nights);

        return $counters;
    }

    /**
     * The minutes of one night shift that earn night differential.
     *
     * Accounting pays it per hour and counts a full graveyard night as eight
     * hours, breaks included. Time already taken off pay comes off the night
     * hours too — lateness always, undertime and over-break only when those
     * deductions are switched on — so nobody earns a night premium for time
     * they were not there.
     */
    protected function nightMinutes(WorkSchedule $schedule, int $late, int $undertime, int $overBreak): int
    {
        $fullDay = (int) round(PayrollSetting::number('hours_per_day', 8) * 60);

        // A schedule HR marked as earning it by hand can sit outside the window
        // entirely; it still earns a full day's worth rather than nothing.
        $scheduled = $schedule->nightWindowMinutes() ?: $fullDay;

        $lost = $late
            + (PayrollSetting::flag('undertime_deduction_enabled') ? $undertime : 0)
            + (PayrollSetting::flag('overbreak_deduction_enabled') ? $overBreak : 0);

        return max(0, min($scheduled, $fullDay) - $lost);
    }

    /**
     * The assignment covering a date. Resolved against the in-memory set rather
     * than the database, and taking the latest start when two overlap.
     *
     * @param  Collection<int, EmployeeScheduleAssignment>  $assignments
     */
    protected function assignmentFor(Collection $assignments, string $date): ?EmployeeScheduleAssignment
    {
        return $assignments->first(function (EmployeeScheduleAssignment $a) use ($date) {
            $startsOnOrBefore = $a->effective_start_date->toDateString() <= $date;
            $endsOnOrAfter = $a->effective_end_date === null || $a->effective_end_date->toDateString() >= $date;

            return $startsOnOrBefore && $endsOnOrAfter;
        });
    }

    /** @return array<int, array<string, AttendanceDay>> */
    protected function loadAttendance(array $employeeIds, Carbon $start, Carbon $end): array
    {
        return AttendanceDay::with('breaks')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->keyBy(fn (AttendanceDay $d) => $d->work_date->toDateString())->all())
            ->all();
    }

    /** @return array<int, Collection<int, EmployeeScheduleAssignment>> */
    protected function loadAssignments(array $employeeIds): array
    {
        return EmployeeScheduleAssignment::with('workSchedule')
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('effective_start_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id')
            ->all();
    }

    /**
     * Approved leave expanded to one entry per calendar day.
     *
     * @return array<int, array<string, string>> employee id => date => paid|lwop
     */
    /**
     * Days each employee was working for the company away from the clock.
     *
     * Ranges expanded to dates in memory, the same way leave is, so the day
     * loop can answer with an array lookup rather than a query per day.
     *
     * @return array<int, array<string, string>> employee id => date => kind
     */
    protected function loadOffsiteDays(array $employeeIds, Carbon $start, Carbon $end): array
    {
        $assignments = OffsiteAssignment::whereIn('employee_id', $employeeIds)
            ->overlapping($start, $end)
            ->get(['employee_id', 'start_date', 'end_date', 'kind']);

        $map = [];

        foreach ($assignments as $assignment) {
            $cursor = $assignment->start_date->copy()->startOfDay();
            $last = $assignment->end_date->copy()->startOfDay();

            while ($cursor->lte($last)) {
                if ($cursor->betweenIncluded($start->copy()->startOfDay(), $end->copy()->startOfDay())) {
                    $map[$assignment->employee_id][$cursor->toDateString()] = $assignment->kind;
                }

                $cursor->addDay();
            }
        }

        return $map;
    }

    /** @return array<int, array<string, string>> */
    protected function loadLeaveDays(array $employeeIds, Carbon $start, Carbon $end): array
    {
        $requests = LeaveRequest::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get(['employee_id', 'start_date', 'end_date', 'is_lwop']);

        $map = [];

        foreach ($requests as $request) {
            $cursor = $request->start_date->copy()->startOfDay();
            $last = $request->end_date->copy()->startOfDay();

            while ($cursor->lte($last)) {
                if ($cursor->betweenIncluded($start->copy()->startOfDay(), $end->copy()->startOfDay())) {
                    $map[$request->employee_id][$cursor->toDateString()] = $request->is_lwop ? 'lwop' : 'paid';
                }

                $cursor->addDay();
            }
        }

        return $map;
    }

    /** @return array<int, float> */
    protected function loadOvertimeHours(array $employeeIds, Carbon $start, Carbon $end): array
    {
        return OvertimeRequest::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereNull('consumed_payroll_run_id')
            ->whereDate('work_date', '>=', $start->toDateString())
            ->whereDate('work_date', '<=', $end->toDateString())
            ->selectRaw('employee_id, sum(hours_approved) as hours')
            ->groupBy('employee_id')
            ->pluck('hours', 'employee_id')
            ->map(fn ($h) => (float) $h)
            ->all();
    }
}
