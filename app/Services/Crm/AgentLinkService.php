<?php

namespace App\Services\Crm;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Ties an HRIS employee to a CRM account, once, on the word of a named person.
 *
 * The CRM cannot store our employee id, and its names, aliases and phone names
 * are its own. So this app never infers who is who at request time. It offers a
 * suggestion, a person confirms it, and the CRM's own id is written down. Every
 * commission lookup afterwards uses that id and nothing else.
 *
 * The cost of getting this wrong is showing one agent another agent's earnings,
 * which is why nothing here links anything on its own.
 */
class AgentLinkService
{
    public function __construct(
        protected CrmClient $client,
        protected CommissionSlipService $slips,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Every user the CRM knows about.
     *
     * @return Collection<int, CrmAgent>
     *
     * @throws CrmUnavailable
     */
    public function agents(bool $fresh = false): Collection
    {
        if ($fresh) {
            Cache::forget('crm:agents');
        }

        $payload = Cache::remember(
            'crm:agents',
            (int) config('services.crm.cache_ttl', 300),
            fn () => $this->client->get('/api/hris/agents'),
        );

        // Tolerates both a bare array and one wrapped in "data" or "agents".
        $rows = $payload['data'] ?? $payload['agents'] ?? $payload;

        if (! is_array($rows)) {
            throw CrmUnavailable::malformed('the agent list was not an array.');
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => CrmAgent::fromCrm($row))
            ->filter()
            ->values();
    }

    /**
     * The CRM account this employee most likely is, and how sure we are.
     *
     * Confidence is deliberately blunt: 'email' is worth confirming at a glance,
     * anything weaker deserves a proper look, and no match at all is stated
     * rather than filled with the nearest name.
     *
     * @param  Collection<int, CrmAgent>  $agents
     * @return array{agent: ?CrmAgent, basis: ?string}
     */
    public function suggestFor(Employee $employee, Collection $agents): array
    {
        $email = mb_strtolower(trim((string) $employee->company_email));

        if ($email !== '') {
            $match = $agents->first(fn (CrmAgent $a) => $a->normalisedEmail() === $email);

            if ($match) {
                return ['agent' => $match, 'basis' => 'email'];
            }
        }

        $phone = preg_replace('/\D+/', '', (string) $employee->personal_contact_number) ?? '';
        $phone = strlen(ltrim($phone, '0')) >= 10 ? substr(ltrim($phone, '0'), -10) : null;

        if ($phone) {
            $match = $agents->first(fn (CrmAgent $a) => $a->normalisedPhone() === $phone);

            if ($match) {
                return ['agent' => $match, 'basis' => 'phone'];
            }
        }

        // Name last and only when it is unambiguous. Two people called Maria
        // Santos is not a hypothetical in a company this size.
        $name = mb_strtolower(trim($employee->first_name . ' ' . $employee->last_name));

        if ($name !== '') {
            $byName = $agents->filter(fn (CrmAgent $a) => mb_strtolower($a->fullName()) === $name);

            if ($byName->count() === 1) {
                return ['agent' => $byName->first(), 'basis' => 'name'];
            }
        }

        return ['agent' => null, 'basis' => null];
    }

    /** Writes the confirmed pairing, with who said so and what they saw. */
    public function link(Employee $employee, CrmAgent $agent, User $actor): void
    {
        abort_if(
            Employee::where('crm_agent_id', $agent->id)->where('id', '!=', $employee->id)->exists(),
            422,
            'That CRM account is already linked to another employee. Unlink it there first.'
        );

        $employee->update([
            'crm_agent_id' => $agent->id,
            'crm_linked_at' => now(),
            'crm_linked_by_user_id' => $actor->id,
            'crm_agent_snapshot' => $agent->toSnapshot(),
        ]);

        // A stale slip cached against the old key would otherwise survive.
        $this->slips->forgetAll($employee);
    }

    public function unlink(Employee $employee): void
    {
        $this->slips->forgetAll($employee);

        $employee->update([
            'crm_agent_id' => null,
            'crm_linked_at' => null,
            'crm_linked_by_user_id' => null,
            'crm_agent_snapshot' => null,
        ]);
    }

    /**
     * Whether the CRM account has drifted from what was confirmed.
     *
     * The link survives an email change in the CRM, which is the point of
     * storing an id — but an email that no longer matches is worth someone
     * looking at, because the alternative reading is that the CRM account was
     * handed to a different person.
     *
     * @param  Collection<int, CrmAgent>  $agents
     * @return list<string>
     */
    public function driftFor(Employee $employee, Collection $agents): array
    {
        if (! $employee->crm_agent_id) {
            return [];
        }

        $agent = $agents->firstWhere('id', $employee->crm_agent_id);

        if (! $agent) {
            return ['The CRM no longer lists the account this employee is linked to.'];
        }

        $warnings = [];
        $hrisEmail = mb_strtolower(trim((string) $employee->company_email));

        if ($hrisEmail !== '' && $agent->normalisedEmail() !== null && $agent->normalisedEmail() !== $hrisEmail) {
            $warnings[] = "The CRM account's email is now {$agent->email}, not {$employee->company_email}.";
        }

        $snapshotName = $employee->crm_agent_snapshot['name'] ?? null;

        if ($snapshotName && $snapshotName !== $agent->fullName()) {
            $warnings[] = "The CRM account is now named {$agent->fullName()}; it was {$snapshotName} when linked.";
        }

        return $warnings;
    }
}
