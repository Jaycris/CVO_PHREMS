<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\OfficeNetwork;
use App\Services\Attendance\PunchLocationPolicy;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may clock in from where.
 *
 * The failure that matters here is not an employee sneaking a punch from home
 * — it is the whole company arriving at 9am unable to start their shift
 * because of a rule nobody can turn off from outside the office. Most of what
 * follows is about that: every uncertain case lets the punch through.
 */
class PunchLocationTest extends TestCase
{
    use RefreshDatabase;

    protected PunchLocationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AppSettingSeeder::class);

        $this->policy = new PunchLocationPolicy();
    }

    protected function enforce(): void
    {
        AppSetting::put(PunchLocationPolicy::SETTING, '1');
        AppSetting::flushCache();
    }

    protected function office(string $range = '203.0.113.0/24'): OfficeNetwork
    {
        return OfficeNetwork::create(['label' => 'Main office', 'ip_address' => $range]);
    }

    #[Test]
    public function an_onsite_employee_may_clock_in_from_the_office(): void
    {
        $this->enforce();
        $this->office();

        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertTrue($this->policy->allows($employee, '203.0.113.42'));
    }

    #[Test]
    public function an_onsite_employee_is_refused_from_home(): void
    {
        $this->enforce();
        $this->office();

        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertFalse($this->policy->allows($employee, '112.198.1.50'));
    }

    #[Test]
    public function remote_and_hybrid_staff_are_never_checked(): void
    {
        $this->enforce();
        $this->office();

        // Being elsewhere is the arrangement, so there is no rule to enforce.
        foreach (['Remote', 'Hybrid'] as $type) {
            $employee = Employee::factory()->create(['workplace_type' => $type]);

            $this->assertTrue(
                $this->policy->allows($employee, '112.198.1.50'),
                "a {$type} employee was refused"
            );
        }
    }

    #[Test]
    public function the_workplace_type_is_read_however_it_was_written(): void
    {
        $this->enforce();
        $this->office();

        // Free text on the employee record. "On-site" and "onsite" are the
        // same answer, and treating them differently would exempt half the
        // people the rule is for.
        foreach (['Onsite', 'onsite', 'On-site', 'ON SITE'] as $written) {
            $employee = Employee::factory()->create(['workplace_type' => $written]);

            $this->assertFalse(
                $this->policy->allows($employee, '112.198.1.50'),
                "'{$written}' was not recognised as on-site"
            );
        }
    }

    #[Test]
    public function nobody_is_checked_while_the_setting_is_off(): void
    {
        $this->office();

        // Off is the state this ships in. Installing the feature must not stop
        // anybody clocking in until somebody decides it should.
        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertFalse($this->policy->isEnforced());
        $this->assertTrue($this->policy->allows($employee, '112.198.1.50'));
    }

    #[Test]
    public function nobody_is_checked_when_no_office_address_is_on_file(): void
    {
        $this->enforce();

        // An empty list means "not set up yet" far more often than it means
        // "no address on earth is acceptable". Reading it the other way locks
        // out the entire company.
        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertTrue($this->policy->allows($employee, '112.198.1.50'));
    }

    #[Test]
    public function an_employee_with_no_workplace_type_is_not_checked(): void
    {
        $this->enforce();
        $this->office();

        // Every employee record in this system currently has this blank.
        // Treating blank as on-site would stop the whole company the moment
        // the switch was turned on.
        $employee = Employee::factory()->create(['workplace_type' => null]);

        $this->assertTrue($this->policy->allows($employee, '203.0.113.1'));
        $this->assertTrue($this->policy->allows($employee, '112.198.1.50'));
    }

    #[Test]
    public function a_switched_off_address_no_longer_counts_as_the_office(): void
    {
        $this->enforce();
        $office = $this->office();
        $office->update(['is_active' => false]);

        OfficeNetwork::create(['label' => 'Second line', 'ip_address' => '198.51.100.7']);

        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertFalse($this->policy->allows($employee, '203.0.113.42'));
        $this->assertTrue($this->policy->allows($employee, '198.51.100.7'));
    }

    #[Test]
    public function a_single_address_and_a_range_both_work(): void
    {
        $this->enforce();
        OfficeNetwork::create(['label' => 'Fixed line', 'ip_address' => '198.51.100.7']);
        OfficeNetwork::create(['label' => 'Range', 'ip_address' => '203.0.113.0/24']);

        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertTrue($this->policy->allows($employee, '198.51.100.7'));
        $this->assertFalse($this->policy->allows($employee, '198.51.100.8'));
        $this->assertTrue($this->policy->allows($employee, '203.0.113.255'));
        $this->assertFalse($this->policy->allows($employee, '203.0.114.1'));
    }

    #[Test]
    public function an_unknown_address_is_refused_for_onsite_staff(): void
    {
        $this->enforce();
        $this->office();

        $employee = Employee::factory()->create(['workplace_type' => 'Onsite']);

        $this->assertFalse($this->policy->allows($employee, null));
    }

    #[Test]
    public function a_malformed_address_is_rejected_before_it_is_saved(): void
    {
        // An entry nobody notices is malformed matches nothing, and the
        // symptom is an employee who cannot clock in rather than an error.
        foreach (['203.0.113.5', '203.0.113.0/24', '::1', '2001:db8::/32'] as $good) {
            $this->assertTrue(OfficeNetwork::isValidAddress($good), "{$good} should be valid");
        }

        foreach (['', 'office', '203.0.113', '203.0.113.5/', '203.0.113.0/64', '999.1.1.1'] as $bad) {
            $this->assertFalse(OfficeNetwork::isValidAddress($bad), "{$bad} should be rejected");
        }
    }

    #[Test]
    public function the_refusal_names_the_address_so_it_can_be_added(): void
    {
        $message = $this->policy->refusalMessage('112.198.1.50');

        // The employee is the only one who can read this number off a screen,
        // so the message has to hand it to them.
        $this->assertStringContainsString('112.198.1.50', $message);
    }

    #[Test]
    public function the_office_networks_page_shows_the_visitors_own_address(): void
    {
        $admin = \App\Models\User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get('/office-networks')
            ->assertOk()
            ->assertSee('This device is on');
    }

    #[Test]
    public function an_employee_cannot_open_the_office_networks_page(): void
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole('Employee');

        $this->actingAs($user)->get('/office-networks')->assertForbidden();
    }
    #[Test]
    public function an_address_set_on_the_server_works_without_any_database_row(): void
    {
        // The way back in when somebody deletes the wrong row. This cannot be
        // changed from inside the app at all.
        config(["attendance.office_ips" => "198.51.100.7,203.0.113.0/24"]);
        $this->enforce();

        $employee = Employee::factory()->create(["workplace_type" => "Onsite"]);

        $this->assertTrue($this->policy->allows($employee, "198.51.100.7"));
        $this->assertTrue($this->policy->allows($employee, "203.0.113.99"));
        $this->assertFalse($this->policy->allows($employee, "112.198.1.50"));
    }

    #[Test]
    public function a_server_address_still_works_when_every_row_is_switched_off(): void
    {
        config(["attendance.office_ips" => "198.51.100.7"]);
        $this->enforce();
        $this->office()->update(["is_active" => false]);

        $employee = Employee::factory()->create(["workplace_type" => "Onsite"]);

        $this->assertTrue($this->policy->allows($employee, "198.51.100.7"));
    }

    #[Test]
    public function a_typo_on_the_server_is_dropped_rather_than_breaking_the_page(): void
    {
        // A malformed entry here must not take attendance down for everybody.
        config(["attendance.office_ips" => "not-an-address, 198.51.100.7 ,,999.9.9.9"]);

        $this->assertSame(["198.51.100.7"], \App\Models\OfficeNetwork::fromConfig());
    }
}