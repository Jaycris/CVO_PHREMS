<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text sent to the whole company.
 *
 * The record of a thing that otherwise leaves no trace: the message is on
 * fifty-two handsets and nowhere else, and the only other evidence is a line on
 * a gateway bill.
 */
class SmsBroadcast extends Model
{
    use HasFactory;

    protected $fillable = [
        'message',
        'recipients_attempted',
        'recipients_sent',
        'recipients_skipped',
        'employee_ids',
        'sent_by_user_id',
    ];

    protected function casts(): array
    {
        return ['employee_ids' => 'array'];
    }

    /** Whether it went to the whole company rather than a chosen few. */
    public function wentToEverybody(): bool
    {
        return $this->employee_ids === null;
    }

    public function audienceLabel(): string
    {
        if ($this->wentToEverybody()) {
            return 'Everybody';
        }

        $count = count($this->employee_ids ?? []);

        return $count . ' chosen ' . ($count === 1 ? 'person' : 'people');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /** Whether the gateway took every one of them. */
    public function reachedEverybody(): bool
    {
        return $this->recipients_sent === $this->recipients_attempted;
    }

    /**
     * What happened, in one line, for the history list.
     *
     * Says the shortfall plainly rather than showing three numbers and leaving
     * somebody to subtract — the useful question is "did it reach everyone",
     * and if not, how many it missed.
     */
    public function outcomeLabel(): string
    {
        $sent = $this->recipients_sent . ' sent';

        $failed = $this->recipients_attempted - $this->recipients_sent;

        $parts = [];

        if ($failed > 0) {
            $parts[] = $failed . ' refused by the gateway';
        }

        if ($this->recipients_skipped > 0) {
            $parts[] = $this->recipients_skipped . ' with no usable mobile';
        }

        return $parts === [] ? $sent : $sent . ', ' . implode(', ', $parts);
    }
}
