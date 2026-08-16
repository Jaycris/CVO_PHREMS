<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankDetailRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'bank_name', 'bank_account_name', 'bank_account_number',
        'previous_bank_name', 'previous_bank_account_name', 'previous_bank_account_number',
        'reason', 'status',
        'decided_by_user_id', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Only the last four digits, everywhere the number is shown.
     *
     * An account number on screen is enough for someone walking past to note
     * down, and nobody approving a change needs the whole thing — they need to
     * see that it changed.
     */
    public static function maskAccount(?string $number): string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return '—';
        }

        return strlen($number) <= 4
            ? str_repeat('•', strlen($number))
            : str_repeat('•', strlen($number) - 4) . substr($number, -4);
    }

    public function maskedAccount(): string
    {
        return static::maskAccount($this->bank_account_number);
    }

    public function maskedPreviousAccount(): string
    {
        return static::maskAccount($this->previous_bank_account_number);
    }

    /** True when there was nothing on file — the employee's first entry. */
    public function isFirstEntry(): bool
    {
        return $this->previous_bank_account_number === null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Waiting for HR',
            'approved' => 'Approved',
            'declined' => 'Declined',
            'cancelled' => 'Withdrawn',
            default => ucfirst($this->status),
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'pending' => 'amber',
            'approved' => 'green',
            'declined' => 'red',
            default => 'neutral',
        };
    }
}
