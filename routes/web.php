<?php

use App\Http\Controllers\EmployeeExportController;
use App\Http\Controllers\PayrollRegisterExportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function () {
    Route::livewire('/login', 'auth.login')->name('login');
});

Route::middleware('signed')->group(function () {
    Route::livewire('/onboarding/{employee}', 'public.onboarding-form')->name('onboarding.show');
    Route::livewire('/set-password/{user}', 'public.set-password')->name('password.setup');
});

Route::middleware('auth')->group(function () {
    /*
     * Self-service. Open to every signed-in user, whatever their access tier —
     * these pages only ever show the signer's own record.
     */
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');
    Route::livewire('/attendance', 'attendance.punch-clock')->name('attendance.punch');
    Route::livewire('/my-profile', 'my-profile')->name('my-profile');

    // The page decides what belongs to the signer; there is no permission for
    // "your own payslip".
    Route::livewire('/my-payslips', 'my-payslips')->name('my-payslips');
    Route::livewire('/my-payslips/{payslip}', 'my-payslips')->name('my-payslips.show');

    /*
     * Filing and approving. Everyone may file; the pages themselves decide what
     * else is shown, since a supervisor's queue comes from who reports to them
     * rather than from a permission.
     */
    Route::livewire('/overtime', 'overtime.index')->name('overtime.index');
    Route::livewire('/overtime/create', 'overtime.create')->name('overtime.create');
    Route::livewire('/overtime/{overtimeRequest}', 'overtime.show')->name('overtime.show');

    Route::livewire('/cash-advance-requests', 'cash-advance-requests.index')->name('cash-advance-requests.index');

    Route::livewire('/leave-requests', 'leave-requests.index')->name('leave-requests.index');
    Route::livewire('/leave-requests/create', 'leave-requests.create')->name('leave-requests.create');
    Route::livewire('/leave-requests/{leaveRequest}', 'leave-requests.show')->name('leave-requests.show');

    /*
     * Administration. Each page is gated on the permission it needs, resolved
     * from the user's position and any individual grants.
     */
    Route::middleware('can:org.departments.manage')->group(function () {
        Route::livewire('/org/departments', 'org.departments')->name('org.departments');
    });

    Route::middleware('can:org.positions.manage')->group(function () {
        Route::livewire('/org/positions', 'org.positions')->name('org.positions');
    });

    Route::middleware('can:users.manage')->group(function () {
        Route::livewire('/users', 'users.index')->name('users.index');
    });

    Route::middleware('can:employees.manage')->group(function () {
        Route::livewire('/employees', 'employees.index')->name('employees.index');
        Route::livewire('/employees/create', 'employees.create')->name('employees.create');
        Route::livewire('/employees/{employee}/edit', 'employees.edit')->name('employees.edit');
        Route::livewire('/employees/{employee}', 'employees.show')->name('employees.show');
    });

    Route::middleware('can:schedules.manage')->group(function () {
        Route::livewire('/schedules', 'schedules.index')->name('schedules.index');
    });

    Route::middleware('can:attendance.view_all')->group(function () {
        Route::livewire('/dtr', 'attendance.dtr')->name('attendance.dtr');
    });

    Route::middleware('can:leave.types.manage')->group(function () {
        Route::livewire('/leave-types', 'leave-types.index')->name('leave-types.index');
    });

    Route::middleware('can:cash_advances.manage')->group(function () {
        Route::livewire('/cash-advances', 'cash-advances.index')->name('cash-advances.index');
    });

    Route::middleware('can:payroll.settings.manage')->group(function () {
        Route::livewire('/payroll/settings', 'payroll.settings')->name('payroll.settings');
    });

    Route::middleware('can:payroll.runs.manage')->group(function () {
        Route::livewire('/payroll', 'payroll.index')->name('payroll.index');
        Route::livewire('/payroll/13th-month', 'payroll.thirteenth-month')->name('payroll.thirteenth-month');
        Route::livewire('/payroll/runs/{run}', 'payroll.show')->name('payroll.show');
        Route::livewire('/payroll/payslips/{payslip}', 'payroll.payslip')->name('payroll.payslip');
        Route::get('/payroll/runs/{run}/export', PayrollRegisterExportController::class)->name('payroll.export');
    });

    Route::middleware('can:reports.view')->group(function () {
        Route::livewire('/reports/attendance-summary', 'reports.attendance-summary')->name('reports.attendance-summary');
        Route::get('/reports/employees/export', EmployeeExportController::class)->name('reports.employees.export');
    });
});
