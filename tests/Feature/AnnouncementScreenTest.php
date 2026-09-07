<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The noticeboard: everybody reads it, few people write on it.
 *
 * The permission guards writing only. Gating the page itself would hide the
 * notices from exactly the people they were written for, which is the one
 * mistake that would make the whole feature pointless.
 */
class AnnouncementScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->hr = User::factory()->create();
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo('announcements.manage');
    }

    protected function plainEmployee(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $employee = Employee::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    #[Test]
    public function anybody_signed_in_can_read_the_board(): void
    {
        Announcement::factory()->create(['title' => 'Payroll moves to the 15th']);

        $this->actingAs($this->plainEmployee())
            ->get('/announcements')
            ->assertOk()
            ->assertSee('Payroll moves to the 15th');
    }

    #[Test]
    public function an_employee_is_not_offered_the_posting_controls(): void
    {
        Livewire::actingAs($this->plainEmployee())
            ->test('announcements.index')
            ->assertSet('canManage', false)
            ->assertDontSee('Post Announcement');
    }

    #[Test]
    public function an_employee_cannot_post_by_calling_the_action(): void
    {
        // The buttons are not rendered, but a crafted Livewire call still
        // reaches the method. That is the path that has to be closed.
        Livewire::actingAs($this->plainEmployee())
            ->test('announcements.index')
            ->set('title', 'Free day off for everyone')
            ->set('body', 'Signed, not HR.')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function an_employee_never_sees_a_draft(): void
    {
        Announcement::factory()->draft()->create(['title' => 'Still being worded']);

        $this->actingAs($this->plainEmployee())
            ->get('/announcements')
            ->assertOk()
            ->assertDontSee('Still being worded');
    }

    #[Test]
    public function posting_a_notice_puts_it_on_the_board(): void
    {
        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('create')
            ->set('title', 'Trade exhibit at SMX')
            ->set('body', 'Booth staff are on site from the 8th.')
            ->set('kind', Announcement::EVENT)
            ->set('startsOn', '2026-09-08')
            ->set('endsOn', '2026-09-13')
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::sole();

        $this->assertSame('Trade exhibit at SMX', $announcement->title);
        $this->assertSame(Announcement::EVENT, $announcement->kind);
        $this->assertSame('2026-09-08', $announcement->starts_on->toDateString());
        $this->assertSame('2026-09-13', $announcement->ends_on->toDateString());
        $this->assertSame($this->hr->id, $announcement->created_by_user_id);
        $this->assertFalse($announcement->isDraft());
    }

    #[Test]
    public function an_unticked_post_is_saved_as_a_draft(): void
    {
        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('create')
            ->set('title', 'Not ready')
            ->set('body', 'Waiting on the CEO.')
            ->set('publishNow', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Announcement::sole()->isDraft());
    }

    #[Test]
    public function the_last_day_cannot_be_before_the_first(): void
    {
        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('create')
            ->set('title', 'Backwards')
            ->set('body', 'Ends before it starts.')
            ->set('startsOn', '2026-09-13')
            ->set('endsOn', '2026-09-08')
            ->call('save')
            ->assertHasErrors('endsOn');

        $this->assertSame(0, Announcement::count());
    }

    #[Test]
    public function editing_a_live_notice_does_not_move_it_back_to_the_top(): void
    {
        /*
         * published_at is the record of when it first went out. Restamping it
         * on every edit would reorder the board and make a notice everyone had
         * already read look new because somebody fixed a typo.
         */
        $announcement = Announcement::factory()->create([
            'title' => 'Payroll date',
            'published_at' => now()->subDays(3),
        ]);

        $originally = $announcement->published_at;

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('edit', $announcement->id)
            ->set('title', 'Payroll date (corrected)')
            ->call('save')
            ->assertHasNoErrors();

        $announcement->refresh();

        $this->assertSame('Payroll date (corrected)', $announcement->title);
        $this->assertTrue($originally->equalTo($announcement->published_at));
    }

    #[Test]
    public function taking_a_notice_down_keeps_the_text(): void
    {
        // Wanted more often than deleting: a notice with the wrong dates should
        // come down now and be fixed, not retyped.
        $announcement = Announcement::factory()->create(['title' => 'Posted by mistake']);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('unpublish', $announcement->id);

        $announcement->refresh();

        $this->assertTrue($announcement->isDraft());
        $this->assertSame('Posted by mistake', $announcement->title);
    }

    #[Test]
    public function posting_a_draft_stamps_it(): void
    {
        $announcement = Announcement::factory()->draft()->create();

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('publish', $announcement->id);

        $this->assertFalse($announcement->fresh()->isDraft());
    }

    #[Test]
    public function nobody_is_emailed_unless_it_is_asked_for(): void
    {
        // The default. A board that emails everybody about everything is one
        // people filter away, taking the notice that mattered with it.
        Notification::fake();

        $this->plainEmployee();

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('create')
            ->set('title', 'Ordinary news')
            ->set('body', 'Nothing urgent.')
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    #[Test]
    public function ticking_the_box_emails_every_current_employee(): void
    {
        Notification::fake();

        $staying = $this->plainEmployee();

        // Somebody who has left keeps their account but is not company news.
        $leaver = $this->plainEmployee();
        $leaver->employee->update(['separation_date' => now()->subMonth()->toDateString()]);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('create')
            ->set('title', 'Office closed Monday')
            ->set('body', 'Burst pipe. Work from home.')
            ->set('kind', Announcement::URGENT)
            ->set('notifyEveryone', true)
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertSentTo($staying, AnnouncementPosted::class);
        Notification::assertNotSentTo($leaver, AnnouncementPosted::class);
    }

    #[Test]
    public function a_draft_is_never_emailed(): void
    {
        // Saving a draft with the notify box left ticked must not send it.
        // "Not ready to show anybody" and "send this to everybody" cannot both
        // be true, and the draft has to win.
        Notification::fake();

        $employee = $this->plainEmployee();

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('create')
            ->set('title', 'Draft with notify left on')
            ->set('body', 'Should reach nobody.')
            ->set('notifyEveryone', true)
            ->set('publishNow', false)
            ->call('save')
            ->assertHasNoErrors();

        Notification::assertNotSentTo($employee, AnnouncementPosted::class);
    }

    #[Test]
    public function an_employee_cannot_delete_a_notice(): void
    {
        $announcement = Announcement::factory()->create();

        Livewire::actingAs($this->plainEmployee())
            ->test('announcements.index')
            ->call('prepareDelete', $announcement->id)
            ->assertForbidden();

        $this->assertSame(1, Announcement::count());
    }

    #[Test]
    public function the_board_shows_on_the_dashboard(): void
    {
        Announcement::factory()->create(['title' => 'Trade exhibit at SMX']);

        $this->actingAs($this->plainEmployee())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Trade exhibit at SMX');
    }
}
