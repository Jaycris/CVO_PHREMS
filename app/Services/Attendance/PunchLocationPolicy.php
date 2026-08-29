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

    /**
     * Whether this employee works on-site.
     *
     * Compared case-insensitively and without punctuation: the value is free
     * text on the employee record, and "onsite", "On-site" and "On Site" are
     * the same answer.
     */
    public function isOnsite(?Employee $employee): bool
    {
        if (! $employee) {
            return false;
        }

        return strtolower(str_replace(['-', ' ', '_'], '', (string) $employee->workplace_type)) === 'onsite';
    }

    /** Whether this employee is one the rule applies to. */
    public function appliesTo(Employee $employee): bool
    {
        if (! $this->isEnforced()) {
            return false;
        }

        if (! $this->isOnsite($employee)) {
            return false;
        }

        return OfficeNetwork::anyConfigured();
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
     * Deliberately says nothing more than that.
     *
     * It used to name the address and invite the employee to pass it to HR so
     * it could be added, which reads as helpful and is the opposite. The rule
     * exists to stop people clocking in from home; handing somebody at home the
     * exact number and telling them how to get it approved is coaching them
     * through it. Anybody genuinely sitting in the office has a colleague
     * beside them clocking in fine, which is the only evidence HR needs.
     *
     * The address is not lost — every refusal is written to the log with the
     * employee and the address, which is where whoever is fixing a changed
     * office line should look, and it is on the Office Networks screen for
     * anybody who can reach it.
     */
    public function refusalMessage(?string $ip = null): string
    {
        return 'You can only clock in and out from the office. '
            . 'If you are in the office and seeing this, contact HR.';
    }
}
