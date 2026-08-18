<?php

namespace App\Services\Crm;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches an agent's commission slip from the CRM.
 *
 * The CRM is the only authority on these figures. Nothing in this class adds,
 * multiplies or converts anything — if a number is wrong on the slip it is
 * wrong in the CRM, which is exactly where it should be fixed.
 */
class CommissionSlipService
{
    public function __construct(
        protected CrmClient $client,
    ) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @throws CrmUnavailable
     */
    public function forEmployee(Employee $employee, string $month): CommissionSlip
    {
        $month = $this->normaliseMonth($month);
        $agent = $this->agentKey($employee);

        if ($agent === null) {
            throw CrmUnavailable::notLinked();
        }

        $payload = Cache::remember(
            "crm:commission:{$agent}:{$month}",
            (int) config('services.crm.cache_ttl', 300),
            // The employee id is sent as well as the CRM's own agent key. The
            // CRM does not have to store it — but if it ever echoes it back,
            // the check below turns a one-way link into a two-way one.
            fn () => $this->client->get('/api/hris/commission-slip', [
                'agent' => $agent,
                'month' => $month,
                'hris_employee_id' => $employee->employee_id,
            ]),
        );

        $this->assertBelongsTo($payload, $employee);

        $slip = CommissionSlip::fromCrm($payload, $month);

        // The CRM knows the agent by its own name for them; the HRIS record is
        // the one HR maintains. Prefer HR's when the CRM did not send one.
        return $slip->agentName !== null
            ? $slip
            : new CommissionSlip(
                month: $slip->month,
                agentName: $employee->fullName() ?: $employee->employee_id,
                team: $slip->team ?? $employee->department?->name,
                workType: $slip->workType ?? $employee->position?->title,
                mtd: $slip->mtd,
                target: $slip->target,
                mtdPercent: $slip->mtdPercent,
                serviceCommission: $slip->serviceCommission,
                markupCommission: $slip->markupCommission,
                usdTotal: $slip->usdTotal,
                exchangeRate: $slip->exchangeRate,
                phpTotal: $slip->phpTotal,
                cardHoldPercent: $slip->cardHoldPercent,
                cardHoldAmount: $slip->cardHoldAmount,
                netCommission: $slip->netCommission,
                transactions: $slip->transactions,
                statementSupplied: $slip->statementSupplied,
            );
    }

    /**
     * Refuses a reply that is about somebody else.
     *
     * The stored link already says which CRM account to ask about. This is the
     * other half: if the CRM also names the employee in its answer, the two
     * must agree. When they do not, something has been rewired at one end and
     * showing the figures anyway would be showing one agent another's money.
     *
     * Silent when the CRM sends no employee id — the check strengthens the link
     * rather than replacing it, so it costs nothing to add before the CRM
     * supports it.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws CrmUnavailable
     */
    protected function assertBelongsTo(array $payload, Employee $employee): void
    {
        $agent = is_array($payload['agent'] ?? null) ? $payload['agent'] : [];

        $claimed = $payload['hris_employee_id']
            ?? $agent['hris_employee_id']
            ?? $payload['employee_id']
            ?? $agent['employee_id']
            ?? null;

        if ($claimed === null || trim((string) $claimed) === '') {
            return;
        }

        if (trim((string) $claimed) !== trim((string) $employee->employee_id)) {
            throw CrmUnavailable::wrongEmployee((string) $claimed, (string) $employee->employee_id);
        }
    }

    public function forget(Employee $employee, string $month): void
    {
        Cache::forget("crm:commission:{$this->agentKey($employee)}:{$this->normaliseMonth($month)}");
    }

    /**
     * How the CRM is asked to identify this person: the HRIS employee id.
     *
     * The CRM stores that against its own user, so there is nothing to match on
     * and nothing to guess. A CRM user that has not been given the id simply
     * answers 404, and the page says so — which is the right answer, unlike a
     * name or email match that would be silently wrong some of the time.
     */
    public function agentKey(Employee $employee): ?string
    {
        return $employee->employee_id ?: null;
    }

    /** Every cached month for this employee, for when their link changes. */
    public function forgetAll(Employee $employee): void
    {
        foreach (array_keys($this->selectableMonths($employee, 36)) as $month) {
            $this->forget($employee, $month);
        }
    }

    /** @return array<string, string> Y-m => "August 2026", newest first. */
    public function selectableMonths(Employee $employee, int $count = 12): array
    {
        $cursor = Carbon::now('Asia/Manila')->startOfMonth();
        $earliest = ($employee->hire_date ?? $cursor)->copy()->startOfMonth();
        $months = [];

        while ($cursor->gte($earliest) && count($months) < $count) {
            $months[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor = $cursor->copy()->subMonthNoOverflow();
        }

        return $months;
    }

    protected function normaliseMonth(string $month): string
    {
        try {
            return Carbon::createFromFormat('Y-m', $month)->format('Y-m');
        } catch (\Throwable) {
            return Carbon::now('Asia/Manila')->format('Y-m');
        }
    }
}
