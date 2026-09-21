<?php

namespace App\Services\Sms;

use App\Models\Employee;

/**
 * The one text a new hire gets to say this number is how the company reaches
 * them.
 *
 * HR ticks it on the employee form. It goes once all of these are true —
 * ticked, onboarding complete, password set, account enabled, employee still
 * active — whichever of them happens last. That is why this is asked at each of
 * those moments rather than on a schedule.
 */
class WelcomeText
{
    /**
     * One text, not two. The gateway cuts everything to 160 characters to keep
     * each message to one credit; this is 149.
     *
     * No "PhremsCVO:" in front, unlike the other texts — the company asked for
     * it to open with the welcome. The sender name already shows as PhremsCVO.
     */
    public const MESSAGE = 'Welcome to CreatiVision Outsourcing! This is the official PHREMS channel for SMS. '
        . "You'll get announcements, HR updates, reminders and schedules here.";

    public function __construct(protected SmsGateway $gateway) {}

    public static function body(): string
    {
        return self::MESSAGE;
    }

    /** Whether everything is in place and it has not gone yet. */
    public static function isDue(Employee $employee): bool
    {
        return $employee->welcome_sms
            && $employee->welcome_sms_sent_at === null
            && $employee->onboarding_completed_at !== null
            && $employee->user?->password_set_at !== null
            && $employee->user->is_active
            // Not somebody on their way out, or already gone.
            && $employee->statusLabel() === 'Active';
    }

    /**
     * Sends it if it is due. Returns whether it went now.
     *
     * The send time is claimed in one conditional write before anything is
     * sent, so two of the three moments landing together still send one text.
     * If the gateway refuses — usually no mobile number on file — the claim is
     * given back, and it goes the next time HR saves the employee.
     */
    public function sendIfReady(Employee $employee): bool
    {
        $employee->loadMissing('user');

        if (! self::isDue($employee)) {
            return false;
        }

        $claimed = Employee::whereKey($employee->id)
            ->whereNull('welcome_sms_sent_at')
            ->update(['welcome_sms_sent_at' => now()]);

        if ($claimed === 0) {
            return false;
        }

        $sent = $this->gateway->send($employee->personal_contact_number, self::body());

        if (! $sent) {
            Employee::whereKey($employee->id)->update(['welcome_sms_sent_at' => null]);
        }

        $employee->refresh();

        return $sent;
    }
}
