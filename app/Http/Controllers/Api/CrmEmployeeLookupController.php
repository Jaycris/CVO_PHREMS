<?php

namespace App\Http\Controllers\Api;

use App\Models\Employee;
use App\Services\Crm\CrmSafeEmployee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the CRM's "HRIS Employee" search field talks to.
 *
 * Read-only, and it hands back only the fields in CrmSafeEmployee — the CRM has
 * no business holding anyone's TIN or salary, and the surest way to keep it that
 * way is for this app never to send them.
 */
class CrmEmployeeLookupController
{
    /** Type-ahead for the Create User form. */
    public function search(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $limit = min(max((int) $request->integer('limit', 15), 1), 50);

        $employees = Employee::with(['department', 'position'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('employee_id', 'like', "%{$term}%")
                ->orWhere('phone_name', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhere('company_email', 'like', "%{$term}%")))
            // People who have left sort last rather than vanishing: an admin
            // fixing up an old CRM user still needs to find them.
            ->orderByRaw('CASE WHEN separation_date IS NULL THEN 0 ELSE 1 END')
            ->orderBy('employee_id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $employees->map(fn (Employee $e) => CrmSafeEmployee::from($e))->all(),
            'count' => $employees->count(),
            'query' => $term,
        ]);
    }

    /**
     * One employee by HRIS Employee ID.
     *
     * This is what the CRM calls when editing a user that already carries an
     * hris_employee_id, to confirm the person still exists and re-read their
     * department, work type and email.
     */
    public function show(string $employeeId): JsonResponse
    {
        $employee = Employee::with(['department', 'position'])
            ->where('employee_id', $employeeId)
            ->first();

        if (! $employee) {
            return response()->json([
                'error' => 'not_found',
                'message' => "No HRIS employee with ID {$employeeId}.",
            ], 404);
        }

        return response()->json(['data' => CrmSafeEmployee::from($employee)]);
    }

    /**
     * A cheap "is HRIS up" check for the CRM to decide whether to offer the
     * search field or fall straight through to manual entry.
     */
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'creativision-hris',
            'employees' => Employee::whereNull('separation_date')->count(),
        ]);
    }
}
