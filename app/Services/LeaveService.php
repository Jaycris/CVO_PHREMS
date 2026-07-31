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

class LeaveService
{
    public function submit(Employee $employee, LeaveType $leaveType, string $startDate, string $endDate, ?string $reason): LeaveRequest
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $daysRequested = $start->diffInDays($end) + 1;

        $balance = $employee->leaveBalance($leaveType);
        $isLwop = ! $employee->isRegular() || $balance < $daysRequested;

        $manager = $employee->reportsTo;

        $leaveRequest = LeaveRequest::create([
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
        abort_unless($leaveRequest->status === 'pending_manager', 403, 'This request is not awaiting manager approval.');

        $leaveRequest->update([
            'manager_decision' => $approved ? 'approved' : 'declined',
            'manager_decided_at' => now(),
            'status' => $approved ? 'pending_ceo' : 'declined',
        ]);

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
        abort_unless($leaveRequest->status === 'pending_ceo', 403, 'This request is not awaiting CEO/COO approval.');

        $leaveRequest->update([
            'ceo_id' => $ceoActor->id,
            'ceo_decision' => $approved ? 'approved' : 'declined',
            'ceo_decided_at' => now(),
            'status' => $approved ? 'approved' : 'declined',
        ]);

        if ($approved && ! $leaveRequest->is_lwop) {
            LeaveCreditTransaction::create([
                'employee_id' => $leaveRequest->employee_id,
                'leave_type_id' => $leaveRequest->leave_type_id,
                'transaction_date' => now()->toDateString(),
                'amount' => -$leaveRequest->days_requested,
                'reason' => 'leave_taken',
                'leave_request_id' => $leaveRequest->id,
            ]);
        }

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
        User::role('HR')->get()->each(
            fn (User $hrUser) => $hrUser->notify(new LeaveRequestStatusUpdated($leaveRequest, $summary))
        );
    }

    protected function firstCeoUser(): ?User
    {
        return User::role('CEO')->first();
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
                // Credits accrue from Probationary onward. Employees who are not yet
                // Regular simply cannot spend them — LeaveService::submit() flags any
                // non-Regular request as LWOP — so by the time they regularize the
                // balance is already waiting for them.
                Employee::query()->get()->each(function (Employee $employee) use ($leaveType, $date, &$count) {
                    LeaveCreditTransaction::create([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $leaveType->id,
                        'transaction_date' => $date->toDateString(),
                        'amount' => $leaveType->monthly_accrual_rate,
                        'reason' => 'monthly_accrual',
                    ]);
                    $count++;
                });
            });

        return $count;
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
