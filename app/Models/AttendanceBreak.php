<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stretch of a day somebody was away from their desk.
 *
 * The kind is a label, not a rule. Pay still works from the day's total against
 * the schedule's allowance, exactly as it did when every break was just "a
 * break" — what this adds is the ability to answer "what was that forty
 * minutes?" without asking the employee.
 */
class AttendanceBreak extends Model
{
    public const LUNCH = 'lunch';

    public const COFFEE = 'coffee';

    public const RESTROOM = 'restroom';

    /**
     * Ordered as they are offered on the punch clock.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        self::LUNCH => 'Lunch Break',
        self::COFFEE => 'Coffee Break',
        self::RESTROOM => 'Rest Room Break',
    ];

    protected $fillable = [
        'attendance_day_id',
        'kind',
        'break_start',
        'break_end',
    ];

    protected function casts(): array
    {
        return [
            'break_start' => 'datetime',
            'break_end' => 'datetime',
        ];
    }

    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    /**
     * Breaks punched before kinds existed genuinely have none.
     *
     * Shown as "Break" rather than guessed at — filling one in would be
     * inventing evidence about somebody's day.
     */
    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? 'Break';
    }

    /** Minutes taken. An unfinished break is still running, so it counts to now. */
    public function minutes(): int
    {
        return (int) floor($this->break_start->diffInMinutes($this->break_end ?? now()));
    }

    public static function isKind(?string $kind): bool
    {
        return $kind !== null && array_key_exists($kind, self::KINDS);
    }
}
