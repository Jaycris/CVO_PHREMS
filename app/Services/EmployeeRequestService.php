<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Notifications\EmployeeRequestActionNeeded;
use App\Notifications\EmployeeRequestStatusUpdated;
use App\Services\Concerns\SerialisesConcurrentWrites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Filing a request and deciding it.
 *
 * Routed to the employee's manager, the same way leave and overtime are — the
 * person who knows whether the desk needs occupying is the one who sits near
 * it. With no manager on file it falls to whoever oversees requests
 * company-wide, so nothing sits undecidable.
 *
 * Deliberately touches neither payroll nor attendance. Approving a work from
 * home day does not change what anyone is paid; it records what was agreed.
 */
class EmployeeRequestService
{
    use SerialisesConcurrentWrites;

    /**
     * @param  list<string>  $dates  Ignored for types that do not need dates.
     */
    public function submit(
        Employee $employee,
        RequestType $type,
        string $details,
        array $dates = [],
    ): EmployeeRequest {
        abort_unless($type->is_active, 422, 'That kind of request is not being accepted at the moment.');

        $dates = $type->needs_dates ? $this->cleanDates($dates) : collect();

        if ($type->needs_dates) {
            abort_if($dates->isEmpty(), 422, 'Pick at least one day.');
            abort_if($dates->count() > 31, 422, 'That is more than a month of days in one request. Split it up.');

            // Yesterday cannot be changed, so asking about it is asking for a
            // record rather than a decision — and that belongs in a
            // conversation with HR, not an approval queue.
            $today = Carbon::today('Asia/Manila');
            $past = $dates->filter(fn (Carbon $d) => $d->lt($today));

            // Written as an if rather than abort_if: that helper builds its
            // message whether or not the condition holds, so naming a date here
            // would call format() on null every time the check passes.
            if ($past->isNotEmpty()) {
                abort(422, 'This has to be asked for in advance. '
                    . $past->first()->format('M j') . ' has already passed.');
            }
        }

        $manager = $employee->reportsTo;

        return DB::transaction(function () use ($employee, $type, $details, $dates, $manager) {
            // The clash check has to happen inside the lock. Outside it, two
            // submissions racing each other both find no clash and both get
            // filed for the same days.
            $this->lockEmployee($employee);

            if ($type->needs_dates) {
                $clash = $this->clashingDates($employee, $type, $dates);

                if ($clash->isNotEmpty()) {
                    abort(422, 'You already have a ' . $type->name . ' request covering '
                        . $clash->first()->format('M j') . '. Withdraw it first if you need to change it.');
                }
            }

            $request = EmployeeRequest::create([
                'employee_id' => $employee->id,
                'request_type_id' => $type->id,
                'details' => $details,
                'status' => 'pending_manager',
                'manager_id' => $manager?->id,
            ]);

            foreach ($dates as $date) {
                $request->days()->create(['work_date' => $date->toDateString()]);
            }

            $request->load(['employee', 'type', 'days']);
            $this->notifyApprover($request);

            return $request;
        });
    }

    public function decide(
        EmployeeRequest $request,
        Employee $actor,
        bool $approved,
        ?string $note = null,
    ): void {
        abort_unless($request->isPending(), 403, 'This request has already been decided.');

        $isAssignedManager = $request->manager_id !== null && $request->manager_id === $actor->id;
        $isFallbackApprover = $request->manager_id === null
            && (bool) $actor->user?->can('requests.view_all');

        abort_unless($isAssignedManager || $isFallbackApprover, 403, 'You cannot decide this request.');

        DB::transaction(function () use ($request, $actor, $approved, $note) {
            // The status above was read off a copy loaded before the click.
            // Re-read it under the lock so a second approver is turned away
            // rather than overwriting the first decision and its note.
            $locked = $this->lockRow(EmployeeRequest::class, $request->id);

            abort_unless($locked->isPending(), 403, 'This request has already been decided.');

            $locked->update([
                'status' => $approved ? 'approved' : 'declined',
                'manager_id' => $locked->manager_id ?? $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $this->notifyRequestor($locked->fresh(['employee', 'type', 'manager', 'days']));
        });

        $request->refresh();
    }

    /** An employee may withdraw their own request while it is still pending. */
    public function cancel(EmployeeRequest $request, Employee $actor): void
    {
        abort_unless($request->isPending(), 403, 'Only a request still waiting can be withdrawn.');
        abort_unless($request->employee_id === $actor->id, 403, 'You can only withdraw your own request.');

        $request->update(['status' => 'cancelled']);
    }

    /**
     * Who else is approved for the same type on the days in a request.
     *
     * Shown to the approver, because "can you work from home on Tuesday" is
     * rarely a question about one person — it is a question about how many
     * desks are empty.
     *
     * @return array<string, int> Y-m-d => how many others are already approved
     */
    public function coverageFor(EmployeeRequest $request): array
    {
        $dates = $request->days->pluck('work_date')->map(fn (Carbon $d) => $d->toDateString());

        if ($dates->isEmpty()) {
            return [];
        }

        return DB::table('employee_request_days')
            ->join('employee_requests', 'employee_requests.id', '=', 'employee_request_days.employee_request_id')
            ->where('employee_requests.status', 'approved')
            ->where('employee_requests.request_type_id', $request->request_type_id)
            ->where('employee_requests.id', '!=', $request->id)
            ->whereIn('employee_request_days.work_date', $dates->all())
            ->selectRaw('employee_request_days.work_date, COUNT(*) as total')
            ->groupBy('employee_request_days.work_date')
            ->pluck('total', 'work_date')
            ->mapWithKeys(fn ($total, $date) => [Carbon::parse($date)->toDateString() => (int) $total])
            ->all();
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
     * Dates already claimed by another live request of the same type.
     *
     * Scoped to the type on purpose: asking to work from home and asking for a
     * shift change on the same Tuesday are not in conflict.
     *
     * @param  Collection<int, Carbon>  $dates
     * @return Collection<int, Carbon>
     */
    protected function clashingDates(Employee $employee, RequestType $type, Collection $dates): Collection
    {
        $taken = DB::table('employee_request_days')
            ->join('employee_requests', 'employee_requests.id', '=', 'employee_request_days.employee_request_id')
            ->where('employee_requests.employee_id', $employee->id)
            ->where('employee_requests.request_type_id', $type->id)
            ->whereIn('employee_requests.status', ['pending_manager', 'approved'])
            ->pluck('employee_request_days.work_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        return $dates->filter(fn (Carbon $d) => in_array($d->toDateString(), $taken, true))->values();
    }

    protected function notifyApprover(EmployeeRequest $request): void
    {
        $recipients = $request->manager?->user
            ? collect([$request->manager->user])
            : User::withPermission('requests.view_all')->get();

        $recipients->each(fn (User $user) => $this->safeNotify($user, new EmployeeRequestActionNeeded($request)));
    }

    protected function notifyRequestor(EmployeeRequest $request): void
    {
        $user = $request->employee?->user;

        if (! $user) {
            return;
        }

        $approved = $request->status === 'approved';
        $when = $request->days->isNotEmpty() ? ' for ' . $request->dateLabel() : '';

        $message = 'Your ' . strtolower($request->typeName()) . ' request' . $when
            . ' was ' . ($approved ? 'approved' : 'declined') . '.';

        $this->safeNotify($user, new EmployeeRequestStatusUpdated(
            $request,
            $message,
            'Your ' . strtolower($request->typeName()) . ' request was ' . ($approved ? 'approved' : 'declined'),
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
