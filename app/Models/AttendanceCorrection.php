<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_day_id',
        'employee_id',
        'work_date',
        'user_id',
        'before',
        'after',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The fields that actually moved, as "Time in 5:33 AM → 9:00 PM".
     *
     * Reads off the two snapshots rather than the day as it stands now, so a
     * later edit does not rewrite what an earlier one is shown to have done.
     *
     * @return list<string>
     */
    public function changedLines(): array
    {
        $labels = [
            'time_in' => 'Time in',
            'time_out' => 'Time out',
        ];

        $lines = [];

        foreach ($labels as $field => $label) {
            $was = $this->before[$field] ?? null;
            $now = $this->after[$field] ?? null;

            if ($was === $now) {
                continue;
            }

            $lines[] = $label . ' ' . self::moment($was) . ' → ' . self::moment($now);
        }

        return $lines;
    }

    /** An empty field reads as "none", not as a blank gap in the sentence. */
    protected static function moment(?string $value): string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->format('g:i A') : 'none';
    }
}
