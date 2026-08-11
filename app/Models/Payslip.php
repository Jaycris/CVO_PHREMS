<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'employee_snapshot' => 'array',
            'notified_at' => 'datetime',
            'daily_rate' => 'decimal:4',
            'hourly_rate' => 'decimal:4',
            'minute_rate' => 'decimal:4',
            'overtime_hours' => 'decimal:2',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('section')->orderBy('sort_order');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayslipAdjustment::class);
    }

    public function cashAdvancePayments(): HasMany
    {
        return $this->hasMany(CashAdvancePayment::class);
    }

    /** The name as it was when this payslip was produced, not as it is now. */
    public function employeeName(): string
    {
        return $this->employee_snapshot['name']
            ?? ($this->employee?->fullName() ?: ($this->employee?->employee_id ?? 'Unknown'));
    }

    public function employeeCode(): string
    {
        return $this->employee_snapshot['employee_id'] ?? ($this->employee?->employee_id ?? '');
    }

    /**
     * A payslip inherits its run's lock. There is no state in which a payslip
     * is editable while its run is not.
     */
    public function isLocked(): bool
    {
        return $this->payrollRun?->isLocked() ?? false;
    }

    public function earningLines(): HasMany
    {
        return $this->lines()->where('section', 'earning');
    }

    public function deductionLines(): HasMany
    {
        return $this->lines()->where('section', 'deduction');
    }
}
