<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReimbursementRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id', 'amount_requested', 'amount_approved',
        'expense_date', 'category', 'description', 'receipt_path', 'status',
        'decided_by_user_id', 'decided_at', 'decision_note',
        'payroll_run_id', 'payslip_id', 'paid_on',
    ];

    protected function casts(): array
    {
        return [
            'amount_requested' => 'decimal:2',
            'amount_approved' => 'decimal:2',
            'expense_date' => 'date',
            'decided_at' => 'datetime',
            'paid_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** Approved but not yet on a payslip — what the next payroll picks up. */
    public function scopeAwaitingPayment(Builder $query): Builder
    {
        return $query->where('status', 'approved')->whereNull('payslip_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->payslip_id !== null;
    }

    /** What will be paid back, falling back to what was claimed. */
    public function effectiveAmount(): float
    {
        return (float) ($this->amount_approved ?? $this->amount_requested);
    }

    public function wasReduced(): bool
    {
        return $this->amount_approved !== null
            && (float) $this->amount_approved < (float) $this->amount_requested;
    }

    /** @return array<string, string> */
    public static function categories(): array
    {
        return [
            'travel' => 'Travel and transport',
            'meals' => 'Meals',
            'supplies' => 'Supplies and equipment',
            'communication' => 'Load and internet',
            'client' => 'Client expenses',
            'other' => 'Other',
        ];
    }

    public function categoryLabel(): string
    {
        return static::categories()[$this->category] ?? $this->category;
    }

    public function statusLabel(): string
    {
        if ($this->isPaid()) {
            return 'Paid';
        }

        return match ($this->status) {
            'pending' => 'Pending Approval',
            'approved' => 'Approved — on the next payroll',
            'declined' => 'Declined',
            'cancelled' => 'Cancelled',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        if ($this->isPaid()) {
            return 'green';
        }

        return match ($this->status) {
            'approved' => 'brand',
            'declined' => 'red',
            'cancelled' => 'neutral',
            default => 'amber',
        };
    }
}
