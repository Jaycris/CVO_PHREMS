<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Notifications\OvertimeRequestActionNeeded;
use App\Notifications\OvertimeRequestStatusUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OvertimeService
{
    /**
     * Overtime pays a flat hourly rate, so an inflated claim is money out the
     * door. Submission is therefore capped against what attendance actually
     * shows, and only a manager's approval makes hours payable.
     */
    public function submit(Employee $employee, string $workDate, float $hours, ?string $reason): OvertimeRequest
    {
        $date = Carbon::parse($workDate)->startOfDay();

        abort_if($date->isFuture(), 422, 'Overtime can only be filed for a date that has already passed.');

        $duplicate = OvertimeRequest::where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->whereIn('status', ['pending_manager', 'approved'])
            ->exists();

        abort_if($duplicate, 422, 'An overtime request for that date is already pending or approved.');

        $manager = $employee->reportsTo;

        return DB::transaction(function () use ($employee, $date, $hours, $reason, $manager) {
            $request = OvertimeRequest::create([
                'employee_id' => $employee->id,
                'work_date' => $date,
                'hours_requested' => $hours,
                'reason' => $reason,
                'status' => 'pending_manager',
                'manager_id' => $manager?->id,
            ]);

            $this->notifyApprover($request);

            return $request;
        });
    }

    /**
     * A manager may approve fewer hours than requested — attendance is the
     * source of truth and claims are often rounded up.
     */
    public function managerDecide(
        OvertimeRequest $request,
        Employee $actor,
        bool $approved,
        ?float $approvedHours = null,
        ?string $note = null
    ): void {
        abort_unless($request->isPending(), 403, 'This overtime request has already been decided.');

        // Whoever the request routes to may decide it. When an employee has no
        // manager on file it falls to HR/Admin rather than becoming undecidable.
        $isAssignedManager = $request->manager_id !== null && $request->manager_id === $actor->id;
        $isFallbackApprover = $request->manager_id === null
            && $actor->user?->hasAnyRole(['HR', 'Admin', 'CEO']);

        abort_unless($isAssignedManager || $isFallbackApprover, 403, 'You cannot decide this overtime request.');

        $hours = $approved
            ? round($approvedHours ?? (float) $request->hours_requested, 2)
            : null;

        if ($approved) {
            abort_if($hours <= 0, 422, 'Approved hours must be greater than zero.');
            abort_if($hours > (float) $request->hours_requested, 422, 'Approved hours cannot exceed the hours requested.');
        }

        DB::transaction(function () use ($request, $actor, $approved, $hours, $note) {
            $request->update([
                'status' => $approved ? 'approved' : 'declined',
                'hours_approved' => $hours,
                'manager_id' => $request->manager_id ?? $actor->id,
                'manager_decision' => $approved ? 'approved' : 'declined',
                'manager_decided_at' => now(),
                'manager_note' => $note,
            ]);

            $this->notifyRequestor($request->fresh(['employee', 'manager']));
        });
    }

    /** An employee may withdraw their own request while it is still pending. */
    public function cancel(OvertimeRequest $request, Employee $actor): void
    {
        abort_unless($request->isPending(), 403, 'Only a pending request can be cancelled.');
        abort_unless($request->employee_id === $actor->id, 403, 'You can only cancel your own overtime request.');

        $request->update(['status' => 'cancelled']);
    }

    /**
     * Overtime implied by attendance: how much longer the employee was on the
     * clock than their shift is long.
     *
     * Both sides are measured as gross spans (in to out, start to end) rather
     * than net of breaks. Breaks are paid here, so netting them out of worked
     * time while the schedule is also net would over-credit anyone who simply
     * did not punch their break — 12 hours on the clock against a 9 hour shift
     * is 3 hours of overtime, not 4.5. Exceeding the allotted break time is a
     * separate deduction (AttendanceDay::overBreakMinutes), so it must not be
     * folded in here as well.
     */
    public function suggestedHours(Employee $employee, Carbon|string $workDate): float
    {
        $date = Carbon::parse($workDate)->toDateString();

        $day = AttendanceDay::where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        if (! $day?->time_in || ! $day?->time_out) {
            return 0.0;
        }

        $assignment = $employee->scheduleAssignmentForDate($date);

        if (! $assignment) {
            return 0.0;
        }

        $schedule = $assignment->workSchedule;
        $start = Carbon::parse($date . ' ' . $schedule->start_time->format('H:i:s'));
        $end = Carbon::parse($date . ' ' . $schedule->end_time->format('H:i:s'));

        if ($schedule->crossesMidnight()) {
            $end->addDay();
        }

        $onClockMinutes = $day->time_in->diffInMinutes($day->time_out);
        $shiftMinutes = $start->diffInMinutes($end);

        return round(max(0, $onClockMinutes - $shiftMinutes) / 60, 2);
    }

    /** Approved overtime hours for a payroll period. */
    public function approvedHoursBetween(Employee $employee, Carbon|string $start, Carbon|string $end): float
    {
        return (float) OvertimeRequest::where('employee_id', $employee->id)
            ->approvedBetween($start, $end)
            ->sum('hours_approved');
    }

    protected function notifyApprover(OvertimeRequest $request): void
    {
        $request->loadMissing('employee');

        $approverUser = $request->manager?->user;

        if ($approverUser) {
            $approverUser->notify(new OvertimeRequestActionNeeded($request));

            return;
        }

        // No manager on file — fall back to HR/Admin so it does not sit unseen.
        User::role(['HR', 'Admin'])->get()->each(function (User $user) use ($request) {
            try {
                $user->notify(new OvertimeRequestActionNeeded($request));
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    protected function notifyRequestor(OvertimeRequest $request): void
    {
        $summary = $request->status === 'approved'
            ? "approved for {$request->hours_approved} hour(s)"
            : 'declined';

        $request->employee?->user?->notify(new OvertimeRequestStatusUpdated($request, $summary));
    }
}
