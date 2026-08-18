<?php

namespace App\Services\Crm;

use App\Models\Employee;

/**
 * The only shape of an employee that ever leaves this app for the CRM.
 *
 * This is an allow-list, not a deny-list, and that is the whole point. A new
 * column added to `employees` next month cannot leak through here by accident —
 * it simply will not appear until someone writes a line for it and thinks about
 * whether it belongs.
 *
 * Never add: birthdate, civil status, TIN, SSS, PhilHealth, Pag-IBIG, basic
 * salary, allowance, personal contact, emergency contact, or address. The CRM
 * has no use for any of it and every copy of it is another place it can leak.
 */
class CrmSafeEmployee
{
    /** @return array<string, mixed> */
    public static function from(Employee $employee): array
    {
        [$firstName, $lastName] = self::splitPhoneName($employee->phone_name);

        return [
            // The bridge between the two systems. The CRM stores this against
            // its user; everything else here is a convenience for the form.
            'hris_employee_id' => $employee->employee_id,

            // The name used for CRM work, not the legal name on the 201 file.
            'phone_name' => $employee->phone_name,
            'first_name' => $firstName,
            'last_name' => $lastName,

            'email' => $employee->company_email,
            'department' => $employee->department?->name,
            'work_type' => $employee->work_type,

            // Offered as a Role suggestion only. The CRM decides its own access.
            'position' => $employee->position?->title,

            'employment_status' => $employee->employment_status,
            // A separated employee stays searchable so an existing CRM user can
            // still be traced back, but the CRM should not create new ones.
            'is_active' => ! $employee->isSeparated(),
        ];
    }

    /**
     * Splits the phone name the way the CRM expects.
     *
     * Two or more words: the first is the first name, everything after it is
     * the last name — so "Maria Bell Cruz" gives "Maria" and "Bell Cruz"
     * rather than dropping the middle word.
     *
     * One word: it is the first name and the last name is left empty, for the
     * CRM admin to finish. Guessing a surname would be inventing one.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function splitPhoneName(?string $phoneName): array
    {
        $parts = preg_split('/\s+/', trim((string) $phoneName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return match (true) {
            $parts === [] => [null, null],
            count($parts) === 1 => [$parts[0], null],
            default => [array_shift($parts), implode(' ', $parts)],
        };
    }
}
