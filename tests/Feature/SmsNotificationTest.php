<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\OffsiteAssignment;
use App\Models\User;
use App\Notifications\AnnouncementPosted;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\OffsiteWorkScheduled;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingSmsDriver;
use Tests\TestCase;

/**
 * Which notifications text somebody, and what the text says.
 *
 * Two rules run through all of this. A text never replaces the email — losing a
 * gateway must not mean losing the record. And nothing texts anybody until
 * somebody switches it on, because every message costs a credit and a company
 * that texts about everything teaches its staff to ignore its texts.
 */
class SmsNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected RecordingSmsDriver $driver;

    protected User $user;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        /*
         * AppSetting memoises into a static, which outlives a test even though
         * RefreshDatabase rolls the row back. Without this, a test that
         * switches SMS on leaves it on for whatever runs next — and the tests
         * asserting that nothing sends by default are exactly the ones that
         * would then pass or fail depending on ordering.
         */
        AppSetting::flushCache();

        $this->driver = new RecordingSmsDriver;
        $this->app->instance(SmsDriver::class, $this->driver);

        $this->user = User::factory()->create();
        $this->user->assignRole('Employee');

        $this->employee = Employee::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'personal_contact_number' => '0917 123 4567',
        ]);
        $this->employee->forceFill(['user_id' => $this->user->id])->save();

        $this->user->refresh();
    }

    protected function switchOn(string $feature): void
    {
        AppSetting::put($feature, '1', 'Test toggle');
    }

    protected function offsite(string $kind = OffsiteAssignment::WORKED): OffsiteAssignment
    {
        return OffsiteAssignment::create([
            'employee_id' => $this->employee->id,
            'start_date' => '2026-09-08',
            'end_date' => '2026-09-13',
            'kind' => $kind,
            'reason' => 'Trade exhibit',
        ]);
    }

    protected function deliver(object $notification): void
    {
        $this->app->make(SmsChannel::class)->send($this->user, $notification);
    }

    #[Test]
    public function off_site_work_does_not_text_anybody_until_it_is_switched_on(): void
    {
        $channels = (new OffsiteWorkScheduled($this->offsite()))->via($this->user);

        $this->assertSame(['mail', 'database'], $channels);
    }

    #[Test]
    public function switching_it_on_adds_sms_without_removing_the_email(): void
    {
        // The important half of this assertion is that mail is still there. A
        // notification that only arrives by text is one nobody can re-read.
        $this->switchOn(SmsGateway::OFFSITE_WORK);

        $channels = (new OffsiteWorkScheduled($this->offsite()))->via($this->user);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
        $this->assertContains(SmsChannel::class, $channels);
    }

    #[Test]
    public function the_off_site_text_says_not_to_clock_in(): void
    {
        $this->deliver(new OffsiteWorkScheduled($this->offsite()));

        $sent = $this->driver->last();

        $this->assertSame('+639171234567', $sent['to']);
        $this->assertStringContainsString('No need to clock in', $sent['message']);
        $this->assertStringContainsString('Trade exhibit', $sent['message']);
        $this->assertStringContainsString('Sep 08 - 13, 2026', $sent['message']);
    }

    #[Test]
    public function the_off_site_text_fits_in_one_segment(): void
    {
        /*
         * Worth asserting rather than trusting. The dates, the reason and the
         * wording all vary, and going one character over doubles the cost of
         * every message the company sends about an exhibit.
         */
        $this->deliver(new OffsiteWorkScheduled($this->offsite()));

        $this->assertLessThanOrEqual(
            SmsGateway::SEGMENT,
            mb_strlen($this->driver->last()['message']),
        );
    }

    #[Test]
    public function the_text_carries_no_link(): void
    {
        // Philippine carriers strip URLs out of business SMS, so a message that
        // depends on one arrives useless.
        $this->deliver(new OffsiteWorkScheduled($this->offsite()));

        $this->assertStringNotContainsString('http', $this->driver->last()['message']);
    }

    #[Test]
    public function cancelling_off_site_work_says_to_clock_in_again(): void
    {
        // The one that matters most. Somebody told not to punch, and then taken
        // off the list, has to be told plainly that the instruction is reversed.
        $this->deliver(new OffsiteWorkScheduled($this->offsite(), OffsiteWorkScheduled::REMOVED));

        $this->assertStringContainsString('clock in as normal', $this->driver->last()['message']);
    }

    #[Test]
    public function a_day_off_in_lieu_is_named_as_one(): void
    {
        $this->deliver(new OffsiteWorkScheduled($this->offsite(OffsiteAssignment::DAY_OFF)));

        $this->assertStringContainsString('day off in lieu', $this->driver->last()['message']);
    }

    #[Test]
    public function only_important_announcements_are_texted(): void
    {
        $this->switchOn(SmsGateway::URGENT_ANNOUNCEMENT);

        $ordinary = Announcement::factory()->create(['kind' => Announcement::NEWS]);
        $urgent = Announcement::factory()->create(['kind' => Announcement::URGENT]);

        $this->assertNotContains(SmsChannel::class, (new AnnouncementPosted($ordinary))->via($this->user));
        $this->assertContains(SmsChannel::class, (new AnnouncementPosted($urgent))->via($this->user));
    }

    #[Test]
    public function an_important_announcement_is_not_texted_while_the_setting_is_off(): void
    {
        // Both switches have to agree. Marking something Important must not be
        // enough on its own, or everything becomes Important.
        $urgent = Announcement::factory()->create(['kind' => Announcement::URGENT]);

        $this->assertNotContains(SmsChannel::class, (new AnnouncementPosted($urgent))->via($this->user));
    }

    #[Test]
    public function the_announcement_text_carries_the_notice_itself(): void
    {
        $announcement = Announcement::factory()->create([
            'kind' => Announcement::URGENT,
            'title' => 'Office closed Monday',
            'body' => 'Burst pipe on the third floor. Work from home.',
        ]);

        $this->deliver(new AnnouncementPosted($announcement));

        $message = $this->driver->last()['message'];

        $this->assertStringContainsString('Office closed Monday', $message);
        $this->assertStringContainsString('Burst pipe', $message);
        $this->assertStringNotContainsString('http', $message);
    }

    #[Test]
    public function somebody_with_no_number_on_file_is_skipped_quietly(): void
    {
        // Ordinary, not an error — the column has never been validated and two
        // of three employees on the live system have nothing in it.
        $this->employee->update(['personal_contact_number' => null]);
        $this->user->refresh();

        $this->deliver(new OffsiteWorkScheduled($this->offsite()));

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function a_landline_is_skipped_rather_than_texted(): void
    {
        $this->employee->update(['personal_contact_number' => '02-8123-4567']);
        $this->user->refresh();

        $this->deliver(new OffsiteWorkScheduled($this->offsite()));

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function a_real_notification_reaches_both_the_mailbox_and_the_phone(): void
    {
        /*
         * End to end rather than channel by channel: the queue runs inline and
         * mail goes to the array transport, so this proves via(), the channel
         * resolution and the gateway all agree with one another.
         */
        $this->switchOn(SmsGateway::OFFSITE_WORK);

        $this->user->notify(new OffsiteWorkScheduled($this->offsite()));

        $this->assertSame(1, $this->driver->count());
        $this->assertSame(1, $this->user->notifications()->count());
    }
}
