<?php

namespace App\Console\Commands;

use App\Models\EmployeeScheduleAssignment;
use App\Models\WorkSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a schedule whose hours were edited in place.
 *
 * Night Shift was changed from 22:00-06:00 to 22:00-07:00 on 3 November. A
 * schedule is one shared record, so that edit reached every day anybody had
 * ever worked on it: six people's October nights, which ended at 06:00 as
 * asked, read as an hour short each and PHP 3,154.82 was docked between them.
 *
 * Editing it back is no fix — either value is wrong for one half of the year.
 * The hours have to belong to a date range, so the old record goes back to
 * what it was and the new hours become a schedule of their own from the day
 * they took effect.
 *
 * Existing assignments are left alone. A day resolves to the assignment with
 * the latest start date, so one new assignment from the changeover date
 * covers everything after it without touching the tangle of overlapping rows
 * that has built up — those are somebody's history and not this command's to
 * tidy.
 */
class SplitEditedScheduleCommand extends Command
{
    protected $signature = 'phrems:split-edited-schedule
        {schedule : The id of the schedule whose hours were edited}
        {from : The date the new hours took effect, YYYY-MM-DD}
        {--restore-end= : Put the old schedule back to this end time, e.g. 06:00}
        {--name= : Name for the new schedule}
        {--apply : Write the changes, instead of only listing them}';

    protected $description = 'Give edited schedule hours their own record from a date, leaving history intact';

    public function handle(): int
    {
        $old = WorkSchedule::find($this->argument('schedule'));

        if (! $old) {
            $this->error('No schedule with that id.');

            return self::FAILURE;
        }

        $from = Carbon::parse($this->argument('from'))->startOfDay();
        $restoreEnd = $this->option('restore-end');
        $newName = $this->option('name') ?: $old->name . ' (from ' . $from->format('M j') . ')';

        $spanning = $this->spanning($old->id, $from);
        $after = EmployeeScheduleAssignment::where('work_schedule_id', $old->id)
            ->whereDate('effective_start_date', '>=', $from->toDateString())
            ->with('employee')
            ->get();

        $this->line('Schedule: ' . $old->name . ' ' . $old->start_time->format('H:i') . '-' . $old->end_time->format('H:i'));
        $this->line('New schedule: ' . $newName . ' ' . $old->start_time->format('H:i') . '-' . $old->end_time->format('H:i'));

        if ($restoreEnd) {
            $this->line('Old schedule end time goes back to ' . $restoreEnd);
        }

        $this->newLine();
        $this->table(
            ['What', 'Employee', 'Detail'],
            $spanning->map(fn ($a) => ['new assignment', optional($a->employee)->employee_id, 'from ' . $from->toDateString()])
                ->concat($after->map(fn ($a) => ['repoint', optional($a->employee)->employee_id, $a->effective_start_date->toDateString() . ' onward']))
                ->all(),
        );

        if (! $this->option('apply')) {
            $this->warn($spanning->count() . ' new assignment(s) and ' . $after->count() . ' repoint(s). Nothing has been changed.');
            $this->line('Run it again with --apply to write them.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($old, $from, $restoreEnd, $newName, $spanning, $after) {
            $new = WorkSchedule::create([
                'name' => $newName,
                'start_time' => $old->start_time->format('H:i'),
                'end_time' => $old->end_time->format('H:i'),
                'lunch_break_minutes' => $old->lunch_break_minutes,
                'coffee_break_minutes' => $old->coffee_break_minutes,
                'work_days' => $old->workDays(),
                'night_differential_eligible' => $old->night_differential_eligible,
            ]);

            // Put the old record back to the hours that were really in force
            // before the edit, so every day before the changeover is judged
            // against what the employee was actually asked to work.
            if ($restoreEnd) {
                $old->update(['end_time' => $restoreEnd]);
            }

            $after->each(fn (EmployeeScheduleAssignment $a) => $a->update(['work_schedule_id' => $new->id]));

            foreach ($spanning as $assignment) {
                $assignment->employee?->scheduleAssignments()->create([
                    'work_schedule_id' => $new->id,
                    'effective_start_date' => $from->toDateString(),
                    'effective_end_date' => $assignment->effective_end_date,
                ]);
            }

            $this->info('Created schedule ' . $new->id . ', ' . $spanning->count() . ' new assignment(s), ' . $after->count() . ' repointed.');
        });

        $this->warn('Recompute any unfinalized payroll run covering these dates.');

        return self::SUCCESS;
    }

    /**
     * One assignment per employee whose cover crosses the changeover.
     *
     * Several overlapping rows can cover the same person — the resolver takes
     * the latest start and ignores the rest — so adding one new assignment
     * each is enough, and adding one per row would pile more onto a set that
     * is already hard to read.
     *
     * @return \Illuminate\Support\Collection<int, EmployeeScheduleAssignment>
     */
    protected function spanning(int $scheduleId, Carbon $from): \Illuminate\Support\Collection
    {
        return EmployeeScheduleAssignment::where('work_schedule_id', $scheduleId)
            ->whereDate('effective_start_date', '<', $from->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_end_date')
                ->orWhereDate('effective_end_date', '>=', $from->toDateString()))
            ->with('employee')
            ->orderByDesc('effective_start_date')
            ->get()
            ->unique('employee_id')
            ->values();
    }
}
