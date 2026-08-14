<?php

namespace Database\Factories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceDayFactory extends Factory
{
    /** A full 9-to-6 day, on time. */
    public function definition(): array
    {
        $date = '2026-08-03';

        return [
            'employee_id' => Employee::factory(),
            'work_date' => $date,
            'time_in' => $date . ' 09:00:00',
            'time_out' => $date . ' 18:00:00',
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'work_date' => $date,
            'time_in' => $date . ' 09:00:00',
            'time_out' => $date . ' 18:00:00',
        ]);
    }

    public function lateBy(int $minutes): static
    {
        return $this->state(function (array $attributes) use ($minutes) {
            $date = $attributes['work_date'];
            $in = \Illuminate\Support\Carbon::parse($date . ' 09:00:00')->addMinutes($minutes);

            return ['time_in' => $in];
        });
    }

    /** Clocked in and never out — the case preflight must block on. */
    public function unclosed(): static
    {
        return $this->state(['time_out' => null]);
    }

    public function graveyardOn(string $date): static
    {
        return $this->state([
            'work_date' => $date,
            'time_in' => $date . ' 22:00:00',
            'time_out' => \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString() . ' 07:00:00',
        ]);
    }
}
