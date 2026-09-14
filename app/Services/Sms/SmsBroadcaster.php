<?php

namespace App\Services\Sms;

use App\Models\Employee;
use App\Models\SmsBroadcast;
use App\Models\User;

/**
 * Sends one text to every current employee, and writes down that it happened.
 *
 * Kept apart from the notification channels on purpose. A notification is about
 * one thing that happened to one person; this is a broadcast somebody composed
 * and chose to pay for, and the interesting facts afterwards are what was sent
 * and how many it reached — not which employee got which copy.
 */
class SmsBroadcaster
{
    public function __construct(protected SmsGateway $gateway) {}

    /**
     * Who a broadcast would reach, before sending one.
     *
     * Both numbers matter. The contact number has never been a required field
     * and a landline cannot receive a text, so "48 of 52" is the difference
     * between believing everybody was told and knowing four were not.
     *
     * @return array{total: int, reachable: int}
     */
    public function reach(?array $employeeIds = null): array
    {
        $numbers = $this->recipients($employeeIds)->pluck('personal_contact_number');

        return [
            'total' => $numbers->count(),
            'reachable' => $numbers->filter(fn ($n) => PhoneNumber::isSendable($n))->count(),
        ];
    }

    /**
     * Sends it, and records what happened.
     *
     * The message is composed once, so every handset gets the same characters
     * and the row stores exactly what was sent rather than what was typed.
     *
     * Separated staff are excluded. Somebody who left last month has no reason
     * to be told the office is closed, and every credit spent on them is one
     * spent annoying a former colleague.
     */
    public function send(string $message, ?User $actor = null, ?array $employeeIds = null): SmsBroadcast
    {
        $body = $this->gateway->compose($message);

        $attempted = 0;
        $sent = 0;
        $skipped = 0;

        foreach ($this->recipients($employeeIds) as $employee) {
            if (! PhoneNumber::isSendable($employee->personal_contact_number)) {
                $skipped++;

                continue;
            }

            $attempted++;

            // The gateway swallows its own failures, so one dead number cannot
            // stop the other fifty-one.
            if ($this->gateway->send($employee->personal_contact_number, $body)) {
                $sent++;
            }
        }

        return SmsBroadcast::create([
            'message' => $body,
            'recipients_attempted' => $attempted,
            'recipients_sent' => $sent,
            'recipients_skipped' => $skipped,
            'employee_ids' => $employeeIds === [] ? null : $employeeIds,
            'sent_by_user_id' => $actor?->id,
        ]);
    }

    /**
     * Who the message is for.
     *
     * Null means everybody currently employed. A list means exactly those
     * people — still filtered on being employed, so an id left over from a
     * stale form cannot text somebody who has since left.
     *
     * Not filtered on having a sign-in account, unlike the email notices: a
     * text reaches a phone, and somebody onboarding this week has a mobile
     * number long before they have a login.
     *
     * @param  list<int>|null  $employeeIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    protected function recipients(?array $employeeIds = null)
    {
        return Employee::query()
            ->whereNull('separation_date')
            ->when($employeeIds !== null && $employeeIds !== [], fn ($q) => $q->whereKey($employeeIds))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * The people who can be picked, for the chooser.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Employee>
     */
    public function selectableStaff(string $search = '')
    {
        return Employee::query()
            ->whereNull('separation_date')
            ->when($search !== '', fn ($q) => $q->search($search))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }
}
