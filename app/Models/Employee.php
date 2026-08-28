<?php

namespace App\Models;

use App\Support\GeneratesReferenceCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    use HasFactory;

    use GeneratesReferenceCode;

    public static function generateEmployeeId(): string
    {
        return static::generateReferenceCode('EMP', 'employee_id');
    }

    /** Guarantees an ID even when a record is created outside the HR form. */
    protected static function booted(): void
    {
        static::creating(function (self $employee): void {
            $employee->employee_id ??= static::generateEmployeeId();
        });
    }

    protected $fillable = [
        'employee_id',
        'phone_name',
        'workplace_type',
        'tracks_attendance',
        'employment_type',
        'company_email',
        'position_id',
        'department_id',
        'hire_date',
        'basic_salary',
        'allowance',
        'commission_scheme',
        'commission_frequency',
        'quota',
        'employment_status',
        'reports_to_id',
        'first_name',
        'middle_name',
        'last_name',
        'birthdate',
        'gender',
        'photo_path',
        'address',
        'personal_contact_number',
        'personal_email',
        'civil_status',
        'emergency_contact_name',
        'emergency_contact_number',
        'tin_number',
        'sss_number',
        'philhealth_number',
        'pagibig_number',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_details_updated_at',
        'onboarding_completed_at',
        'user_id',
        'separation_date',
        'separation_type',
        'separation_reason',
        'include_in_payroll',
        'sss_enrolled',
        'philhealth_enrolled',
        'pagibig_enrolled',
        'bir_withholding_enrolled',
        'allowance_taxable',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'birthdate' => 'date',
            'basic_salary' => 'decimal:2',
            'allowance' => 'decimal:2',
            'quota' => 'decimal:2',
            'onboarding_completed_at' => 'datetime',
            'bank_details_updated_at' => 'datetime',
            'separation_date' => 'date',
            'tracks_attendance' => 'boolean',
            'include_in_payroll' => 'boolean',
            'sss_enrolled' => 'boolean',
            'philhealth_enrolled' => 'boolean',
            'pagibig_enrolled' => 'boolean',
            'bir_withholding_enrolled' => 'boolean',
            'allowance_taxable' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} " . ($this->middle_name ? "{$this->middle_name} " : '') . $this->last_name);
    }

    /** Public URL for the profile photo, or null when none has been uploaded. */
    public function photoUrl(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        // Guards against a row pointing at a file that was removed on disk,
        // which would otherwise render a broken image.
        return Storage::disk('public')->exists($this->photo_path)
            ? asset('storage/' . ltrim($this->photo_path, '/'))
            : null;
    }

    /** Up to two letters for the avatar fallback. */
    public function initials(): string
    {
        $first = mb_substr(trim((string) $this->first_name), 0, 1);
        $last = mb_substr(trim((string) $this->last_name), 0, 1);
        $initials = mb_strtoupper($first . $last);

        return $initials !== '' ? $initials : mb_strtoupper(mb_substr($this->employee_id ?? '?', 0, 2));
    }

    public function isRegular(): bool
    {
        return $this->employment_status === 'Regular';
    }

    public function isSeparated(Carbon|string|null $asOf = null): bool
    {
        if ($this->separation_date === null) {
            return false;
        }

        return $this->separation_date->lte(Carbon::parse($asOf ?? Carbon::today()));
    }

    /** How somebody left. The reason field explains it; this classifies it. */
    public const SEPARATION_TYPES = [
        'resigned' => 'Resigned',
        'terminated' => 'Terminated',
        'end_of_contract' => 'End of Contract',
        'retired' => 'Retired',
    ];

    /**
     * Whether the person is still with the company, in one word.
     *
     * Separate from employment_status, which says Regular or Probationary —
     * that is what kind of employee somebody is, not whether they still work
     * here. A separated employee kept their Regular status on the way out, so
     * the directory was showing a green "Regular" badge for people who had
     * left months ago.
     *
     * A separation date in the future reads as Active until it arrives, which
     * is what lets HR file a resignation in advance without cutting somebody
     * off from the system on the day they hand in their notice.
     */
    public function statusLabel(Carbon|string|null $asOf = null): string
    {
        if (! $this->isSeparated($asOf)) {
            return $this->separation_date ? 'Leaving' : 'Active';
        }

        return self::SEPARATION_TYPES[$this->separation_type] ?? 'Separated';
    }

    public function statusColor(Carbon|string|null $asOf = null): string
    {
        if (! $this->isSeparated($asOf)) {
            return $this->separation_date ? 'amber' : 'green';
        }

        return $this->separation_type === 'terminated' ? 'red' : 'neutral';
    }

    /**
     * Whether this employee should appear on a payroll run covering the period.
     * Someone hired mid-cutoff or separated mid-cutoff still belongs on the run;
     * the aggregator clamps their date range.
     */
    public function isPayrollEligibleFor(Carbon|string $periodStart, Carbon|string $periodEnd): bool
    {
        if (! $this->include_in_payroll) {
            return false;
        }

        $start = Carbon::parse($periodStart)->startOfDay();
        $end = Carbon::parse($periodEnd)->startOfDay();

        if ($this->hire_date && $this->hire_date->gt($end)) {
            return false;
        }

        return $this->separation_date === null || $this->separation_date->gte($start);
    }

    public function scopeForPayroll(Builder $query, Carbon|string $periodStart, Carbon|string $periodEnd): Builder
    {
        $start = Carbon::parse($periodStart)->toDateString();
        $end = Carbon::parse($periodEnd)->toDateString();

        return $query->where('include_in_payroll', true)
            ->where(fn (Builder $q) => $q->whereNull('hire_date')->orWhere('hire_date', '<=', $end))
            ->where(fn (Builder $q) => $q->whereNull('separation_date')->orWhere('separation_date', '>=', $start));
    }

    /*
     * Rate helpers. basic_salary is cast decimal:2, which Laravel returns as a
     * STRING — every caller must coerce to float or strict comparisons and
     * round() behave unexpectedly.
     */

    /**
     * A day's pay. Prices an absence directly, and lateness and overtime
     * through the hour and minute rates below.
     *
     * $workingDays is how many days the employee was actually scheduled for the
     * period being paid. Passing it makes the rate self-correcting: a cutoff
     * with 10 scheduled days prices each at a tenth of the half-salary, so
     * missing all ten deducts exactly the half-salary and lands on zero. That
     * holds in a 20-day February and a 23-day July alike, which no fixed
     * divisor manages — 22 leaves 1,818 behind in February and overdraws by
     * 909 in July.
     *
     * The cost is that a day is worth more in a short month than a long one,
     * and overtime moves with it. That is the company's decision, recorded as
     * daily_rate_basis.
     *
     * Omitting it falls back to the fixed divisor, which is what screens
     * showing a rate outside any payroll period want.
     */
    public function dailyRate(?int $workingDays = null): float
    {
        $salary = (float) $this->basic_salary;

        if ($workingDays !== null
            && $workingDays > 0
            && PayrollSetting::get('daily_rate_basis', 'actual') === 'actual') {
            // Each cutoff pays half the monthly salary, so the day rate is that
            // half spread over the days this cutoff actually scheduled.
            return ($salary / 2) / $workingDays;
        }

        $divisor = PayrollSetting::number('daily_rate_divisor', 22);

        return $divisor > 0 ? $salary / $divisor : 0.0;
    }

    /** Hours in a working day — a 12-hour shift is not an 8-hour one. */
    public function hourlyRate(?int $workingDays = null): float
    {
        $hours = PayrollSetting::number('hours_per_day', 8);

        return $hours > 0 ? $this->dailyRate($workingDays) / $hours : 0.0;
    }

    public function minuteRate(?int $workingDays = null): float
    {
        return $this->hourlyRate($workingDays) / 60;
    }

    /**
     * Employees who can appear in a Reports To list — those holding a position
     * flagged supervisory (Team Leader, Manager, COO, CEO). Separated staff are
     * excluded so leave approvals never route to someone who has left.
     */
    public function scopeSupervisors(Builder $query): Builder
    {
        return $query->whereHas('position', fn (Builder $p) => $p->where('is_supervisory', true))
            ->whereNull('separation_date');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $term === ''
            ? $query
            : $query->where(function (Builder $q) use ($term) {
                $q->where('employee_id', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('phone_name', 'like', "%{$term}%")
                    ->orWhere('company_email', 'like', "%{$term}%")
                    ->orWhereHas('department', fn (Builder $department) => $department->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('position', fn (Builder $position) => $position->where('title', 'like', "%{$term}%"));
            });
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'reports_to_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    public function bankDetailRequests(): HasMany
    {
        return $this->hasMany(BankDetailRequest::class);
    }

    public function hasBankDetails(): bool
    {
        return filled($this->bank_account_number);
    }

    /** Only the last four digits — see BankDetailRequest::maskAccount(). */
    public function maskedBankAccount(): string
    {
        return BankDetailRequest::maskAccount($this->bank_account_number);
    }

    public function leaveCreditTransactions(): HasMany
    {
        return $this->hasMany(LeaveCreditTransaction::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function leaveDispositions(): HasMany
    {
        return $this->hasMany(EmployeeLeaveDisposition::class);
    }

    public function leaveEligibilities(): HasMany
    {
        return $this->hasMany(EmployeeLeaveEligibility::class);
    }

    /**
     * Whether this employee is entitled to a leave type at all.
     *
     * With no explicit record, event-based types (Maternity, Paternity) are
     * NOT granted — they depend on a qualifying life event, so handing them to
     * everyone by default would be wrong. Every other type stays eligible so
     * existing SL/VL behaviour is untouched.
     */
    public function isEligibleFor(LeaveType $leaveType): bool
    {
        $explicit = $this->leaveEligibilities()
            ->where('leave_type_id', $leaveType->id)
            ->value('is_eligible');

        if ($explicit !== null) {
            return (bool) $explicit;
        }

        return $leaveType->accrual_mode !== 'event_based';
    }

    public function leaveBalance(LeaveType $leaveType): float
    {
        return (float) $this->leaveCreditTransactions()
            ->where('leave_type_id', $leaveType->id)
            ->sum('amount');
    }

    public function leaveDispositionFor(LeaveType $leaveType): string
    {
        return $this->leaveDispositions()
            ->where('leave_type_id', $leaveType->id)
            ->value('disposition') ?? 'carry_over';
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeScheduleAssignment::class)
            ->orderByDesc('effective_start_date')
            ->orderByDesc('id');
    }

    public function currentScheduleAssignment(): ?EmployeeScheduleAssignment
    {
        return $this->scheduleAssignmentForDate(Carbon::today());
    }

    public function scheduleAssignmentForDate(Carbon|string $date): ?EmployeeScheduleAssignment
    {
        $date = Carbon::parse($date)->toDateString();

        return $this->scheduleAssignments()
            ->where('effective_start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_end_date')->orWhere('effective_end_date', '>=', $date))
            ->first();
    }

    public function assignSchedule(WorkSchedule $schedule, string $effectiveStartDate): EmployeeScheduleAssignment
    {
        $startDate = Carbon::parse($effectiveStartDate)->startOfDay();

        return DB::transaction(function () use ($schedule, $startDate) {
            // A reassignment on the same effective date replaces the previous one
            // rather than stacking a second open-ended row on top of it.
            $this->scheduleAssignments()
                ->whereDate('effective_start_date', $startDate)
                ->delete();

            // Close any still-open assignment that began before this one.
            $this->scheduleAssignments()
                ->whereNull('effective_end_date')
                ->whereDate('effective_start_date', '<', $startDate)
                ->update(['effective_end_date' => $startDate->copy()->subDay()]);

            return $this->scheduleAssignments()->create([
                'work_schedule_id' => $schedule->id,
                'effective_start_date' => $startDate,
                'effective_end_date' => null,
            ]);
        });
    }
}
