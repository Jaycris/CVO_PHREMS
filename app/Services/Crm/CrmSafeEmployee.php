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
        /*
         * The phone name is optional, so it falls back to the real one.
         *
         * Most people use their own name for CRM work; the field exists for the
         * few who do not. Sending null when it is blank made it optional in
         * name only — the CRM's Create User form would open with no name in it,
         * so whoever filled it in had to know to go back and set the phone name
         * first.
         */
        [$firstName, $lastName] = self::splitPhoneName($employee->phone_name);

        if ($firstName === null) {
            $firstName = $employee->first_name ?: null;
            $lastName = $employee->last_name ?: null;
        }

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

            // Onsite, Hybrid or Remote. Sent under both names because the CRM
            // calls its field Work Type, and a mismatch there would be a silent
            // blank rather than an error the CRM developer would notice.
            'workplace_type' => $employee->workplace_type,
            'work_type' => $employee->workplace_type,

            // Offered as a Role suggestion only. The CRM decides its own access.
            'position' => $employee->position?->title,

            'employment_status' => $employee->employment_status,
            'employment_type' => $employee->employment_type,
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
