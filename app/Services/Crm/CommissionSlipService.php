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

        $payload = Cache::remember(
            "crm:commission:{$agent}:{$month}",
            (int) config('services.crm.cache_ttl', 300),
            fn () => $this->client->get('/api/hris/commission-slip', [
                'agent' => $agent,
                'month' => $month,
            ]),
        );

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

    public function forget(Employee $employee, string $month): void
    {
        Cache::forget("crm:commission:{$this->agentKey($employee)}:{$this->normaliseMonth($month)}");
    }

    /**
     * How the CRM is asked to identify this person.
     *
     * crm_agent_id when HR has filled it in, otherwise the company email — so
     * the integration works whether the CRM keys agents by its own id or by
     * their address, without anyone having to backfill a column first.
     */
    public function agentKey(Employee $employee): string
    {
        return (string) ($employee->crm_agent_id ?: $employee->company_email ?: $employee->employee_id);
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
