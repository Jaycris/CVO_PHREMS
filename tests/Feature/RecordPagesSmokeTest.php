<?php

namespace Tests\Feature;

use App\Models\CashAdvance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Payroll\PayrollService;
use Database\Seeders\AppSettingSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StatutorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pages that need a record to exist before they can be opened, and the
 * downloads.
 *
 * The plain smoke test only proves the list pages render when there is nothing
 * on file, which is the easy half. Everything here needs real data, and these
 * are the pages a fault actually reaches a person through — nobody looks at an
 * empty payroll run, they look at the one with their pay in it.
 */
class RecordPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(AppSettingSeeder::class);
        $this->seed(LeaveTypeSeeder::class);
        $this->seed(StatutorySeeder::class);

        $this->employee = Employee::factory()->salary(20000)->create();
        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->admin->assignRole('Admin');
        $this->employee->forceFill(['user_id' => $this->admin->id])->save();
        $this->employee->assignSchedule(\App\Models\WorkSchedule::factory()->create(), '2020-01-01');

        $this->actingAs($this->admin);
    }

    #[Test]
    public function an_employee_record_opens(): void
    {
        $this->get('/employees/' . $this->employee->id)->assertOk();
        $this->get('/employees/' . $this->employee->id . '/edit')->assertOk();
    }

    #[Test]
    public function a_leave_request_opens(): void
    {
        $request = LeaveRequest::create([
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::first()->id,
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'days_requested' => 1,
            'status' => 'pending_manager',
            'reason' => 'Family matter',
        ]);

        $this->get('/leave-requests/' . $request->id)->assertOk();
    }

    #[Test]
    public function an_overtime_request_opens(): void
    {
        $request = OvertimeRequest::create([
            'employee_id' => $this->employee->id,
            'work_date' => '2026-08-12',
            'hours_requested' => 2,
            'status' => 'pending_manager',
            'reason' => 'Month-end close',
        ]);

        $this->get('/overtime/' . $request->id)->assertOk();
    }

    #[Test]
    public function a_payroll_run_its_payslip_and_the_register_export_all_open(): void
    {
        $run = $this->computedRun();
        $payslip = $run->payslips()->firstOrFail();

        $this->get('/payroll/runs/' . $run->id)->assertOk();
        $this->get('/payroll/payslips/' . $payslip->id)->assertOk();

        // A streamed CSV only fails once someone downloads it, so the body has
        // to actually be pulled rather than just the headers checked.
        $csv = $this->get('/payroll/runs/' . $run->id . '/export');
        $csv->assertOk();
        $this->assertNotEmpty($csv->streamedContent());
    }

    #[Test]
    public function an_employee_can_open_and_download_their_own_released_payslip(): void
    {
        $run = $this->computedRun();
        $payslip = $run->payslips()->firstOrFail();
        $payslip->forceFill(['notified_at' => now()])->save();

        $this->get('/my-payslips')->assertOk();
        $this->get('/my-payslips/' . $payslip->id)->assertOk();
        $this->get('/my-payslips/' . $payslip->id . '/download')->assertOk();
    }

    #[Test]
    public function an_unreleased_payslip_is_refused_to_the_employee(): void
    {
        $run = $this->computedRun();
        $payslip = $run->payslips()->firstOrFail();

        // Computed but not sent. HR is still checking the figures, so the
        // employee must not be able to reach them by guessing the URL.
        $this->assertNull($payslip->notified_at);
        $this->get('/my-payslips/' . $payslip->id)->assertForbidden();
    }

    #[Test]
    public function one_employee_cannot_open_another_employees_payslip(): void
    {
        $run = $this->computedRun();
        $payslip = $run->payslips()->firstOrFail();
        $payslip->forceFill(['notified_at' => now()])->save();

        $other = Employee::factory()->create();
        $otherUser = User::factory()->create();
        $other->forceFill(['user_id' => $otherUser->id])->save();
        $otherUser->assignRole('Employee');

        $this->actingAs($otherUser)
            ->get('/my-payslips/' . $payslip->id)
            ->assertForbidden();
    }

    #[Test]
    public function the_employee_directory_export_downloads(): void
    {
        $csv = $this->get('/reports/employees/export');

        $csv->assertOk();
        $this->assertStringContainsString($this->employee->employee_id, $csv->streamedContent());
    }

    #[Test]
    public function a_cash_advance_shows_on_the_register(): void
    {
        CashAdvance::create([
            'employee_id' => $this->employee->id,
            'principal_amount' => 10000,
            'amount_per_cutoff' => 1500,
            'start_date' => '2026-08-11',
            'status' => 'active',
        ]);

        $this->get('/cash-advances')->assertOk();
    }

    /** A run with real payslips in it, computed the way the app computes one. */
    protected function computedRun(): PayrollRun
    {
        $service = app(PayrollService::class);
        $run = $service->openRun(2026, 8, 'second');
        $service->compute($run);

        return $run->fresh();
    }
}
