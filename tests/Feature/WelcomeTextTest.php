<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\User;
use App\Services\Sms\SmsDriver;
use App\Services\Sms\SmsGateway;
use App\Services\Sms\WelcomeText;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\RecordingSmsDriver;
use Tests\TestCase;

/**
 * The welcome text a new hire gets once they are fully set up.
 *
 * Three things must all be true — HR ticked it, onboarding is complete, the
 * password is set — and it goes once, whichever of them happened last.
 */
class WelcomeTextTest extends TestCase
{
    use RefreshDatabase;

    protected RecordingSmsDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        AppSetting::flushCache();

        $this->driver = new RecordingSmsDriver;
        $this->app->instance(SmsDriver::class, $this->driver);
    }

    /** @param array<string, mixed> $employee */
    protected function newHire(array $employee = [], bool $passwordSet = true, bool $enabled = true): Employee
    {
        $user = User::factory()->create([
            'password_set_at' => $passwordSet ? now() : null,
            'is_active' => $enabled,
        ]);
        $user->assignRole('Employee');

        $hire = Employee::factory()->create(array_merge([
            'personal_contact_number' => '0917 123 4567',
            // The edit form requires it, and the factory leaves it out.
            'personal_email' => 'new.hire@example.com',
            'onboarding_completed_at' => now(),
            'welcome_sms' => true,
        ], $employee));
        $hire->forceFill(['user_id' => $user->id])->save();

        return $hire;
    }

    protected function send(Employee $employee): bool
    {
        return app(WelcomeText::class)->sendIfReady($employee->fresh());
    }

    #[Test]
    public function it_goes_once_everything_is_in_place(): void
    {
        $employee = $this->newHire();

        $this->assertTrue($this->send($employee));

        $this->assertSame(1, $this->driver->count());
        $this->assertSame('+639171234567', $this->driver->last()['to']);
        $this->assertStringContainsString('Welcome to CreatiVision Outsourcing!', $this->driver->last()['message']);
        $this->assertNotNull($employee->fresh()->welcome_sms_sent_at);
    }

    #[Test]
    public function it_never_goes_twice(): void
    {
        $employee = $this->newHire();

        $this->send($employee);
        $this->send($employee);

        $this->assertSame(1, $this->driver->count());
    }

    #[Test]
    public function nothing_goes_unless_hr_ticked_it(): void
    {
        $this->assertFalse($this->send($this->newHire(['welcome_sms' => false])));
        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function nothing_goes_before_onboarding_is_complete(): void
    {
        $this->assertFalse($this->send($this->newHire(['onboarding_completed_at' => null])));
        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function nothing_goes_before_the_password_is_set(): void
    {
        $this->assertFalse($this->send($this->newHire(passwordSet: false)));
        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function nothing_goes_while_the_account_is_disabled(): void
    {
        $this->assertFalse($this->send($this->newHire(enabled: false)));
        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function nothing_goes_to_somebody_who_is_leaving(): void
    {
        $this->assertFalse($this->send($this->newHire(['separation_date' => now()->addWeek()->toDateString()])));
        $this->assertSame(0, $this->driver->count());
    }

    #[Test]
    public function enabling_the_account_last_sends_it(): void
    {
        $employee = $this->newHire(enabled: false);

        $ceo = User::factory()->create(['is_super_admin' => true]);
        $ceo->assignRole('Admin');

        Livewire::actingAs($ceo)
            ->test('users.index')
            ->call('setSelectedAccess', [$employee->user_id], true);

        $this->assertSame(1, $this->driver->count());
    }

    #[Test]
    public function with_no_mobile_number_it_waits_rather_than_counting_as_sent(): void
    {
        $employee = $this->newHire(['personal_contact_number' => null]);

        $this->assertFalse($this->send($employee));
        $this->assertNull($employee->fresh()->welcome_sms_sent_at);

        // HR adds the number; the next try sends it.
        $employee->update(['personal_contact_number' => '09171234567']);
        $this->assertTrue($this->send($employee));
    }

    #[Test]
    public function ticking_it_after_everything_is_done_sends_it_on_save(): void
    {
        $employee = $this->newHire(['welcome_sms' => false]);

        $hr = User::factory()->create();
        $hr->assignRole('Admin');
        $hr->givePermissionTo('employees.manage');

        Livewire::actingAs($hr)
            ->test('employees.edit', ['employee' => $employee])
            ->set('welcome_sms', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, $this->driver->count());
        $this->assertTrue($employee->fresh()->welcome_sms);
    }

    #[Test]
    public function the_message_fits_one_text_without_being_cut(): void
    {
        // The gateway cuts anything longer than one text and adds "...".
        $this->assertSame(WelcomeText::body(), app(SmsGateway::class)->compose(WelcomeText::body()));
        $this->assertLessThanOrEqual(SmsGateway::SEGMENT, mb_strlen(WelcomeText::body()));

        // Opens with the welcome, not "PhremsCVO:".
        $this->assertStringStartsWith('Welcome to CreatiVision Outsourcing!', WelcomeText::body());
    }
}
