<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkFromHomeRequest;
use App\Notifications\WorkFromHomeActionNeeded;
use App\Notifications\WorkFromHomeStatusUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Asking to work from home on particular days.
 *
 * Routed to the employee's manager, the same way leave and overtime are — the
 * person who knows whether the desk needs to be occupied is the one who sits
 * near it.
 *
 * Deliberately does not touch payroll or attendance. An approved day is still a
 * full working day: the employee clocks in as usual and is paid as usual. All
 * this records is where they were.
 */
class WorkFromHomeService
{
    /**
     * @param  list<string>  $dates
     */
    public function submit(Employee $employee, array $dates, string $reason): WorkFromHomeRequest
    {
        $dates = $this->cleanDates($dates);

        abort_if($dates->isEmpty(), 422, 'Pick at least one day.');
        abort_if($dates->count() > 31, 422, 'That is more than a month of days in one request. Split it up.');

        // Yesterday cannot be changed, so asking about it is asking for a record
        // rather than a decision — and the honest place for that is a
        // conversation with HR, not an approval queue.
        $today = Carbon::today('Asia/Manila');
        $past = $dates->filter(fn (Carbon $d) => $d->lt($today));

        // Written as an if rather than abort_if: that helper builds its message
        // whether or not the condition holds, so naming a date here would call
        // format() on null every time the check passes.
        if ($past->isNotEmpty()) {
            abort(422, 'Work from home has to be asked for in advance. '
                . $past->first()->format('M j') . ' has already passed.');
        }

        $clash = $this->clashingDates($employee, $dates);

        if ($clash->isNotEmpty()) {
            abort(422, 'You already have a request covering '
                . $clash->first()->format('M j') . '. Withdraw it first if you need to change it.');
        }

        $manager = $employee->reportsTo;

        return DB::transaction(function () use ($employee, $dates, $reason, $manager) {
            $request = WorkFromHomeRequest::create([
                'employee_id' => $employee->id,
                'reason' => $reason,
                'status' => 'pending_manager',
                'manager_id' => $manager?->id,
            ]);

            foreach ($dates as $date) {
                $request->days()->create(['work_date' => $date->toDateString()]);
            }

            $request->load(['employee', 'days']);
            $this->notifyApprover($request);

            return $request;
        });
    }

    public function decide(
        WorkFromHomeRequest $request,
        Employee $actor,
        bool $approved,
        ?string $note = null,
    ): void {
        abort_unless($request->isPending(), 403, 'This request has already been decided.');

        // Whoever it routes to may decide it. With no manager on file it falls
        // to whoever oversees this company-wide, rather than sitting forever.
        $isAssignedManager = $request->manager_id !== null && $request->manager_id === $actor->id;
        $isFallbackApprover = $request->manager_id === null
            && (bool) $actor->user?->can('wfh.view_all');

        abort_unless($isAssignedManager || $isFallbackApprover, 403, 'You cannot decide this request.');

        DB::transaction(function () use ($request, $actor, $approved, $note) {
            $request->update([
                'status' => $approved ? 'approved' : 'declined',
                'manager_id' => $request->manager_id ?? $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $this->notifyRequestor($request->fresh(['employee', 'manager', 'days']));
        });
    }

    /** An employee may withdraw their own request while it is still pending. */
    public function cancel(WorkFromHomeRequest $request, Employee $actor): void
    {
        abort_unless($request->isPending(), 403, 'Only a request still waiting can be withdrawn.');
        abort_unless($request->employee_id === $actor->id, 403, 'You can only withdraw your own request.');

        $request->update(['status' => 'cancelled']);
    }

    /**
     * Who else is working from home on the days in a request.
     *
     * Shown to the approver, because "can you work from home on Tuesday" is
     * rarely a question about one person — it is a question about how many
     * desks are empty.
     *
     * @return array<string, int> Y-m-d => how many others are already approved
     */
    public function coverageFor(WorkFromHomeRequest $request): array
    {
        $dates = $request->days->pluck('work_date')->map(fn (Carbon $d) => $d->toDateString());

        if ($dates->isEmpty()) {
            return [];
        }

        return DB::table('work_from_home_days')
            ->join('work_from_home_requests', 'work_from_home_requests.id', '=', 'work_from_home_days.work_from_home_request_id')
            ->where('work_from_home_requests.status', 'approved')
            ->where('work_from_home_requests.id', '!=', $request->id)
            ->whereIn('work_from_home_days.work_date', $dates->all())
            ->selectRaw('work_from_home_days.work_date, COUNT(*) as total')
            ->groupBy('work_from_home_days.work_date')
            ->pluck('total', 'work_date')
            ->mapWithKeys(fn ($total, $date) => [Carbon::parse($date)->toDateString() => (int) $total])
            ->all();
    }

    public function canApproveAny(User $user): bool
    {
        return $user->can('wfh.view_all') || ($user->employee?->directReports()->exists() ?? false);
    }

    /**
     * @param  list<string>  $dates
     * @return Collection<int, Carbon>
     */
    protected function cleanDates(array $dates): Collection
    {
        return collect($dates)
            ->filter(fn ($date) => is_string($date) && trim($date) !== '')
            ->map(function ($date) {
                try {
                    return Carbon::parse($date)->startOfDay();
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->unique(fn (Carbon $d) => $d->toDateString())
            ->sort()
            ->values();
    }

    /**
     * Dates already claimed by another live request from the same employee.
     *
     * @param  Collection<int, Carbon>  $dates
     * @return Collection<int, Carbon>
     */
    protected function clashingDates(Employee $employee, Collection $dates): Collection
    {
        $taken = DB::table('work_from_home_days')
            ->join('work_from_home_requests', 'work_from_home_requests.id', '=', 'work_from_home_days.work_from_home_request_id')
            ->where('work_from_home_requests.employee_id', $employee->id)
            ->whereIn('work_from_home_requests.status', ['pending_manager', 'approved'])
            ->pluck('work_from_home_days.work_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        return $dates->filter(fn (Carbon $d) => in_array($d->toDateString(), $taken, true))->values();
    }

    protected function notifyApprover(WorkFromHomeRequest $request): void
    {
        $recipients = $request->manager?->user
            ? collect([$request->manager->user])
            : User::withPermission('wfh.view_all')->get();

        $recipients->each(fn (User $user) => $this->safeNotify($user, new WorkFromHomeActionNeeded($request)));
    }

    protected function notifyRequestor(WorkFromHomeRequest $request): void
    {
        $user = $request->employee?->user;

        if (! $user) {
            return;
        }

        $approved = $request->status === 'approved';

        $message = $approved
            ? 'Your work from home request for ' . $request->dateLabel() . ' was approved.'
            : 'Your work from home request for ' . $request->dateLabel() . ' was declined.';

        $this->safeNotify($user, new WorkFromHomeStatusUpdated(
            $request,
            $message,
            'Your work from home request was ' . ($approved ? 'approved' : 'declined'),
        ));
    }

    /** One unreachable mailbox must not abort the rest. */
    protected function safeNotify(User $user, $notification): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
