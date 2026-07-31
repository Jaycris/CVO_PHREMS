<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Employee extends Model
{
    protected $fillable = [
        'employee_id',
        'phone_name',
        'company_email',
        'position_id',
        'department_id',
        'hire_date',
        'basic_salary',
        'allowance',
        'commission_scheme',
        'quota',
        'employment_status',
        'reports_to_id',
        'first_name',
        'middle_name',
        'last_name',
        'birthdate',
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
        'onboarding_completed_at',
        'user_id',
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
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} " . ($this->middle_name ? "{$this->middle_name} " : '') . $this->last_name);
    }

    public function isRegular(): bool
    {
        return $this->employment_status === 'Regular';
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
