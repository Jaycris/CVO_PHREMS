<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveCreditTransaction;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two people deciding the same leave request at the same moment.
 *
 * This cannot happen locally: `php artisan serve` handles one request at a
 * time, so every race in this app is invisible until it is on real hosting
 * running several PHP processes at once. These tests stand in for that by
 * replaying the second click against the state the first one left behind,
 * which is exactly what the loser of a race sees.
 *
 * A leave credit is paid time. Deducting it twice takes a day's pay from
 * somebody, and the only trace is a balance that quietly does not add up.
 */
class LeaveConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected Employee $ceo;

    protected LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        Notification::fake();

        $this->leaveType = LeaveType::first();

        $this->employee = Employee::factory()->create(['employment_status' => 'Regular']);
        $this->ceo = Employee::factory()->create(['employment_status' => 'Regular']);

        foreach ([$this->employee, $this->ceo] as $person) {
            $user = User::factory()->create();
            $person->forceFill(['user_id' => $user->id])->save();
            $person->refresh();
        }

        // Enough credits that the request is paid leave rather than LWOP.
        LeaveCreditTransaction::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->leaveType->id,
            'transaction_date' => now()->toDateString(),
            'amount' => 10,
            'reason' => 'opening_balance',
        ]);
    }

    protected function service(): LeaveService
    {
        return new LeaveService();
    }

    protected function pendingCeoRequest(): LeaveRequest
    {
        $request = $this->service()->submit(
            $this->employee->refresh(),
            $this->leaveType,
            '2026-09-14',
            '2026-09-16',
            'Family matter',
        );

        // No manager on the factory employee, so it lands with the CEO.
        $this->assertSame('pending_ceo', $request->status);
        $this->assertFalse((bool) $request->is_lwop);

        return $request;
    }

    #[Test]
    public function the_second_approval_of_the_same_request_is_refused(): void
    {
        $request = $this->pendingCeoRequest();

        $this->service()->ceoDecide($request, $this->ceo, approved: true);

        // The second click arrives holding a copy loaded before the first one
        // landed — still saying "pending_ceo". The lock and re-read are what
        // stop it going through on that stale copy.
        $stale = LeaveRequest::find($request->id);
        $stale->setRawAttributes(['status' => 'pending_ceo'] + $stale->getRawOriginal());

        $this->expectExceptionMessage('This request is not awaiting CEO/COO approval.');
        $this->service()->ceoDecide($stale, $this->ceo, approved: true);
    }

    #[Test]
    public function approving_deducts_the_credits_exactly_once(): void
    {
        $request = $this->pendingCeoRequest();

        $this->service()->ceoDecide($request, $this->ceo, approved: true);

        try {
            $this->service()->ceoDecide($request->fresh(), $this->ceo, approved: true);
        } catch (\Throwable) {
            // Expected — the point is what the balance says afterwards.
        }

        $deductions = LeaveCreditTransaction::where('leave_request_id', $request->id)->get();

        $this->assertCount(1, $deductions, 'the leave credits were deducted more than once');
        $this->assertSame(-3.0, (float) $deductions->first()->amount);
        $this->assertSame(7.0, $this->employee->refresh()->leaveBalance($this->leaveType));
    }

    #[Test]
    public function a_declined_request_deducts_nothing(): void
    {
        $request = $this->pendingCeoRequest();

        $this->service()->ceoDecide($request, $this->ceo, approved: false);

        $this->assertSame(0, LeaveCreditTransaction::where('leave_request_id', $request->id)->count());
        $this->assertSame(10.0, $this->employee->refresh()->leaveBalance($this->leaveType));
    }

    #[Test]
    public function the_status_change_and_the_deduction_land_together_or_not_at_all(): void
    {
        $request = $this->pendingCeoRequest();

        $this->service()->ceoDecide($request, $this->ceo, approved: true);

        // The pair has to be indivisible. An approved request with no
        // deduction is free leave; a deduction with no approval charges
        // somebody for a day off they never got.
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame(1, LeaveCreditTransaction::where('leave_request_id', $request->id)->count());
    }

    #[Test]
    public function the_second_manager_decision_is_refused_too(): void
    {
        $manager = Employee::factory()->create();
        $managerUser = User::factory()->create();
        $manager->forceFill(['user_id' => $managerUser->id])->save();

        $this->employee->forceFill(['reports_to_id' => $manager->id])->save();

        $request = $this->service()->submit(
            $this->employee->refresh(),
            $this->leaveType,
            '2026-09-14',
            '2026-09-16',
            'Family matter',
        );

        $this->assertSame('pending_manager', $request->status);

        $this->service()->managerDecide($request, approved: true);

        $stale = LeaveRequest::find($request->id);
        $stale->setRawAttributes(['status' => 'pending_manager'] + $stale->getRawOriginal());

        $this->expectExceptionMessage('This request is not awaiting manager approval.');
        $this->service()->managerDecide($stale, approved: true);
    }

    #[Test]
    public function two_punches_on_the_same_day_cannot_make_two_attendance_rows(): void
    {
        // Not a lock — a unique key on employee_id + work_date. The database
        // refuses the duplicate whichever process gets there second, which is
        // the one guarantee that holds no matter how many PHP processes the
        // host decides to run.
        \App\Models\AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-09-14',
            'time_in' => '2026-09-14 09:00:00',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        \App\Models\AttendanceDay::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-09-14',
            'time_in' => '2026-09-14 09:00:05',
        ]);
    }
}
