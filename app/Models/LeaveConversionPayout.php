<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveConversionPayout extends Model
{
    protected $fillable = [
        'leave_credit_transaction_id', 'employee_id', 'payroll_run_id', 'payslip_id',
        'days', 'daily_rate', 'amount', 'for_year',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'daily_rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    public function leaveCreditTransaction(): BelongsTo
    {
        return $this->belongsTo(LeaveCreditTransaction::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
