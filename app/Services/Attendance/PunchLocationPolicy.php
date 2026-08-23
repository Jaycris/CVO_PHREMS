<?php

namespace App\Services\Attendance;

use App\Models\AppSetting;
use App\Models\Employee;
use App\Models\OfficeNetwork;

/**
 * Whether someone may clock in from where they are.
 *
 * Only on-site staff are held to it. Hybrid and remote employees are expected
 * to be anywhere, so checking their address would be checking a rule that does
 * not exist.
 *
 * Everything here fails open on purpose, and each case is worth stating:
 *
 *   The check is off        Nobody is stopped. It stays off until somebody
 *                           turns it on, so installing this does not lock a
 *                           company out of its own attendance overnight.
 *
 *   No networks on file     Nobody is stopped. An empty list is far more
 *                           likely to mean "not set up yet" than "no address
 *                           on earth is acceptable".
 *
 *   Workplace type not set  Nobody is stopped. Every employee record here
 *                           currently has this blank, and treating blank as
 *                           on-site would stop the whole company clocking in
 *                           the moment the switch is turned on.
 *
 * The cost of failing open is an employee clocking in from home who should
 * not have. The cost of failing closed is the entire workforce unable to
 * start their shift, with nobody able to fix it but an administrator who also
 * cannot clock in. Those are not comparable.
 */
class PunchLocationPolicy
{
    public const SETTING = 'attendance_ip_restriction_enabled';

    public const ONSITE = 'Onsite';

    /** Whether the company has switched the check on at all. */
    public function isEnforced(): bool
    {
        return AppSetting::flag(self::SETTING, false);
    }

    /** Whether this employee is one the rule applies to. */
    public function appliesTo(Employee $employee): bool
    {
        if (! $this->isEnforced()) {
            return false;
        }

        // Compared case-insensitively: the value is free text on the employee
        // record, and "onsite" and "On-site" are the same answer.
        $type = strtolower(str_replace(['-', ' ', '_'], '', (string) $employee->workplace_type));

        if ($type !== 'onsite') {
            return false;
        }

        return OfficeNetwork::active()->exists();
    }

    /** Whether this employee may clock in or out from this address. */
    public function allows(Employee $employee, ?string $ip): bool
    {
        if (! $this->appliesTo($employee)) {
            return true;
        }

        return OfficeNetwork::contains($ip);
    }

    /**
     * What to tell someone who is turned away.
     *
     * Names the address they came from, because the first thing whoever fixes
     * this needs is the number to add to the list — and the employee is the
     * only one who can read it off their own screen.
     */
    public function refusalMessage(?string $ip): string
    {
        return 'You can only clock in and out from the office network. '
            . 'This device is on ' . ($ip ?: 'an unknown address')
            . '. If you are in the office, give that number to HR so they can add it.';
    }
}
