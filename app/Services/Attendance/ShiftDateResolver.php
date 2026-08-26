<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Which shift a punch belongs to, which is not always the day it happened on.
 *
 * A shift running 10 PM Monday to 6 AM Tuesday is one shift, and it belongs on
 * Monday — the day it started. Recording the punch against the calendar date
 * instead broke two things for night staff.
 *
 * A graveyard employee who came in after midnight — say 1 AM, three hours late
 * for a 10 PM shift — had that punch filed under the new day. When they came in
 * again at 10 PM that same evening, the punch clock found a completed day and
 * refused with "You have already completed your shift for today". They were
 * locked out of their own shift, and once shifted past midnight it happened
 * every day after.
 *
 * The same mistake made them look punctual. Lateness measures the punch against
 * the schedule's start on the day it is filed under, and 1 AM is *earlier* than
 * 10 PM, so three hours late read as nought minutes late.
 *
 * The rule here is the nearest scheduled start. A 10 PM shift punched at 1 AM
 * is three hours from last night's start and twenty-one from tonight's, so it
 * belongs to last night. Punched at 9 PM — an hour early, which people do, and
 * which nothing stops them doing — it is an hour from tonight's start and
 * twenty-three from last night's, so it belongs to tonight. Early, on time or
 * hours late, each punch lands on the shift the person was actually working.
 *
 * Day shifts never move: 8 AM against a 9 AM start is an hour from today and
 * twenty-three from yesterday, so today wins, exactly as before.
 */
class ShiftDateResolver
{
    /**
     * The work date a punch at this moment should be recorded against.
     *
     * Falls back to the calendar date for anybody with no schedule on file —
     * there is nothing to measure against, and inventing a shift for them would
     * be worse than the plain answer.
     */
    public function forPunch(Employee $employee, Carbon $at): string
    {
        $today = $at->copy()->startOfDay();

        $best = null;
        $bestDistance = null;

        // Yesterday first, so an exact tie goes to today rather than dragging a
        // punch backwards. Ties only happen on a schedule change, and the newer
        // shift is the likelier one.
        foreach ([$today->copy()->subDay(), $today] as $date) {
            $assignment = $employee->scheduleAssignmentForDate($date);

            if (! $assignment) {
                continue;
            }

            $start = Carbon::parse(
                $date->toDateString() . ' ' . $assignment->workSchedule->start_time->format('H:i:s')
            );

            $distance = abs($start->diffInMinutes($at));

            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $date->toDateString();
                $bestDistance = $distance;
            }
        }

        return $best ?? $today->toDateString();
    }
}
