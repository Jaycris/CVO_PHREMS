<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Sms\LogDriver;
use App\Services\Sms\SmsDriver;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingSmsDriver;
use Tests\TestCase;

/**
 * Proving the SMS setup works before anything depends on it.
 *
 * Credentials get typed into a live .env and then sit unproven. Without a way
 * to send one message on demand, the first evidence that a key is wrong is an
 * employee not being told they are on off-site work — which is the one message
 * that had to arrive.
 */
class SmsTestSendTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected RecordingSmsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        AppSetting::flushCache();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('app.settings.manage');

        $this->driver = new RecordingSmsDriver;
        $this->app->instance(SmsDriver::class, $this->driver);
    }

    #[Test]
    public function a_test_message_goes_to_the_gateway(): void
    {
        Livewire::actingAs($this->admin)
            ->test('settings')
            ->set('testNumber', '0917 123 4567')
            ->call('sendTestSms')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->driver->count());
        $this->assertSame('+639171234567', $this->driver->last()['to']);
        $this->assertStringContainsString('SMS is working', $this->driver->last()['message']);
    }

    #[Test]
    public function a_number_that_cannot_receive_a_text_is_refused_before_sending(): void
    {
        // Refused here rather than at the gateway, so the admin is told why
        // instead of watching a credit disappear into a landline.
        Livewire::actingAs($this->admin)
            ->test('settings')
            ->set('testNumber', '02-8123-4567')
            ->call('sendTestSms')
            ->assertSet('smsTestTone', 'warning')
            ->assertSee('not a Philippine mobile number');

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function the_log_driver_says_plainly_that_nothing_was_sent(): void
    {
        /*
         * The trap this exists for: a successful-looking test on an app that
         * never sent anything. Somebody would tick both toggles, see green, and
         * believe SMS was live.
         */
        $this->app->instance(SmsDriver::class, new LogDriver);

        Livewire::actingAs($this->admin)
            ->test('settings')
            ->set('testNumber', '09171234567')
            ->call('sendTestSms')
            ->assertSet('smsTestTone', 'info')
            ->assertSee('Nothing was sent');
    }

    #[Test]
    public function a_refusal_from_the_gateway_is_reported_with_somewhere_to_look(): void
    {
        $this->app->instance(SmsDriver::class, new RecordingSmsDriver(configured: true, accepts: false));

        Livewire::actingAs($this->admin)
            ->test('settings')
            ->set('testNumber', '09171234567')
            ->call('sendTestSms')
            ->assertSet('smsTestTone', 'error')
            ->assertSee('sender name is approved');
    }

    #[Test]
    public function an_empty_number_is_asked_for_rather_than_ignored(): void
    {
        Livewire::actingAs($this->admin)
            ->test('settings')
            ->set('testNumber', '')
            ->call('sendTestSms')
            ->assertHasErrors('testNumber');

        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function somebody_without_the_settings_permission_cannot_reach_the_screen(): void
    {
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        $this->actingAs($employee)->get('/settings')->assertForbidden();
    }
}
