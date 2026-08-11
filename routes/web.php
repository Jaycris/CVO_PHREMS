<?php

use App\Http\Controllers\EmployeeExportController;
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
    Route::livewire('/dashboard', 'dashboard')->name('dashboard');

    Route::livewire('/attendance', 'attendance.punch-clock')->name('attendance.punch');

    Route::livewire('/my-profile', 'my-profile')->name('my-profile');

    Route::livewire('/overtime', 'overtime.index')->name('overtime.index');
    Route::livewire('/overtime/create', 'overtime.create')->name('overtime.create');
    Route::livewire('/overtime/{overtimeRequest}', 'overtime.show')->name('overtime.show');

    Route::livewire('/leave-requests', 'leave-requests.index')->name('leave-requests.index');
    Route::livewire('/leave-requests/create', 'leave-requests.create')->name('leave-requests.create');
    Route::livewire('/leave-requests/{leaveRequest}', 'leave-requests.show')->name('leave-requests.show');

    Route::middleware('role:Admin|HR')->group(function () {
        Route::livewire('/org/departments', 'org.departments')->name('org.departments');
        Route::livewire('/org/positions', 'org.positions')->name('org.positions');

        Route::livewire('/users', 'users.index')->name('users.index');

        Route::livewire('/employees', 'employees.index')->name('employees.index');
        Route::livewire('/employees/create', 'employees.create')->name('employees.create');
        Route::livewire('/employees/{employee}/edit', 'employees.edit')->name('employees.edit');
        Route::livewire('/employees/{employee}', 'employees.show')->name('employees.show');

        Route::livewire('/schedules', 'schedules.index')->name('schedules.index');

        Route::livewire('/dtr', 'attendance.dtr')->name('attendance.dtr');

        Route::livewire('/leave-types', 'leave-types.index')->name('leave-types.index');

        Route::livewire('/cash-advances', 'cash-advances.index')->name('cash-advances.index');

        Route::livewire('/reports/attendance-summary', 'reports.attendance-summary')->name('reports.attendance-summary');
        Route::get('/reports/employees/export', EmployeeExportController::class)->name('reports.employees.export');
    });
});
