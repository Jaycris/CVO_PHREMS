<?php

namespace Tests\Feature;

use App\Services\Sms\PhoneNumber;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RecordingSmsDriver;
use Tests\TestCase;

/**
 * The rules between a notification and a phone.
 *
 * Two of them cost real money when they are wrong. A number that is not a
 * mobile burns a credit to reach nobody, and a single en dash in the message
 * cuts the segment from 160 characters to 70 and charges for three.
 */
class SmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected RecordingSmsDriver $driver;

    protected SmsGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        // AppSetting memoises into a static that outlives a test, so a toggle
        // switched on elsewhere would still read as on here.
        \App\Models\AppSetting::flushCache();

        $this->driver = new RecordingSmsDriver;
        $this->app->instance(SmsDriver::class, $this->driver);
        $this->gateway = $this->app->make(SmsGateway::class);
    }

    /** @return list<array{string, ?string}> */
    public static function numbers(): array
    {
        return [
            'how it is written here' => ['09171234567', '+639171234567'],
            'with spaces' => ['0917 123 4567', '+639171234567'],
            'with dashes' => ['0917-123-4567', '+639171234567'],
            'already international' => ['+639171234567', '+639171234567'],
            'international, no plus' => ['639171234567', '+639171234567'],
            'international with spaces' => ['+63 917 123 4567', '+639171234567'],
            'dialled from a landline' => ['00639171234567', '+639171234567'],
            'leading zero left off' => ['9171234567', '+639171234567'],

            // Everything below cannot receive an SMS and must not be tried.
            'a Manila landline' => ['02-8123-4567', null],
            'too short' => ['0917123', null],
            'too long' => ['091712345678901', null],
            'blank' => ['', null],
            'words' => ['n/a', null],
            'a US number' => ['+12139439143', null],
        ];
    }

    #[Test]
    #[DataProvider('numbers')]
    public function a_typed_number_becomes_one_the_gateway_can_use(string $raw, ?string $expected): void
    {
        // personal_contact_number has never had a format check, so every one of
        // these shapes is already sitting in the employee table somewhere.
        $this->assertSame($expected, PhoneNumber::toE164($raw));
    }

    #[Test]
    public function a_masked_number_is_safe_to_write_to_a_log(): void
    {
        // Enough to tell which phone it was; not enough to be somebody's mobile
        // number sitting in a log file that other people read.
        $this->assertSame('0917•••4567', PhoneNumber::mask('09171234567'));
        $this->assertSame('unusable', PhoneNumber::mask('02-8123-4567'));
    }

    #[Test]
    public function an_unusable_number_is_skipped_rather_than_sent_to(): void
    {
        $this->assertFalse($this->gateway->send('02-8123-4567', 'Hello'));
        $this->assertFalse($this->gateway->send(null, 'Hello'));
        $this->assertFalse($this->gateway->send('', 'Hello'));

        $this->assertSame(0, $this->driver->count(), 'No credit should have been spent.');
    }

    #[Test]
    public function an_en_dash_is_converted_rather_than_sent(): void
    {
        /*
         * The expensive one. rangeLabel() renders "Sep 8 – 13, 2026" with an en
         * dash, and one character outside GSM-7 switches the whole message to
         * UCS-2 — 70 characters a segment instead of 160. Every off-site text
         * would have quietly cost three credits instead of one.
         */
        $this->gateway->send('09171234567', 'Off-site Sep 8 – 13, 2026');

        $this->assertSame('Off-site Sep 8 - 13, 2026', $this->driver->last()['message']);
    }

    #[Test]
    public function curly_quotes_and_a_peso_sign_are_converted_too(): void
    {
        $this->gateway->send('09171234567', 'Maria’s pay is ₱1,000… fine');

        $this->assertSame("Maria's pay is PHP 1,000... fine", $this->driver->last()['message']);
    }

    #[Test]
    public function a_long_message_is_cut_to_one_segment(): void
    {
        // Longer would still send, as two or three segments, charged as two or
        // three. Nothing here is worth paying twice for.
        $this->gateway->send('09171234567', str_repeat('a', 400));

        $sent = $this->driver->last()['message'];

        $this->assertSame(SmsGateway::SEGMENT, mb_strlen($sent));
        $this->assertStringEndsWith('...', $sent);
    }

    #[Test]
    public function a_message_that_fits_is_left_exactly_as_written(): void
    {
        $this->gateway->send('09171234567', 'PHREMS: Office closed today.');

        $this->assertSame('PHREMS: Office closed today.', $this->driver->last()['message']);
    }

    #[Test]
    public function the_number_reaches_the_driver_in_international_form(): void
    {
        $this->gateway->send('0917 123 4567', 'Hello');

        $this->assertSame('+639171234567', $this->driver->last()['to']);
    }

    #[Test]
    public function a_driver_that_throws_does_not_take_the_caller_down_with_it(): void
    {
        /*
         * The whole reason SMS sits alongside email rather than in front of it.
         * HR saving somebody's off-site days must not fail because a gateway
         * was unreachable — the days are what protects their pay.
         */
        $this->app->instance(SmsDriver::class, new class implements SmsDriver
        {
            public function name(): string
            {
                return 'exploding';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(string $e164, string $message): bool
            {
                throw new \RuntimeException('gateway on fire');
            }
        });

        $gateway = $this->app->make(SmsGateway::class);

        $this->assertFalse($gateway->send('09171234567', 'Hello'));
    }

    #[Test]
    public function nothing_is_switched_on_by_default(): void
    {
        // Shipping the code must not start sending anybody anything. Both
        // switches are seeded to No and stay there until somebody decides.
        $this->seed(\Database\Seeders\AppSettingSeeder::class);

        $this->assertFalse(SmsGateway::enabledFor(SmsGateway::OFFSITE_WORK));
        $this->assertFalse(SmsGateway::enabledFor(SmsGateway::URGENT_ANNOUNCEMENT));
    }

    #[Test]
    public function a_setting_switches_one_kind_on(): void
    {
        \App\Models\AppSetting::put(SmsGateway::OFFSITE_WORK, '1', 'Off-site texts');

        $this->assertTrue(SmsGateway::enabledFor(SmsGateway::OFFSITE_WORK));
        $this->assertFalse(SmsGateway::enabledFor(SmsGateway::URGENT_ANNOUNCEMENT));
    }

    #[Test]
    public function the_default_driver_sends_nothing(): void
    {
        // An app with no credentials must not be one setting away from texting
        // the whole company, and local development must never reach a real
        // phone — the test data holds real colleagues' numbers.
        config(['services.sms.driver' => null]);

        $this->app->forgetInstance(SmsDriver::class);

        $this->assertFalse($this->app->make(SmsGateway::class)->isLive());
    }
}
