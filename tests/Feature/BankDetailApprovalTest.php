<?php

namespace Tests\Feature;

use App\Models\BankDetailRequest;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Notifications\BankDetailActionNeeded;
use App\Notifications\BankDetailChangeNotice;
use App\Notifications\BankDetailStatusUpdated;
use App\Services\BankDetailService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who decides where someone's salary lands, and who gets told.
 *
 * This is the one field in the system that redirects money, so the two roles
 * are kept apart on purpose: the CEO or COO decides, HR and Accounting are
 * informed and cannot decide. The tests below are what stops those two
 * collapsing back into one permission the next time someone tidies up.
 */
class BankDetailApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected Employee $employee;

    protected User $employeeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Notification::fake();

        $this->employee = Employee::factory()->create();
        $this->employeeUser = User::factory()->create();
        $this->employee->forceFill(['user_id' => $this->employeeUser->id])->save();
        $this->employeeUser->assignRole('Employee');
        $this->employee->refresh();
    }

    protected function service(): BankDetailService
    {
        return new BankDetailService();
    }

    /** Someone who holds a permission through their position, as in real use. */
    protected function userWith(string $permission, string $title): User
    {
        $position = Position::factory()->create(['title' => $title]);
        $position->givePermissionTo($permission);

        $employee = Employee::factory()->create(['position_id' => $position->id]);
        $user = User::factory()->create();
        $employee->forceFill(['user_id' => $user->id])->save();
        $user->assignRole('Admin');

        return $user->fresh();
    }

    protected function giveExistingDetails(): void
    {
        $this->employee->forceFill([
            'bank_name' => 'BPI',
            'bank_account_name' => $this->employee->fullName(),
            'bank_account_number' => '1234567890',
        ])->save();

        $this->employee->refresh();
    }

    #[Test]
    public function the_first_account_an_employee_enters_needs_no_approval(): void
    {
        $request = $this->service()->submit($this->employee, 'BPI', 'Maria Santos', '1234567890');

        // Nothing to protect yet, so it is written straight through.
        $this->assertNull($request);
        $this->assertSame('1234567890', $this->employee->fresh()->bank_account_number);
    }

    #[Test]
    public function hr_and_accounting_are_told_when_a_first_account_is_set(): void
    {
        $hr = $this->userWith('bank_details.notify', 'Human Resources');

        $this->service()->submit($this->employee, 'BPI', 'Maria Santos', '1234567890');

        Notification::assertSentTo($hr, BankDetailChangeNotice::class);
    }

    #[Test]
    public function changing_an_account_does_not_touch_the_employee_record_until_approved(): void
    {
        $this->giveExistingDetails();

        $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777', 'Closed my BPI account');

        // The whole point of the approval. Filing must move nothing.
        $this->assertSame('BPI', $this->employee->fresh()->bank_name);
        $this->assertSame('1234567890', $this->employee->fresh()->bank_account_number);
    }

    #[Test]
    public function the_ceo_is_asked_to_decide_and_hr_is_only_informed(): void
    {
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');
        $hr = $this->userWith('bank_details.notify', 'Human Resources');
        $accounting = $this->userWith('bank_details.notify', 'Accounting');

        $this->giveExistingDetails();
        $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        Notification::assertSentTo($ceo, BankDetailActionNeeded::class);

        // HR and Accounting hear about it but are never asked to act on
        // something they have no permission to do.
        Notification::assertSentTo($hr, BankDetailChangeNotice::class);
        Notification::assertSentTo($accounting, BankDetailChangeNotice::class);
        Notification::assertNotSentTo($hr, BankDetailActionNeeded::class);
        Notification::assertNotSentTo($accounting, BankDetailActionNeeded::class);
    }

    #[Test]
    public function the_approver_is_not_told_twice_about_the_same_change(): void
    {
        // Somebody holding both permissions must still get one message, not a
        // "you must act" followed by a "for your information" about it.
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');
        $ceo->givePermissionTo('bank_details.notify');

        $this->giveExistingDetails();
        $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        Notification::assertSentTo($ceo, BankDetailActionNeeded::class);
        Notification::assertNotSentTo($ceo, BankDetailChangeNotice::class);
    }

    #[Test]
    public function a_company_with_no_accounting_simply_notifies_nobody_extra(): void
    {
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');

        $this->giveExistingDetails();
        $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        // No special case: the permission is held by nobody, so nobody is told.
        Notification::assertSentTo($ceo, BankDetailActionNeeded::class);
        Notification::assertNothingSentTo($this->employeeUser);
    }

    #[Test]
    public function approving_moves_the_money_and_tells_everyone(): void
    {
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');
        $hr = $this->userWith('bank_details.notify', 'Human Resources');

        $this->giveExistingDetails();
        $request = $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        $this->service()->decide($request, $ceo, approved: true);

        $this->assertSame('GCash', $this->employee->fresh()->bank_name);
        $this->assertSame('9998887777', $this->employee->fresh()->bank_account_number);

        Notification::assertSentTo($this->employeeUser, BankDetailStatusUpdated::class);
        Notification::assertSentTo($hr, BankDetailChangeNotice::class);
    }

    #[Test]
    public function declining_leaves_the_old_account_in_place(): void
    {
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');

        $this->giveExistingDetails();
        $request = $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        $this->service()->decide($request, $ceo, approved: false, note: 'Confirm this with me in person first.');

        $this->assertSame('BPI', $this->employee->fresh()->bank_name);
        $this->assertSame('declined', $request->fresh()->status);
    }

    #[Test]
    public function hr_cannot_decide_a_bank_detail_change(): void
    {
        $hr = $this->userWith('bank_details.notify', 'Human Resources');

        $this->giveExistingDetails();
        $request = $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        // Being told is not the same as being allowed. Without this, "notify"
        // would be a quiet second way to move somebody's salary.
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->decide($request, $hr, approved: true);
    }

    #[Test]
    public function an_employee_cannot_approve_their_own_change(): void
    {
        $this->giveExistingDetails();
        $request = $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->service()->decide($request, $this->employeeUser, approved: true);
    }

    #[Test]
    public function nobody_gets_a_memo_about_their_own_bank_account(): void
    {
        // The approver is also the person changing their own details.
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');
        $ceoEmployee = Employee::where('user_id', $ceo->id)->firstOrFail();
        $ceoEmployee->forceFill([
            'bank_name' => 'BPI',
            'bank_account_name' => 'Boss',
            'bank_account_number' => '1111111111',
        ])->save();

        $this->service()->submit($ceoEmployee->refresh(), 'GCash', 'Boss', '2222222222');

        Notification::assertNotSentTo($ceo, BankDetailChangeNotice::class);
    }

    #[Test]
    public function a_second_request_is_refused_while_one_is_still_waiting(): void
    {
        $this->giveExistingDetails();
        $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');

        $this->expectExceptionMessage('You already have a bank detail change waiting for approval.');
        $this->service()->submit($this->employee->refresh(), 'Maya', 'Maria Santos', '5556667777');
    }

    #[Test]
    public function a_decided_request_cannot_be_decided_again(): void
    {
        $ceo = $this->userWith('bank_details.approve', 'Chief Executive Officer');

        $this->giveExistingDetails();
        $request = $this->service()->submit($this->employee, 'GCash', 'Maria Santos', '9998887777');
        $this->service()->decide($request, $ceo, approved: true);

        $this->expectExceptionMessage('This request has already been decided.');
        $this->service()->decide($request->fresh(), $ceo, approved: false);
    }

    #[Test]
    public function the_account_number_is_masked_wherever_it_is_shown(): void
    {
        // Enough for whoever is approving to see that it changed, not enough
        // for someone glancing at the screen to write down.
        $this->assertSame('••••••7890', BankDetailRequest::maskAccount('1234567890'));
        $this->assertSame('••••', BankDetailRequest::maskAccount('1234'));
        $this->assertSame('—', BankDetailRequest::maskAccount(null));
    }
}
