<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'title' => 'Notice ' . fake()->unique()->numberBetween(1, 9999),
            'body' => 'Something the company wants everybody to know.',
            'kind' => Announcement::NEWS,
            'starts_on' => now()->toDateString(),
            'ends_on' => null,
            'is_pinned' => false,
            'published_at' => now(),
            'created_by_user_id' => null,
        ];
    }

    /** Written but not posted — nobody but its author can see it. */
    public function draft(): static
    {
        return $this->state(fn () => ['published_at' => null]);
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }

    /** Runs between two dates, the way an event does. */
    public function between(string $start, string $end): static
    {
        return $this->state(fn () => ['starts_on' => $start, 'ends_on' => $end]);
    }
}
