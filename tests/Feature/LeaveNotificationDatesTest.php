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
use Tests\TestCase;

/**
 * Telling two leave requests apart in the notification bell.
 *
 * The wording never said which days a request covered, so somebody filing one
 * day in November and another a fortnight later produced two lines reading
 * word for word the same. It looked like one notification sent twice, and the
 * only way to find out was to open both requests.
 */
class LeaveNotificationDatesTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $staff;

    protected LeaveType $vacation;

    protected LeaveService $leave;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);

        $this->vacation = LeaveType::where('name', 'Vacation Leave')->firstOrFail();
        $this->leave = app(LeaveService::class);

        $this->staff = Employee::factory()->create(['first_name' => 'Ric', 'last_name' => 'Moreno']);
        $this->staff->forceFill(['user_id' => User::factory()->create()->id])->save();
        $this->staff->refresh();
    }

    protected function approver(array $permissions): Employee
    {
        $employee = Employee::factory()->create(['first_name' => 'Jay', 'last_name' => 'Cris']);
        $user = User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Admin');
        $user->givePermissionTo($permissions);

        return $employee->fresh();
    }

    #[Test]
    public function two_requests_from_one_person_do_not_read_the_same(): void
    {
        Notification::fake();

        $ceo = $this->approver(['leave.approve']);

        // The exact shape that caused it: same person, same leave type, one
        // day each, a fortnight apart.
        $this->leave->submit($this->staff, $this->vacation, '2026-11-06', '2026-11-06', null);
        $this->leave->submit($this->staff->fresh(), $this->vacation, '2026-11-20', '2026-11-20', null);

        $messages = [];

        Notification::assertSentTo($ceo->user, LeaveRequestActionNeeded::class, function ($notification) use ($ceo, &$messages) {
            $messages[] = $notification->toArray($ceo->user)['message'];

            return true;
        });

        $this->assertCount(2, $messages);
        $this->assertNotSame($messages[0], $messages[1], 'the two read identically, which is the whole bug');
        $this->assertStringContainsString('Nov 06, 2026', implode(' ', $messages));
        $this->assertStringContainsString('Nov 20, 2026', implode(' ', $messages));
    }

    #[Test]
    public function the_dates_survive_the_manager_step(): void
    {
        Notification::fake();

        $manager = $this->approver(['leave.view_all']);
        $this->staff->update(['reports_to_id' => $manager->id]);

        $ceo = Employee::factory()->create(['first_name' => 'Chief', 'last_name' => 'Exec']);
        $ceoUser = User::factory()->create();
        $ceo->forceFill(['user_id' => $ceoUser->id])->save();
        $ceoUser->assignRole('Admin');
        $ceoUser->givePermissionTo('leave.approve');

        $request = $this->leave->submit($this->staff->fresh(), $this->vacation, '2026-11-06', '2026-11-06', null);
        $this->leave->managerDecide($request, approved: true);

        Notification::assertSentTo($ceoUser->fresh(), LeaveRequestActionNeeded::class, function ($notification) use ($ceoUser) {
            return str_contains($notification->toArray($ceoUser)['message'], 'Nov 06, 2026');
        });
    }

    #[Test]
    public function a_range_reads_as_a_range(): void
    {
        Notification::fake();

        // "from Nov 06 to Nov 06" reads like a mistake, so a single day says
        // "on". A real range has to say both ends.
        $ceo = $this->approver(['leave.approve']);

        $this->leave->submit($this->staff, $this->vacation, '2026-11-06', '2026-11-09', null);

        Notification::assertSentTo($ceo->user, LeaveRequestActionNeeded::class, function ($notification) use ($ceo) {
            $message = $notification->toArray($ceo->user)['message'];

            return str_contains($message, 'Nov 06, 2026') && str_contains($message, 'Nov 09, 2026');
        });
    }

    #[Test]
    public function the_decision_told_to_hr_carries_the_dates_too(): void
    {
        Notification::fake();

        $hr = $this->approver(['leave.view_all']);
        $ceo = Employee::factory()->create(['first_name' => 'Chief', 'last_name' => 'Exec']);
        $ceoUser = User::factory()->create();
        $ceo->forceFill(['user_id' => $ceoUser->id])->save();
        $ceoUser->assignRole('Admin');
        $ceoUser->givePermissionTo('leave.approve');

        $request = $this->leave->submit($this->staff->fresh(), $this->vacation, '2026-11-20', '2026-11-20', null);
        $this->leave->ceoDecide($request, $ceo->fresh(), true);

        Notification::assertSentTo($hr->user, LeaveRequestStatusUpdated::class, function ($notification) use ($hr) {
            return str_contains($notification->toArray($hr->user)['message'], 'Nov 20, 2026');
        });
    }
}
