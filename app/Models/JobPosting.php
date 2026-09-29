<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One advert for a role, written by HR and read by the company website.
 *
 * Draft is invisible to the world, Published is on the website, Closed is off
 * it again. A closing date does the same thing on its own, so a role nobody
 * remembered to take down stops advertising itself.
 */
class JobPosting extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const CLOSED = 'closed';

    protected $fillable = [
        'title', 'slug', 'summary', 'description', 'responsibilities', 'qualifications',
        'department_id', 'position_id', 'employment_type', 'workplace_type', 'location',
        'headcount', 'salary_min', 'salary_max', 'salary_visible',
        'apply_email', 'apply_url', 'status', 'published_at', 'closes_on', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'salary_visible' => 'boolean',
            'published_at' => 'datetime',
            'closes_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (JobPosting $posting) {
            $posting->slug = $posting->slug ?: self::uniqueSlug($posting->title, $posting->id);
        });
    }

    /**
     * A web address for the title, kept unique.
     *
     * Two "Sales Agent" adverts a year apart would otherwise collide, and the
     * second would quietly overwrite the first's link.
     */
    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (self::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        return $slug;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** What the website is allowed to see: published, and not past its closing date. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)
            ->where(fn (Builder $q) => $q->whereNull('closes_on')->orWhereDate('closes_on', '>=', now()->toDateString()));
    }

    public function isLive(): bool
    {
        return $this->status === self::PUBLISHED
            && ($this->closes_on === null || $this->closes_on->gte(Carbon::today()));
    }

    /** Published, but the closing date has passed — off the website, still here. */
    public function hasExpired(): bool
    {
        return $this->status === self::PUBLISHED && ! $this->isLive();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::CLOSED => 'Closed',
            $this->hasExpired() => 'Expired',
            $this->status === self::PUBLISHED => 'Published',
            default => 'Draft',
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->status === self::CLOSED => 'neutral',
            $this->hasExpired() => 'amber',
            $this->status === self::PUBLISHED => 'green',
            default => 'neutral',
        };
    }

    public function salaryRange(): ?string
    {
        if ($this->salary_min === null && $this->salary_max === null) {
            return null;
        }

        $money = fn ($amount) => '₱' . number_format((float) $amount, 0);

        return match (true) {
            $this->salary_max === null => 'From ' . $money($this->salary_min),
            $this->salary_min === null => 'Up to ' . $money($this->salary_max),
            default => $money($this->salary_min) . ' - ' . $money($this->salary_max),
        };
    }

    /**
     * The only shape of a posting that reaches the public internet.
     *
     * An allow-list, like CrmSafeEmployee: a column added here later cannot
     * appear on the website until somebody writes a line for it. The salary is
     * left out entirely unless HR ticked that it may be shown.
     *
     * @return array<string, mixed>
     */
    public function forWebsite(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'description' => $this->description,
            'responsibilities' => $this->responsibilities,
            'qualifications' => $this->qualifications,
            'department' => $this->department?->name,
            'employment_type' => $this->employment_type,
            'workplace_type' => $this->workplace_type,
            'location' => $this->location,
            'openings' => (int) $this->headcount,
            'salary_range' => $this->salary_visible ? $this->salaryRange() : null,
            'apply_email' => $this->apply_email,
            'apply_url' => $this->apply_url,
            'posted_on' => $this->published_at?->toDateString(),
            'closes_on' => $this->closes_on?->toDateString(),
        ];
    }
}
