<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\SmsBroadcast;
use App\Models\User;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsGateway;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingSmsDriver;
use Tests\TestCase;

/**
 * Texting the whole company with nothing else attached.
 *
 * Its own act rather than an announcement with the board and the email switched
 * off. "Office closed, do not come in" has to reach phones in the next two
 * minutes and has no business becoming a dashboard item somebody re-reads in
 * March.
 */
class SmsBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected User $hr;

    protected RecordingSmsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        AppSetting::flushCache();
        AppSetting::put(SmsGateway::URGENT_ANNOUNCEMENT, '1', 'Allow texting');

        $this->driver = new RecordingSmsDriver;
        $this->app->instance(SmsDriver::class, $this->driver);

        $this->hr = User::factory()->create();
        $this->hr->assignRole('Admin');
        $this->hr->givePermissionTo('announcements.manage');
    }

    protected function staffWithMobiles(int $count): void
    {
        foreach (range(1, $count) as $i) {
            Employee::factory()->create([
                'personal_contact_number' => '0917123456' . $i,
            ]);
        }
    }

    #[Test]
    public function a_text_reaches_everybody_with_a_usable_mobile(): void
    {
        $this->staffWithMobiles(3);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Office closed today. Work from home.')
            ->set('blastConfirmed', true)
            ->call('sendBlast')
            ->assertHasNoErrors();

        $this->assertSame(3, $this->driver->count());
        $this->assertSame('Office closed today. Work from home.', $this->driver->last()['message']);
    }

    #[Test]
    public function it_posts_nothing_and_emails_nobody(): void
    {
        // The whole point of asking for it separately.
        $this->staffWithMobiles(2);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Office closed today.')
            ->set('blastConfirmed', true)
            ->call('sendBlast');

        $this->assertSame(0, \App\Models\Announcement::count(), 'Nothing should reach the board.');
    }

    #[Test]
    public function what_was_sent_is_written_down(): void
    {
        /*
         * A text exists on fifty-two handsets and nowhere else. Without a row,
         * the only evidence the company messaged everybody is a line on a
         * gateway bill nobody can reconcile.
         */
        $this->staffWithMobiles(2);
        Employee::factory()->create(['personal_contact_number' => null]);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Payday moved to Friday.')
            ->set('blastConfirmed', true)
            ->call('sendBlast');

        $broadcast = SmsBroadcast::sole();

        $this->assertSame('Payday moved to Friday.', $broadcast->message);
        $this->assertSame(2, $broadcast->recipients_attempted);
        $this->assertSame(2, $broadcast->recipients_sent);
        $this->assertSame(1, $broadcast->recipients_skipped);
        $this->assertSame($this->hr->id, $broadcast->sent_by_user_id);
    }

    #[Test]
    public function the_message_is_stored_as_it_was_actually_sent(): void
    {
        // Character-converted and cut by the same code that sends it, so the
        // record matches the handsets rather than the typing.
        $this->staffWithMobiles(1);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Exhibit Sep 8 – 13 is cancelled')
            ->set('blastConfirmed', true)
            ->call('sendBlast');

        $this->assertSame('Exhibit Sep 8 - 13 is cancelled', SmsBroadcast::sole()->message);
    }

    #[Test]
    public function somebody_who_has_left_is_not_texted(): void
    {
        // Every credit spent on a former colleague is one spent annoying them.
        Employee::factory()->create(['personal_contact_number' => '09171234567']);
        Employee::factory()->create([
            'personal_contact_number' => '09179999999',
            'separation_date' => now()->subMonth()->toDateString(),
        ]);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Office closed today.')
            ->set('blastConfirmed', true)
            ->call('sendBlast');

        $this->assertSame(1, $this->driver->count());
        $this->assertSame('+639171234567', $this->driver->last()['to']);
    }

    #[Test]
    public function nothing_sends_without_the_confirmation(): void
    {
        // Everything else on this screen can be undone. Fifty-two phones
        // buzzing cannot, so it takes a second deliberate action.
        $this->staffWithMobiles(2);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Office closed today.')
            ->call('sendBlast')
            ->assertHasErrors('blastConfirmed');

        $this->assertSame(0, $this->driver->count());
        $this->assertSame(0, SmsBroadcast::count());
    }

    #[Test]
    public function an_empty_message_is_refused(): void
    {
        $this->staffWithMobiles(2);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastConfirmed', true)
            ->call('sendBlast')
            ->assertHasErrors('blastMessage');

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function the_confirmation_does_not_stay_ticked_for_the_next_one(): void
    {
        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->set('blastConfirmed', true)
            ->set('blastMessage', 'Left over')
            ->call('openBlast')
            ->assertSet('blastConfirmed', false)
            ->assertSet('blastMessage', '');
    }

    #[Test]
    public function a_text_can_go_to_chosen_people_only(): void
    {
        // A message that concerns three people should not buzz fifty-two
        // phones, and should not cost fifty-two credits.
        $wanted = Employee::factory()->create(['personal_contact_number' => '09171111111']);
        Employee::factory()->create(['personal_contact_number' => '09172222222']);
        Employee::factory()->create(['personal_contact_number' => '09173333333']);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastAudience', 'some')
            ->set('blastEmployeeIds', [$wanted->id])
            ->set('blastMessage', 'Please come to the office at 2pm.')
            ->set('blastConfirmed', true)
            ->call('sendBlast')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->driver->count());
        $this->assertSame('+639171111111', $this->driver->last()['to']);

        $broadcast = SmsBroadcast::sole();
        $this->assertSame([$wanted->id], $broadcast->employee_ids);
        $this->assertFalse($broadcast->wentToEverybody());
        $this->assertSame('1 chosen person', $broadcast->audienceLabel());
    }

    #[Test]
    public function choosing_people_and_ticking_nobody_sends_nothing(): void
    {
        /*
         * The dangerous case. An empty list must never fall through to meaning
         * everybody — that is a whole-company text nobody asked for.
         */
        $this->staffWithMobiles(3);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastAudience', 'some')
            ->set('blastMessage', 'Please come to the office at 2pm.')
            ->set('blastConfirmed', true)
            ->call('sendBlast')
            ->assertHasErrors('blastEmployeeIds');

        $this->assertSame(0, $this->driver->count());
        $this->assertSame(0, SmsBroadcast::count());
    }

    #[Test]
    public function somebody_who_left_cannot_be_texted_by_a_stale_selection(): void
    {
        // The id is still in the form, but they are no longer employed.
        $leaver = Employee::factory()->create([
            'personal_contact_number' => '09174444444',
            'separation_date' => now()->subDay()->toDateString(),
        ]);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastAudience', 'some')
            ->set('blastEmployeeIds', [$leaver->id])
            ->set('blastMessage', 'Please come to the office at 2pm.')
            ->set('blastConfirmed', true)
            ->call('sendBlast')
            ->assertHasNoErrors();

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function the_whole_company_is_still_the_default(): void
    {
        $this->staffWithMobiles(3);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->assertSet('blastAudience', 'all')
            ->set('blastMessage', 'Office closed today.')
            ->set('blastConfirmed', true)
            ->call('sendBlast');

        $this->assertSame(3, $this->driver->count());
        $this->assertTrue(SmsBroadcast::sole()->wentToEverybody());
    }

    #[Test]
    public function an_employee_cannot_text_the_company(): void
    {
        $this->staffWithMobiles(2);

        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        Livewire::actingAs($employee)
            ->test('announcements.index')
            ->call('openBlast')
            ->assertForbidden();

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function it_is_refused_while_texting_is_switched_off(): void
    {
        AppSetting::put(SmsGateway::URGENT_ANNOUNCEMENT, '0', 'Allow texting');
        AppSetting::flushCache();

        $this->staffWithMobiles(2);

        Livewire::actingAs($this->hr)
            ->test('announcements.index')
            ->call('openBlast')
            ->set('blastMessage', 'Office closed today.')
            ->set('blastConfirmed', true)
            ->call('sendBlast')
            ->assertForbidden();

        $this->assertSame(0, $this->driver->count());
    }
}
