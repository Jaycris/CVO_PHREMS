<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatutorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every page opens.
 *
 * Not a substitute for testing what the pages do — it only proves none of
 * them throws. That sounds like a low bar until you remember that the last
 * three faults found here were a 500 on My Commission from a renamed column,
 * a locked-property crash on a slip, and a table claiming to show departments
 * on every screen. All three reached a person before a test did.
 *
 * A new page belongs in this list the day it gets a route.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AppSettingSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $this->seed(StatutorySeeder::class);
    }

    /**
     * Pages that need the signer to have an employee record of their own.
     *
     * An HR manager or the owner may hold a login without being an employee in
     * the system, so these have to fail politely rather than crash. They raise
     * a 403 carrying a written reason, which the custom error page shows.
     */
    public static function pagesNeedingAnEmployeeRecord(): array
    {
        return [
            'my attendance' => ['/attendance'],
            'my profile' => ['/my-profile'],
            'my commission' => ['/my-commission'],
            'file leave' => ['/leave-requests/create'],
        ];
    }

    /** Every page an administrator can reach, with nothing on file yet. */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'my payslips' => ['/my-payslips'],
            'my reimbursements' => ['/my-reimbursements'],
            'leave requests' => ['/leave-requests'],
            'overtime' => ['/overtime'],
            'file overtime' => ['/overtime/create'],
            'requests' => ['/requests'],
            'cash advance requests' => ['/cash-advance-requests'],
            'departments' => ['/org/departments'],
            'positions' => ['/org/positions'],
            'recruitment' => ['/recruitment'],
            'employees' => ['/employees'],
            'add employee' => ['/employees/create'],
            'users' => ['/users'],
            'work schedules' => ['/schedules'],
            'dtr' => ['/dtr'],
            'holidays' => ['/holidays'],
            'request types' => ['/request-types'],
            'leave types' => ['/leave-types'],
            'cash advance record' => ['/cash-advances'],
            'reimbursement record' => ['/reimbursements'],
            'commission runs' => ['/commissions'],
            'commission schemes' => ['/commissions/schemes'],
            'bank details' => ['/bank-details'],
            'run payroll' => ['/payroll'],
            '13th month' => ['/payroll/13th-month'],
            'payroll settings' => ['/payroll/settings'],
            'money in and out' => ['/money'],
            'attendance summary' => ['/reports/attendance-summary'],
            'system settings' => ['/settings'],
        ];
    }

    #[Test]
    #[DataProvider('adminPages')]
    public function an_administrator_can_open_every_page(string $url): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get($url)
            ->assertOk();
    }

    /**
     * The same sweep with an employee on file.
     *
     * Worth doing twice: several pages take one branch when the signer has no
     * employee record and a different one when they do, and the empty branch
     * is the one that gets tested by accident.
     */
    #[Test]
    #[DataProvider('pagesNeedingAnEmployeeRecord')]
    public function a_page_needing_an_employee_record_refuses_politely_without_one(string $url): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get($url)
            ->assertForbidden()
            // The custom 403 page shows the written reason, so the person is
            // told what to do about it rather than just being stopped.
            ->assertSee('No employee profile is linked to your account.');
    }

    #[Test]
    #[DataProvider('pagesNeedingAnEmployeeRecord')]
    public function a_page_needing_an_employee_record_opens_with_one(string $url): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Employee');

        $this->actingAs($user)
            ->get($url)
            ->assertOk();
    }

    #[Test]
    #[DataProvider('adminPages')]
    public function an_administrator_with_an_employee_record_can_open_every_page(string $url): void
    {
        $employee = Employee::factory()->create();
        $admin = $employee->user ?? User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $admin->assignRole('Admin');
        $employee->forceFill(['user_id' => $admin->id])->save();

        $this->actingAs($admin)
            ->get($url)
            ->assertOk();
    }

    /** Pages every signed-in person reaches, whatever their role. */
    public static function selfServicePages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'my attendance' => ['/attendance'],
            'my profile' => ['/my-profile'],
            'my payslips' => ['/my-payslips'],
            'my commission' => ['/my-commission'],
            'file leave' => ['/leave-requests/create'],
            'my reimbursements' => ['/my-reimbursements'],
            'leave requests' => ['/leave-requests'],
            'overtime' => ['/overtime'],
            'requests' => ['/requests'],
            'cash advance requests' => ['/cash-advance-requests'],
        ];
    }

    #[Test]
    #[DataProvider('selfServicePages')]
    public function an_employee_can_open_their_own_pages(string $url): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Employee');

        $this->actingAs($user)
            ->get($url)
            ->assertOk();
    }

    /** Pages an employee must not reach. */
    public static function administrativePages(): array
    {
        return [
            'employees' => ['/employees'],
            'users' => ['/users'],
            'dtr' => ['/dtr'],
            'holidays' => ['/holidays'],
            'run payroll' => ['/payroll'],
            'payroll settings' => ['/payroll/settings'],
            'commission runs' => ['/commissions'],
            'commission schemes' => ['/commissions/schemes'],
            'system settings' => ['/settings'],
            'money in and out' => ['/money'],
            'bank details' => ['/bank-details'],
            'departments' => ['/org/departments'],
            'positions' => ['/org/positions'],
        ];
    }

    #[Test]
    #[DataProvider('administrativePages')]
    public function an_employee_is_refused_the_administrative_pages(string $url): void
    {
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Employee');

        $this->actingAs($user)
            ->get($url)
            ->assertForbidden();
    }

    #[Test]
    public function a_signed_out_visitor_is_sent_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/employees')->assertRedirect('/login');
        $this->get('/payroll')->assertRedirect('/login');
    }

    #[Test]
    public function a_disabled_account_cannot_reach_any_page(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $user->assignRole('Admin');

        // Not merely refused — signed out, so a session that was open when HR
        // disabled the account stops working on the next click rather than at
        // the next login.
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function the_health_check_answers_without_a_login(): void
    {
        $this->get('/up')->assertOk();
    }
}
