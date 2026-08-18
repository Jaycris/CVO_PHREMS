<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The confirmed HRIS ↔ CRM pairings, as a CSV.
 *
 * The point of this file is the day the CRM can hold an HRIS Employee ID of its
 * own. Rather than someone re-deciding every match by hand a second time, they
 * paste this in: each row already says which CRM account was confirmed to be
 * which employee, and by whom.
 */
class CrmLinkExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $employees = Employee::with('crmLinkedBy')->orderBy('employee_id')->get();

        return response()->streamDownload(function () use ($employees) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'HRIS Employee ID', 'Employee Name', 'Company Email', 'Department',
                'CRM Agent ID', 'CRM Name At Linking', 'CRM Email At Linking',
                'Linked On', 'Linked By',
            ]);

            foreach ($employees as $employee) {
                fputcsv($handle, [
                    $employee->employee_id,
                    $employee->fullName(),
                    $employee->company_email,
                    $employee->department?->name,
                    $employee->crm_agent_id ?: 'NOT LINKED',
                    $employee->crm_agent_snapshot['name'] ?? null,
                    $employee->crm_agent_snapshot['email'] ?? null,
                    $employee->crm_linked_at?->format('Y-m-d'),
                    $employee->crmLinkedBy?->name,
                ]);
            }

            fclose($handle);
        }, 'crm-agent-links-' . now()->format('Y-m-d') . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
