<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestActionNeeded;
use App\Notifications\LeaveRequestStatusUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Filing leave and deciding it.
 *
 * Leave credits are paid time, so every write that moves them runs inside a
 * transaction with the row locked first. Without that, two people clicking
 * Approve at the same moment both pass the status check and both write a
 * deduction, taking the days twice — and nothing downstream can tell, because
 * a balance is only ever the sum of its transactions.
 */
class LeaveService
{
    public function submit(Employee $employee, LeaveType $leaveType, string $startDate, string $endDate, ?string $reason): LeaveRequest
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $daysRequested = $start->diffInDays($end) + 1;

        abort_unless(
            $employee->isEligibleFor($leaveType),
            403,
            "This employee is not entitled to {$leaveType->name}."
        );

        $leaveRequest = DB::transaction(function () use ($employee, $leaveType, $start, $end, $daysRequested, $reason) {
            // Locking the employee serialises their own submissions, so two
            // requests filed at once cannot both read the same balance and
            // both come out as paid leave when only one is covered.
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            $balance = $employee->leaveBalance($leaveType);
            $isLwop = ! $employee->isRegular() || $balance < $daysRequested;

            $manager = $employee->reportsTo;

            return LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $start,
                'end_date' => $end,
                'days_requested' => $daysRequested,
                'reason' => $reason,
                'is_lwop' => $isLwop,
                'status' => $manager ? 'pending_manager' : 'pending_ceo',
                'manager_id' => $manager?->id,
            ]);
        });

        $manager = $employee->reportsTo;

        $this->notifyHr($leaveRequest, "New leave request from " . $this->employeeName($employee) . " ({$daysRequested} day(s), {$leaveType->name}) submitted.");

        if ($manager?->user) {
            $manager->user->notify(new LeaveRequestActionNeeded($leaveRequest));
        } elseif ($ceo = $this->firstCeoUser()) {
            $ceo->notify(new LeaveRequestActionNeeded($leaveRequest));
        }

        return $leaveRequest;
    }

    public function managerDecide(LeaveRequest $leaveRequest, bool $approved, ?string $note = null): void
    {
        DB::transaction(function () use ($leaveRequest, $approved) {
            // Re-read under a lock. Checking the status on a copy loaded before
            // the click means two managers can both find it pending.
            $locked = LeaveRequest::whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === 'pending_manager', 403, 'This request is not awaiting manager approval.');

            $locked->update([
                'manager_decision' => $approved ? 'approved' : 'declined',
                'manager_decided_at' => now(),
                'status' => $approved ? 'pending_ceo' : 'declined',
            ]);
        });

        $leaveRequest->refresh();

        if (! $approved) {
            $this->notifyFinalDecision($leaveRequest, 'declined by Manager', $note);

            return;
        }

        if ($ceo = $this->firstCeoUser()) {
            $ceo->notify(new LeaveRequestActionNeeded($leaveRequest));
        }

        $this->notifyRequestor($leaveRequest, 'Your leave request was approved by your Manager and is now awaiting CEO/COO approval.');
        $this->notifyHr($leaveRequest, $this->employeeName($leaveRequest->employee) . "'s leave request was approved by Manager, now pending CEO/COO.");
    }

    public function ceoDecide(LeaveRequest $leaveRequest, Employee $ceoActor, bool $approved, ?string $note = null): void
    {
        DB::transaction(function () use ($leaveRequest, $ceoActor, $approved) {
            // The status check and the credit deduction have to be one
            // indivisible step. Apart, two approvals racing each other both see
            // "pending_ceo" and both deduct the days — the employee is charged
            // twice for one absence, and the only trace is a balance that is
            // quietly wrong.
            $locked = LeaveRequest::whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

            abort_unless($locked->status === 'pending_ceo', 403, 'This request is not awaiting CEO/COO approval.');

            $locked->update([
                'ceo_id' => $ceoActor->id,
                'ceo_decision' => $approved ? 'approved' : 'declined',
                'ceo_decided_at' => now(),
                'status' => $approved ? 'approved' : 'declined',
            ]);

            if ($approved && ! $locked->is_lwop) {
                LeaveCreditTransaction::create([
                    'employee_id' => $locked->employee_id,
                    'leave_type_id' => $locked->leave_type_id,
                    'transaction_date' => now()->toDateString(),
                    'amount' => -$locked->days_requested,
                    'reason' => 'leave_taken',
                    'leave_request_id' => $locked->id,
                ]);
            }
        });

        $leaveRequest->refresh();

        $this->notifyFinalDecision($leaveRequest, $approved ? 'approved by CEO/COO' : 'declined by CEO/COO', $note);
    }

    protected function notifyFinalDecision(LeaveRequest $leaveRequest, string $outcome, ?string $note): void
    {
        $summary = "Your leave request was {$outcome}." . ($leaveRequest->is_lwop && str_contains($outcome, 'approved') ? ' This will be recorded as Leave Without Pay (LWOP).' : '') . ($note ? " Note: {$note}" : '');

        $this->notifyRequestor($leaveRequest, $summary);
        $this->notifyHr($leaveRequest, $this->employeeName($leaveRequest->employee) . "'s leave request was {$outcome}.");
    }

    protected function notifyRequestor(LeaveRequest $leaveRequest, string $summary): void
    {
        if ($user = $leaveRequest->employee->user) {
            $user->notify(new LeaveRequestStatusUpdated($leaveRequest, $summary));
        }
    }

    protected function notifyHr(LeaveRequest $leaveRequest, string $summary): void
    {
        User::withPermission('leave.view_all')->get()->each(
            fn (User $hrUser) => $hrUser->notify(new LeaveRequestStatusUpdated($leaveRequest, $summary))
        );
    }

    protected function firstCeoUser(): ?User
    {
        return User::withPermission('leave.approve')->first();
    }

    protected function employeeName(Employee $employee): string
    {
        return $employee->fullName() ?: $employee->employee_id;
    }

    /**
     * Monthly accrual for leave types with accrual_mode = monthly_accrual,
     * run for the given date's accrual_day_of_month. Grants monthly_accrual_rate
     * credits to every Regular employee.
     */
    public function runMonthlyAccrual(Carbon $date): int
    {
        $count = 0;

        LeaveType::where('accrual_mode', 'monthly_accrual')
            ->where('accrual_day_of_month', $date->day)
            ->where('is_active', true)
            ->get()
            ->each(function (LeaveType $leaveType) use ($date, &$count) {
                Employee::with('leaveEligibilities')->get()
                    ->each(function (Employee $employee) use ($leaveType, $date, &$count) {
                        $count += $this->accrueOnce($employee, $leaveType, $date) ? 1 : 0;
                    });
            });

        return $count;
    }

    /**
     * Grants one month's credit to one person, if it is theirs to have.
     *
     * Credits accrue from Probationary onward. Somebody who is not yet Regular
     * simply cannot spend them — submit() flags any non-Regular request as
     * LWOP — so by the time they regularize the balance is already waiting.
     *
     * Returns false when nothing was written, which is the normal answer for
     * most employees on most dates.
     */
    protected function accrueOnce(Employee $employee, LeaveType $leaveType, Carbon $date): bool
    {
        // HR can switch an individual off a leave type entirely.
        if (! $employee->isEligibleFor($leaveType)) {
            return false;
        }

        // Nothing accrues for a month somebody had not joined yet. Without
        // this, being hired on the 9th earned a full month's leave on the 10th.
        if ($employee->hire_date && $date->lt($employee->hire_date->startOfDay())) {
            return false;
        }

        // Nor after they have left. Leave was accruing for former staff, and
        // vacation leave turns into cash at year end.
        if ($employee->separation_date && $date->gt($employee->separation_date->startOfDay())) {
            return false;
        }

        /*
         * One credit per person, per type, per date — no matter how many times
         * this runs. The accrual had no such guard, so running it twice on the
         * 10th granted twice, and the back-fill would have re-granted every
         * month it had already covered.
         */
        $exists = LeaveCreditTransaction::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->whereDate('transaction_date', $date->toDateString())
            ->where('reason', 'monthly_accrual')
            ->exists();

        if ($exists) {
            return false;
        }

        LeaveCreditTransaction::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'transaction_date' => $date->toDateString(),
            'amount' => $leaveType->monthly_accrual_rate,
            'reason' => 'monthly_accrual',
        ]);

        return true;
    }

    /**
     * Grants every accrual an employee has already earned but never received.
     *
     * PHREMS started accruing the day its scheduler first ran, so somebody
     * hired in May had nothing to show for May, June and July. This walks each
     * person from their hire date to today and grants the accrual days that
     * have passed — which is what everybody assumed was happening all along.
     *
     * Safe to run more than once: accrueOnce refuses a date it has already
     * credited, so a second run grants nothing.
     */
    public function backfillAccruals(?Carbon $upTo = null): int
    {
        $upTo = ($upTo ?? Carbon::today())->startOfDay();
        $count = 0;

        $types = LeaveType::where('accrual_mode', 'monthly_accrual')
            ->where('is_active', true)
            ->get();

        if ($types->isEmpty()) {
            return 0;
        }

        Employee::with('leaveEligibilities')->get()->each(function (Employee $employee) use ($types, $upTo, &$count) {
            if (! $employee->hire_date) {
                return;
            }

            foreach ($types as $leaveType) {
                foreach ($this->accrualDatesBetween($leaveType, $employee->hire_date, $upTo) as $date) {
                    $count += $this->accrueOnce($employee, $leaveType, $date) ? 1 : 0;
                }
            }
        });

        return $count;
    }

    /**
     * Every accrual day for this leave type from a hire date up to a cut-off.
     *
     * A type set to the 31st still accrues in February: the day is clamped to
     * the end of a short month rather than skipped, so nobody quietly loses
     * four or five days a year to the calendar.
     *
     * @return list<Carbon>
     */
    protected function accrualDatesBetween(LeaveType $leaveType, Carbon $from, Carbon $to): array
    {
        $day = (int) ($leaveType->accrual_day_of_month ?: 1);
        $dates = [];

        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $accrualDate = $cursor->copy()->day(min($day, $cursor->daysInMonth));

            if ($accrualDate->betweenIncluded($from->copy()->startOfDay(), $to)) {
                $dates[] = $accrualDate;
            }

            $cursor->addMonthNoOverflow();
        }

        return $dates;
    }

    /**
     * Annual reset, run each Jan 1st. For leave types that reset_annually
     * (e.g. SL): every Regular employee's balance resets to default_annual_credits.
     * For leave types that don't reset but allow carry-over/cash-conversion (e.g. VL):
     * settle each employee's unused balance per their disposition election, then
     * zero out (cash_out) or keep (carry_over) as the new starting balance.
     */
    public function runAnnualReset(Carbon $date): int
    {
        $count = 0;

        LeaveType::where('is_active', true)->get()->each(function (LeaveType $leaveType) use ($date, &$count) {
            Employee::where('employment_status', 'Regular')->get()->each(function (Employee $employee) use ($leaveType, $date, &$count) {
                if ($leaveType->resets_annually) {
                    $balance = $employee->leaveBalance($leaveType);
                    LeaveCreditTransaction::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'transaction_date' => $date->toDateString(),
                        'amount' => $leaveType->default_annual_credits - $balance,
                        'reason' => 'annual_reset',
                        'note' => "Reset to {$leaveType->default_annual_credits} days",
                    ]);
                    $count++;

                    return;
                }

                if (! $leaveType->allow_carry_over && ! $leaveType->allow_cash_conversion) {
                    return;
                }

                $disposition = $employee->leaveDispositionFor($leaveType);
                $balance = $employee->leaveBalance($leaveType);

                if ($disposition === 'cash_out' && $balance > 0) {
                    LeaveCreditTransaction::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'transaction_date' => $date->toDateString(),
                        'amount' => -$balance,
                        'reason' => 'year_end_cash_conversion',
                        'note' => "Cashed out {$balance} days",
                    ]);
                    $count++;
                }
                // carry_over: balance simply persists, no transaction needed —
                // monthly accrual continues adding on top from here.
            });
        });

        return $count;
    }
}
