<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\OffsiteAssignment;
use App\Models\User;
use App\Services\TodayBoard;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What is happening today, gathered from where it already lives.
 *
 * Every fact on this board was already in PHREMS and none of it reached the
 * person opening the dashboard. The board stores nothing of its own, so the
 * thing worth testing is that each source is read correctly and that the
 * dates decide what shows — nobody is going to remember to clear it.
 */
class TodayBoardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Employee $employee;

    protected Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->today = Carbon::parse('2026-09-08');

        $this->user = User::factory()->create();
        $this->user->assignRole('Employee');

        $this->employee = Employee::factory()->create(['hire_date' => '2024-01-15']);
        $this->employee->forceFill(['user_id' => $this->user->id])->save();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    protected function board(?User $user = null)
    {
        return app(TodayBoard::class)->for(($user ?? $this->user)->fresh(), $this->today->copy());
    }

    protected function titles(?User $user = null): array
    {
        return $this->board($user)->pluck('title')->all();
    }

    #[Test]
    public function a_holiday_today_is_on_the_board(): void
    {
        Holiday::create([
            'date' => $this->today->toDateString(),
            'name' => 'National Heroes Day',
            'type' => Holiday::REGULAR,
            'observance' => Holiday::PHILIPPINES,
            'worked_premium_percent' => 100,
        ]);

        $item = $this->board()->firstWhere('title', 'National Heroes Day');

        $this->assertNotNull($item);
        $this->assertSame('Holiday', $item['label']);
        $this->assertStringContainsString('paid whether or not it is worked', $item['detail']);
    }

    #[Test]
    public function a_special_working_day_says_to_come_in(): void
    {
        /*
         * The one holiday type that is not a day off. Somebody reading only the
         * word "holiday" would stay home and be marked absent, so the board has
         * to say the opposite of what the heading implies.
         */
        Holiday::create([
            'date' => $this->today->toDateString(),
            'name' => 'Special Working Day',
            'type' => Holiday::SPECIAL_WORKING,
            'observance' => Holiday::PHILIPPINES,
            'worked_premium_percent' => 0,
        ]);

        $item = $this->board()->firstWhere('title', 'Special Working Day');

        $this->assertStringContainsString('Clock in as usual', $item['detail']);
    }

    #[Test]
    public function a_holiday_on_another_day_is_not(): void
    {
        Holiday::create([
            'date' => $this->today->copy()->addDay()->toDateString(),
            'name' => 'Tomorrow Only',
            'type' => Holiday::REGULAR,
            'observance' => Holiday::PHILIPPINES,
            'worked_premium_percent' => 100,
        ]);

        $this->assertNotContains('Tomorrow Only', $this->titles());
    }

    #[Test]
    public function an_announcement_running_today_is_on_the_board(): void
    {
        Announcement::factory()->between('2026-09-08', '2026-09-13')->create([
            'title' => 'Trade exhibit at SMX',
        ]);

        $this->assertContains('Trade exhibit at SMX', $this->titles());
    }

    #[Test]
    public function an_announcement_with_no_end_date_stays_up(): void
    {
        Announcement::factory()->create([
            'title' => 'Payroll now lands on the 15th',
            'starts_on' => '2026-01-01',
            'ends_on' => null,
        ]);

        $this->assertContains('Payroll now lands on the 15th', $this->titles());
    }

    #[Test]
    public function an_announcement_clears_itself_the_day_after_it_ends(): void
    {
        // The reason notices carry dates at all. A noticeboard that waits for
        // somebody to tidy it is a noticeboard nobody reads by March.
        Announcement::factory()->between('2026-09-01', '2026-09-07')->create([
            'title' => 'Finished yesterday',
        ]);

        $this->assertNotContains('Finished yesterday', $this->titles());
    }

    #[Test]
    public function an_announcement_dated_ahead_waits_its_turn(): void
    {
        Announcement::factory()->between('2026-09-20', '2026-09-21')->create([
            'title' => 'Christmas party planning',
        ]);

        $this->assertNotContains('Christmas party planning', $this->titles());
    }

    #[Test]
    public function a_draft_is_never_on_the_board(): void
    {
        // Written for today and still not posted. The dates say show it; the
        // missing publish stamp says no, and the publish stamp wins.
        Announcement::factory()->draft()->create([
            'title' => 'Not ready yet',
            'starts_on' => $this->today->toDateString(),
        ]);

        $this->assertNotContains('Not ready yet', $this->titles());
    }

    #[Test]
    public function a_pinned_announcement_comes_before_the_others(): void
    {
        Announcement::factory()->create(['title' => 'Ordinary notice']);
        Announcement::factory()->pinned()->create(['title' => 'Read this first']);

        $announcements = $this->board()
            ->where('label', '!=', 'Holiday')
            ->whereIn('title', ['Ordinary notice', 'Read this first'])
            ->pluck('title')
            ->values()
            ->all();

        $this->assertSame(['Read this first', 'Ordinary notice'], $announcements);
    }

    #[Test]
    public function an_employee_off_site_today_is_told_not_to_clock_in(): void
    {
        /*
         * They were emailed when the days were set, but that was a week ago.
         * What somebody needs at eight in the morning is the answer on the
         * screen they open, not in a folder.
         */
        OffsiteAssignment::create([
            'employee_id' => $this->employee->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-13',
            'kind' => OffsiteAssignment::WORKED,
            'reason' => 'Trade exhibit — booth duty',
        ]);

        $item = $this->board()->firstWhere('title', 'You are working off-site today');

        $this->assertNotNull($item);
        $this->assertStringContainsString('No need to clock in', $item['detail']);
    }

    #[Test]
    public function a_day_off_in_lieu_is_named_as_one(): void
    {
        // Payroll treats the two identically. The board must not, because the
        // employee is being told something different in each case.
        OffsiteAssignment::create([
            'employee_id' => $this->employee->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-08',
            'kind' => OffsiteAssignment::DAY_OFF,
            'reason' => 'Rest day after the exhibit',
        ]);

        $this->assertContains('Your day off in lieu is today', $this->titles());
    }

    #[Test]
    public function somebody_elses_off_site_day_is_not_shown_to_an_employee(): void
    {
        OffsiteAssignment::create([
            'employee_id' => Employee::factory()->create()->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-13',
            'kind' => OffsiteAssignment::WORKED,
            'reason' => 'Trade exhibit — booth duty',
        ]);

        $this->assertSame([], $this->titles());
    }

    #[Test]
    public function whoever_arranges_off_site_work_sees_who_is_out(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('Admin');
        $hr->givePermissionTo('attendance.offsite.manage');

        $team = Employee::factory()->count(2)->create();

        foreach ($team as $member) {
            OffsiteAssignment::create([
                'employee_id' => $member->id,
                'start_date' => '2026-09-08',
                'end_date' => '2026-09-13',
                'kind' => OffsiteAssignment::WORKED,
                'reason' => 'Trade exhibit — booth duty',
            ]);
        }

        $item = $this->board($hr)->firstWhere('label', 'Off-site today');

        $this->assertNotNull($item);
        $this->assertSame('Trade exhibit — booth duty', $item['title']);

        // Grouped by what it is. Six people on one booth is one line worth
        // reading; six identical lines is a list nobody finishes.
        $this->assertSame(1, $this->board($hr)->where('label', 'Off-site today')->count());

        foreach ($team as $member) {
            $this->assertStringContainsString($member->fullName(), $item['detail']);
        }
    }

    #[Test]
    public function a_work_anniversary_is_on_the_board(): void
    {
        Employee::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'hire_date' => '2023-09-08',
        ]);

        $item = $this->board()->firstWhere('label', 'Work anniversary');

        $this->assertNotNull($item);
        $this->assertStringContainsString('Maria', $item['title']);
        $this->assertStringContainsString('3 years', $item['detail']);
    }

    #[Test]
    public function somebody_who_started_today_has_no_anniversary_yet(): void
    {
        // Their first day is not a "0 years with CreatiVision" line.
        Employee::factory()->create([
            'first_name' => 'New',
            'last_name' => 'Starter',
            'hire_date' => $this->today->toDateString(),
        ]);

        $this->assertSame([], $this->board()->where('label', 'Work anniversary')->all());
    }

    #[Test]
    public function somebody_who_has_left_is_not_congratulated(): void
    {
        Employee::factory()->create([
            'first_name' => 'Former',
            'last_name' => 'Colleague',
            'hire_date' => '2023-09-08',
            'separation_date' => '2026-06-30',
        ]);

        $this->assertSame([], $this->board()->where('label', 'Work anniversary')->all());
    }

    #[Test]
    public function a_quiet_day_gives_an_empty_board(): void
    {
        $this->assertSame([], $this->titles());
    }
}
