<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A schedule whose hours were edited in place.
 *
 * Night Shift was changed from 22:00-06:00 to 22:00-07:00 on 3 November.
 * Because a schedule is one shared record, that reached every day anybody had
 * worked on it: October nights ending at 06:00, exactly as asked, read as an
 * hour short each, and six people were docked PHP 3,154.82 between them.
 *
 * Editing it back fixes nothing — either value is wrong for half the year.
 * The hours have to belong to a date range.
 */
class SplitEditedScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected WorkSchedule $night;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // As it stands today: already edited to 07:00.
        $this->night = WorkSchedule::factory()->create([
            'name' => 'Night Shift',
            'start_time' => '22:00',
            'end_time' => '07:00',
            'work_days' => [1, 2, 3, 4, 5],
        ]);

        $this->employee = Employee::factory()->create(['hire_date' => '2026-08-26']);
        $this->employee->assignSchedule($this->night, '2026-08-26');
        $this->employee->refresh();
    }

    protected function punch(string $date, string $out): AttendanceDay
    {
        return AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => $date,
            'time_in' => $date . ' 22:00:00',
            'time_out' => \Illuminate\Support\Carbon::parse($date)->addDay()->toDateString() . ' ' . $out,
        ]);
    }

    protected function split(bool $apply = true): void
    {
        $this->artisan('phrems:split-edited-schedule', [
            'schedule' => $this->night->id,
            'from' => '2026-11-01',
            '--restore-end' => '06:00',
            '--name' => 'Night Shift 10PM-7AM',
        ] + ($apply ? ['--apply' => true] : []))->assertSuccessful();
    }

    #[Test]
    public function october_nights_ending_at_six_stop_reading_as_an_hour_short(): void
    {
        $october = $this->punch('2026-10-26', '06:00');

        // The damage as it stands: she worked her whole shift and is charged
        // 59 minutes for it.
        $this->assertSame(60, $october->undertimeMinutes());

        $this->split();

        $this->assertSame(0, $october->fresh()->undertimeMinutes());
    }

    #[Test]
    public function november_nights_still_have_to_run_to_seven(): void
    {
        $this->split();

        // Leaving at 06:00 after the change really is an hour early, and must
        // still be counted. The fix is about which hours applied when, not
        // about forgiving undertime.
        $this->assertSame(60, $this->punch('2026-11-03', '06:00')->undertimeMinutes());
        $this->assertSame(0, $this->punch('2026-11-04', '07:00')->undertimeMinutes());
    }

    #[Test]
    public function the_old_schedule_goes_back_to_the_hours_that_were_really_in_force(): void
    {
        $this->split();

        $this->assertSame('06:00', $this->night->fresh()->end_time->format('H:i'));

        $new = WorkSchedule::where('name', 'Night Shift 10PM-7AM')->sole();
        $this->assertSame('22:00', $new->start_time->format('H:i'));
        $this->assertSame('07:00', $new->end_time->format('H:i'));
    }

    #[Test]
    public function existing_assignments_are_left_where_they_are(): void
    {
        $before = $this->employee->scheduleAssignments()->sole();

        $this->split();

        // Somebody's assignment history is not this command's to tidy, and
        // rewriting rows that overlap in ways nobody has untangled is how a
        // fix becomes the next problem.
        $this->assertSame('2026-08-26', $before->fresh()->effective_start_date->toDateString());
        $this->assertSame($this->night->id, $before->fresh()->work_schedule_id);
    }

    #[Test]
    public function one_new_assignment_is_added_from_the_changeover(): void
    {
        $this->split();

        // The relation sorts newest first, which is how a day resolves to the
        // latest assignment covering it.
        $assignments = $this->employee->fresh()->scheduleAssignments;

        $this->assertCount(2, $assignments);
        $this->assertSame('2026-11-01', $assignments->first()->effective_start_date->toDateString());
    }

    #[Test]
    public function an_assignment_already_starting_after_the_changeover_is_repointed(): void
    {
        // The split somebody tried by hand: two assignments, both pointing at
        // the same record, so splitting the dates achieved nothing.
        $later = $this->employee->scheduleAssignments()->create([
            'work_schedule_id' => $this->night->id,
            'effective_start_date' => '2026-11-03',
        ]);

        $this->split();

        $new = WorkSchedule::where('name', 'Night Shift 10PM-7AM')->sole();

        $this->assertSame($new->id, $later->fresh()->work_schedule_id);
    }

    #[Test]
    public function somebody_whose_cover_ended_before_the_changeover_is_left_out(): void
    {
        $gone = Employee::factory()->create(['hire_date' => '2026-08-26']);
        $gone->assignSchedule($this->night, '2026-08-26');
        $gone->scheduleAssignments()->update(['effective_end_date' => '2026-10-17']);

        $this->split();

        // They never worked a day under the new hours, so nothing about them
        // changes.
        $this->assertCount(1, $gone->fresh()->scheduleAssignments);
    }

    #[Test]
    public function one_new_assignment_per_person_however_many_overlapping_rows_they_have(): void
    {
        // Update Schedule only ever adds, so people have collected two and
        // three overlapping rows. Adding one per row would pile more onto a
        // set that is already hard to read.
        $this->employee->scheduleAssignments()->create([
            'work_schedule_id' => $this->night->id,
            'effective_start_date' => '2026-09-15',
        ]);

        $this->split();

        $added = $this->employee->fresh()->scheduleAssignments()
            ->whereDate('effective_start_date', '2026-11-01')
            ->count();

        $this->assertSame(1, $added);
    }

    #[Test]
    public function without_apply_it_changes_nothing(): void
    {
        $this->split(apply: false);

        $this->assertSame('07:00', $this->night->fresh()->end_time->format('H:i'));
        $this->assertCount(1, $this->employee->fresh()->scheduleAssignments);
        $this->assertSame(0, WorkSchedule::where('name', 'Night Shift 10PM-7AM')->count());
    }
}
