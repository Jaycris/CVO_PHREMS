<?php

/*
|--------------------------------------------------------------------------
| Permission catalogue
|--------------------------------------------------------------------------
|
| Access has three separate layers, and it helps to keep them straight:
|
|   Role      Admin or Employee. The access tier — whether a user can hold
|             administrative permissions at all. Nothing more.
|
|   Position  The job title (Human Resources, Accountant, Team Leader). Carries
|             the default permission set for everyone doing that job, so a new
|             HR hire is set up by assigning the position, not by ticking boxes.
|
|   Permission  What may actually be opened or done. Granted to a position, and
|             optionally to one user on top of their position's set.
|
| An Employee-tier user has no administrative permissions no matter what
| position they hold. Self-service pages — own profile, own attendance, filing
| leave, overtime and cash advance — are open to every signed-in user and are
| deliberately not listed here.
|
| Adding a permission here and reseeding makes it appear in the Positions and
| Users screens automatically.
|
*/

return [

    'groups' => [

        'Organization' => [
            'org.departments.manage' => 'Departments — add, edit and remove',
            'org.positions.manage' => 'Positions — add, edit and set what each position may access',
        ],

        'People' => [
            'recruitment.manage' => 'Recruitment — open roles, applicants and hiring',
            'employees.manage' => 'Employees — view the directory, onboard and edit records',
            'users.manage' => 'Users — create sign-in accounts and grant access',
        ],

        'Time & Attendance' => [
            'schedules.manage' => 'Work Schedules — create and assign shifts',
            'holidays.manage' => 'Holidays — keep the yearly list of holidays that payroll reads',
            'attendance.view_all' => 'DTR — view everyone\'s daily time records',
            'overtime.view_all' => 'Overtime — view all filings across the company',
        ],

        'Requests' => [
            'requests.view_all' => 'Requests — view every request, and decide those from employees with no manager',
            'requests.types.manage' => 'Request Types — add and edit the kinds of request employees can file',
        ],

        'Leave' => [
            'leave.types.manage' => 'Leave Types — configure entitlements and accrual',
            'leave.view_all' => 'Leave — view all requests across the company',
            'leave.approve' => 'Leave — give final approval',
        ],

        'Cash Advance' => [
            'cash_advances.view_all' => 'Cash Advance — view all requests and be notified of each one',
            'cash_advances.amend' => 'Cash Advance — change the amount and deduction plan on a pending request',
            'cash_advances.approve' => 'Cash Advance — give final approval and release the money',
            'cash_advances.manage' => 'Advance Register — record advances directly, pause and cancel them',
        ],

        'Commissions' => [
            'commissions.view_all' => 'Commission Slips — open and print any agent\'s slip',
            'commissions.runs.manage' => 'Commission Runs — open a month and compute it from the CRM',
            'commissions.runs.finalize' => 'Commission Runs — lock the figures and send the slips to agents',
        ],

        'Bank Details' => [
            'bank_details.approve' => 'Bank Details — approve changes to where an employee\'s salary is paid',
        ],

        'Reimbursements' => [
            'reimbursements.approve' => 'Reimbursements — approve what the company pays back for expenses',
            'reimbursements.view_all' => 'Reimbursements — view every claim across the company',
        ],

        'Payroll' => [
            'payroll.runs.manage' => 'Payroll — open a run, compute it and review the payslips',
            'payroll.runs.finalize' => 'Payroll — lock the figures and mark a run as paid',
            'payroll.runs.unlock' => 'Payroll — reopen a run that was already locked',
            'payroll.settings.manage' => 'Payroll Settings — government contribution rates, which cutoff they come out of, and company payroll policy',
        ],

        'Reports' => [
            'reports.view' => 'Reports — attendance summary and exports',
        ],

        'System' => [
            'app.settings.manage' => 'System Settings — how many rows the tables show, and other app-wide display options',
        ],

    ],

];
