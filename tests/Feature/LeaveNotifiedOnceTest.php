<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestActionNeeded;
use App\Notifications\LeaveRequestStatusUpdated;
use App\Services\LeaveService;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * One leave request, one notification each.
 *
 * The approver was told to act on it, and everybody with leave.view_all was
 * told it had been filed. The CEO holds both, so one request arrived twice —
 * once as a job and once as news about the same job.
 */
class LeaveNotifiedOnceTest extends TestCase
{
    use RefreshDatabase;

    protected LeaveService $leave;

    protected LeaveType $vacation;

    protected Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->leave = new LeaveService;
        $this->vacation = LeaveType::where('code', 'VL')->sole();

        $this->staff = $this->employeeWithLogin('Ric', 'Moreno');
    }

    protected function employeeWithLogin(string $first, string $last, array $permissions = []): Employee
    {
        $user = User::factory()->create(['name' => $first . ' ' . $last]);

        // Leave permissions only count for Admin accounts — see
        // User::scopeWithPermission, which every recipient list goes through.
        $user->assignRole($permissions === [] ? 'Employee' : 'Admin');

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $employee = Employee::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'employment_status' => 'Regular',
            'hire_date' => '2024-01-05',
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();

        return $employee->fresh();
    }

    protected function file(): \App\Models\LeaveRequest
    {
        return $this->leave->submit($this->staff, $this->vacation, '2026-10-05', '2026-10-06', 'Family matters.');
    }

    #[Test]
    public function the_ceo_who_both_approves_and_watches_leave_is_told_once(): void
    {
        Notification::fake();

        // No manager on the employee, so approval falls to the CEO.
        $ceo = $this->employeeWithLogin('Jay', 'Cris', ['leave.approve', 'leave.view_all']);

        $this->file();

        Notification::assertSentToTimes($ceo->user, LeaveRequestActionNeeded::class, 1);
        Notification::assertNotSentTo($ceo->user, LeaveRequestStatusUpdated::class);
    }

    #[Test]
    public function hr_who_only_watches_still_gets_the_copy(): void
    {
        Notification::fake();

        $ceo = $this->employeeWithLogin('Jay', 'Cris', ['leave.approve']);
        $hr = $this->employeeWithLogin('Ana', 'Reyes', ['leave.view_all']);

        $this->file();

        Notification::assertSentToTimes($ceo->user, LeaveRequestActionNeeded::class, 1);
        Notification::assertSentToTimes($hr->user, LeaveRequestStatusUpdated::class, 1);
    }

    #[Test]
    public function nobody_is_told_they_filed_their_own_leave(): void
    {
        Notification::fake();

        // HR filing their own leave sees it in front of them already.
        $this->staff->user->givePermissionTo('leave.view_all');
        $this->employeeWithLogin('Jay', 'Cris', ['leave.approve']);

        $this->file();

        Notification::assertNotSentTo($this->staff->user, LeaveRequestStatusUpdated::class);
    }

    #[Test]
    public function the_manager_hears_it_once_and_the_ceo_hears_the_next_step_once(): void
    {
        Notification::fake();

        $manager = $this->employeeWithLogin('Maria', 'Santos', ['leave.view_all']);
        $this->staff->update(['reports_to_id' => $manager->id]);

        $ceo = $this->employeeWithLogin('Jay', 'Cris', ['leave.approve', 'leave.view_all']);

        $request = $this->file();

        Notification::assertSentToTimes($manager->user, LeaveRequestActionNeeded::class, 1);
        Notification::assertNotSentTo($manager->user, LeaveRequestStatusUpdated::class);
        // Not their turn yet, so the CEO only hears it was filed.
        Notification::assertSentToTimes($ceo->user, LeaveRequestStatusUpdated::class, 1);

        $this->leave->managerDecide($request, approved: true);

        Notification::assertSentToTimes($ceo->user, LeaveRequestActionNeeded::class, 1);

        // And it says who approved it, so the CEO knows this is the second stage.
        Notification::assertSentTo($ceo->user, LeaveRequestActionNeeded::class, function ($notification) use ($ceo) {
            return str_contains($notification->toArray($ceo->user)['message'], 'Maria Santos approved Ric Moreno');
        });
        // Still the one from filing; no second copy about the manager's approval.
        Notification::assertSentToTimes($ceo->user, LeaveRequestStatusUpdated::class, 1);
        Notification::assertSentToTimes($manager->user, LeaveRequestStatusUpdated::class, 0);
    }

    #[Test]
    public function the_email_says_who_approved_it_before_it_reached_the_ceo(): void
    {
        Notification::fake();

        $manager = $this->employeeWithLogin('Maria', 'Santos');
        $this->staff->update(['reports_to_id' => $manager->id]);
        $ceo = $this->employeeWithLogin('Jay', 'Cris', ['leave.approve']);

        $request = $this->file();

        $filed = (new LeaveRequestActionNeeded($request->fresh()))->toMail($manager->user)->render();
        $this->assertStringContainsString('requested 2 day(s)', $filed);

        $this->leave->managerDecide($request, approved: true);

        $second = (new LeaveRequestActionNeeded($request->fresh()))->toMail($ceo->user)->render();
        $this->assertStringContainsString('Maria Santos', $second);
        $this->assertStringContainsString('approved', $second);
    }

    #[Test]
    public function the_ceo_deciding_it_is_not_told_about_their_own_decision(): void
    {
        Notification::fake();

        $ceo = $this->employeeWithLogin('Jay', 'Cris', ['leave.approve', 'leave.view_all']);
        $request = $this->file();

        $this->leave->ceoDecide($request, $ceo, approved: true);

        Notification::assertNotSentTo($ceo->user, LeaveRequestStatusUpdated::class);
        // The employee still hears the outcome.
        Notification::assertSentToTimes($this->staff->user, LeaveRequestStatusUpdated::class, 1);
    }
}
