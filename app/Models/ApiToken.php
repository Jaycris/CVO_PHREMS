<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * A token the CRM uses to call this app's employee lookup.
 *
 * The plaintext exists exactly once — in the return value of issue(), on its way
 * to the screen. After that only its hash is here, so nobody, including an
 * admin with database access, can read a token back out. Losing one means
 * issuing another, which is the correct trade.
 */
class ApiToken extends Model
{
    /** Marks these as ours at a glance, in a log or a CRM config field. */
    public const PREFIX = 'hris_';

    protected $fillable = [
        'name', 'token_hash', 'token_hint',
        'last_used_at', 'revoked_at',
        'created_by_user_id', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Issues a new token.
     *
     * @return array{token: ApiToken, plaintext: string}
     */
    public static function issue(string $name): array
    {
        $plaintext = self::PREFIX . Str::random(48);
        $user = Auth::user();

        $token = static::create([
            'name' => trim($name) !== '' ? trim($name) : 'CRM',
            'token_hash' => static::hash($plaintext),
            'token_hint' => substr($plaintext, 0, 10) . '…' . substr($plaintext, -4),
            'created_by_user_id' => $user?->id,
            // Kept as text too, so the answer to "who issued this" survives the
            // account being deleted.
            'created_by_name' => $user?->name,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }

    /** The live token matching a presented secret, or null. */
    public static function findByPlaintext(string $plaintext): ?self
    {
        if (! str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        return static::active()->where('token_hash', static::hash($plaintext))->first();
    }

    /**
     * Records that the token was just used.
     *
     * Written at most once a minute. Stamping every call would put a write in
     * front of every type-ahead keystroke the CRM's search box makes, and the
     * question this answers — has the CRM ever reached us, and roughly when —
     * does not need the precision.
     */
    public function touchUsage(): void
    {
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    protected static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
