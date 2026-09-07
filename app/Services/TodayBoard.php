<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OffsiteAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What is happening today, gathered into one list.
 *
 * The facts were all already in PHREMS and all in different places: the holiday
 * list knew today was a holiday, the off-site screen knew who was at the
 * exhibit, the employee record knew who joined five years ago today. None of it
 * met the person opening the dashboard, so it travelled by group chat instead.
 *
 * Everything here is read-only and derived. Nothing on this board is stored as
 * a board item — a holiday appears because the holiday list has the date, and
 * disappears at midnight without anybody tidying up. The only thing anyone
 * writes by hand is an announcement, and that carries its own dates.
 *
 * Birthdays are deliberately absent. The dashboard already gives them a card
 * with photographs; repeating them here would push the actual news down.
 */
class TodayBoard
{
    /**
     * The board for one person, most important first.
     *
     * The viewer matters because two of the items are personal: being off-site
     * today is news to the employee it applies to, and a company-wide count of
     * who is off-site is only of use to whoever arranges it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function for(User $user, ?Carbon $today = null): Collection
    {
        $today = ($today ?? now('Asia/Manila'))->copy()->startOfDay();

        return collect()
            ->concat($this->holidays($today))
            ->concat($this->announcements($today))
            ->concat($this->offsite($user, $today))
            ->concat($this->anniversaries($today))
            ->values();
    }

    /**
     * Today's holidays.
     *
     * First on the board, because it is the one item that changes whether
     * somebody is expected at work at all — and a special working day is the
     * case where the answer is "yes, despite the name", which is exactly the
     * thing people get wrong.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function holidays(Carbon $today): Collection
    {
        return Holiday::query()
            ->whereDate('date', $today->toDateString())
            ->ordered()
            ->get()
            ->map(fn (Holiday $holiday) => [
                'icon' => 'calendar',
                'tone' => $holiday->type === Holiday::SPECIAL_WORKING ? 'amber' : 'green',
                'label' => 'Holiday',
                'title' => $holiday->name,
                'detail' => $this->holidayDetail($holiday),
                'url' => null,
            ]);
    }

    /**
     * What the holiday means for the reader, in the terms they care about.
     *
     * Whether they are expected in, and whether the day is paid. The premium
     * for working it is left out on purpose — it is set on a screen only HR can
     * open, and quoting a rate here invites an argument on the payslip.
     */
    protected function holidayDetail(Holiday $holiday): string
    {
        $observance = $holiday->observance === Holiday::PHILIPPINES
            ? ''
            : ' (' . $holiday->observanceLabel() . ')';

        return match ($holiday->type) {
            Holiday::SPECIAL_WORKING => $holiday->typeLabel() . $observance . ' — a normal working day. Clock in as usual.',
            Holiday::REGULAR => $holiday->typeLabel() . $observance . ' — paid whether or not it is worked.',
            default => $holiday->typeLabel() . $observance . ' — nobody is expected in, and the day is still paid.',
        };
    }

    /**
     * Notices somebody wrote, covering today.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function announcements(Carbon $today): Collection
    {
        return Announcement::query()
            ->liveOn($today)
            ->forBoard()
            ->get()
            ->map(fn (Announcement $announcement) => [
                'icon' => $announcement->icon(),
                'tone' => $announcement->kindColor(),
                'label' => $announcement->kindLabel(),
                'title' => $announcement->title,
                'detail' => $announcement->body,
                'pinned' => $announcement->is_pinned,
                'url' => route('announcements.index'),
            ]);
    }

    /**
     * Off-site work covering today.
     *
     * Two different readers want two different things here. The employee needs
     * telling that they are not expected to clock in — they are already emailed
     * when the days are set, but an email read a week ago is not what somebody
     * is thinking about at eight in the morning. Whoever arranges it needs the
     * count, so a booth with nobody rostered on it is visible before the day is
     * over rather than after payroll.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function offsite(User $user, Carbon $today): Collection
    {
        $items = collect();

        $assignments = OffsiteAssignment::query()
            ->with('employee')
            ->overlapping($today, $today)
            ->get();

        if ($assignments->isEmpty()) {
            return $items;
        }

        $mine = $user->employee
            ? $assignments->firstWhere('employee_id', $user->employee->id)
            : null;

        if ($mine) {
            $items->push([
                'icon' => 'building',
                'tone' => 'brand',
                'label' => $mine->kindLabel(),
                'title' => $mine->isDayOff()
                    ? 'Your day off in lieu is today'
                    : 'You are working off-site today',
                'detail' => $mine->isDayOff()
                    ? $mine->reason . '. No need to clock in — the day is paid.'
                    : $mine->reason . '. No need to clock in — the day is paid and you will not be marked absent.',
                'url' => route('attendance.punch'),
            ]);
        }

        if ($user->can('attendance.offsite.manage')) {
            /*
             * Grouped by what it is rather than listed per person. Six people
             * on one exhibit is one line worth reading; six lines saying the
             * same thing is a list nobody finishes.
             */
            foreach ($assignments->groupBy('reason') as $reason => $group) {
                $items->push([
                    'icon' => 'people-group',
                    'tone' => 'neutral',
                    'label' => 'Off-site today',
                    'title' => $reason,
                    'detail' => $this->names($group->map(fn (OffsiteAssignment $a) => $a->employee)),
                    'url' => route('attendance.offsite'),
                ]);
            }
        }

        return $items;
    }

    /**
     * Work anniversaries falling today.
     *
     * The day somebody joined, not the day they were hired into their current
     * role — so it survives a promotion, which is the point of marking it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function anniversaries(Carbon $today): Collection
    {
        return Employee::query()
            ->whereNotNull('hire_date')
            ->whereMonth('hire_date', $today->month)
            ->whereDay('hire_date', $today->day)
            ->whereYear('hire_date', '<', $today->year)
            ->whereNull('separation_date')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (Employee $employee) use ($today) {
                $years = $employee->hire_date->diffInYears($today);

                return [
                    'icon' => 'trending-up',
                    'tone' => 'blue',
                    'label' => 'Work anniversary',
                    'title' => $employee->fullName() ?: $employee->employee_id,
                    'detail' => $years . ' ' . ($years === 1 ? 'year' : 'years') . ' with CreatiVision today.',
                    'url' => null,
                ];
            });
    }

    /**
     * A readable list of people, trimmed once it stops being readable.
     *
     * @param  Collection<int, Employee|null>  $employees
     */
    protected function names(Collection $employees): string
    {
        $names = $employees
            ->filter()
            ->map(fn (Employee $e) => $e->fullName() ?: $e->employee_id)
            ->values();

        if ($names->count() <= 4) {
            return $names->join(', ', ' and ');
        }

        return $names->take(3)->join(', ') . ' and ' . ($names->count() - 3) . ' others';
    }
}
