<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $columns = [
            'Employee ID', 'Full Name', 'Phone Name', 'Company Email', 'Department', 'Position',
            'Hire Date', 'Employment Status', 'Basic Salary', 'Allowance',
            'Reports To', 'Personal Contact', 'Personal Email', 'Civil Status',
        ];

        $employees = Employee::with(['department', 'position', 'reportsTo'])->orderBy('employee_id')->get();

        return response()->streamDownload(function () use ($columns, $employees) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            foreach ($employees as $employee) {
                fputcsv($handle, [
                    $employee->employee_id,
                    $employee->fullName(),
                    $employee->phone_name,
                    $employee->company_email,
                    $employee->department->name,
                    $employee->position->title,
                    $employee->hire_date->format('Y-m-d'),
                    $employee->employment_status,
                    $employee->basic_salary,
                    $employee->allowance,
                    $employee->reportsTo?->fullName(),
                    $employee->personal_contact_number,
                    $employee->personal_email,
                    $employee->civil_status,
                ]);
            }

            fclose($handle);
        }, 'employee-master-list-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
