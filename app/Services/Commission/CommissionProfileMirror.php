<?php

namespace App\Services\Commission;

use App\Models\CommissionScheme;
use App\Models\Employee;
use App\Services\Crm\CommissionSlip as CrmCommissionSlip;
use App\Services\Crm\CommissionSlipService;
use Illuminate\Support\Carbon;

/**
 * Copies an agent's commission setup down from the CRM.
 *
 * The CRM owns all of it: whether somebody earns commission at all, which plan
 * they are on, and what they are measured against. Every figure is worked out
 * from those, so what PHREMS keeps is a copy — and a copy nobody refreshes is
 * just a number that used to be true.
 *
 * Reads the whole agent list in one request rather than asking about each
 * employee in turn. That is not only cheaper: asking per-employee could only
 * ever confirm somebody already believed to be an agent, so it could switch
 * people off and never on — which is the wrong half of the problem. Forgetting
 * to mark a new agent is what costs somebody their commission.
 *
 * One way only. Nothing here writes back, so a mistake in PHREMS cannot change
 * what an agent is actually paid.
 */
class CommissionProfileMirror
{
    public function __construct(
        protected CommissionSlipService $crm,
    ) {}

    /**
     * Brings one employee's record in line with the CRM.
     *
     * Silent when the CRM cannot answer or does not know this person. This runs
     * while somebody is looking at a profile, and neither a CRM that is down
     * nor an employee who is not an agent should change anything on the page.
     */
    public function refresh(Employee $employee, ?Carbon $month = null): bool
    {
        if (! $this->crm->isConfigured() || blank($employee->employee_id)) {
            return false;
        }

        $month ??= Carbon::now('Asia/Manila')->startOfMonth();

        $directory = $this->crm->agentDirectory($month);

        // The CRM answered but has never heard of them. That is not the same as
        // "no longer an agent" — a CRM that is unreachable returns an empty
        // directory too — so nothing is switched off on the strength of it.
        if (! array_key_exists($employee->employee_id, $directory)) {
            return false;
        }

        return $this->applyDirectoryEntry($employee, $directory[$employee->employee_id]);
    }

    /**
     * @param  array{eligible: bool, scheme: ?string, target: ?float}  $entry
     */
    public function applyDirectoryEntry(Employee $employee, array $entry): bool
    {
        $changes = $this->frequencyChange($employee, $entry['eligible']);

        // Only carried across for people who actually earn commission. Copying
        // a plan onto somebody the CRM says is not an agent would put a scheme
        // on a profile that then hides it, which reads as a bug.
        if ($entry['eligible']) {
            $changes += $this->schemeChange($employee, $entry['scheme']);
            $changes += $this->targetChange($employee, $entry['target']);
        }

        return $this->write($employee, $changes);
    }

    /** Applies what a fetched slip says, used while a run is computing. */
    public function apply(Employee $employee, CrmCommissionSlip $slip): bool
    {
        $changes = $this->schemeChange($employee, $slip->scheme)
            + $this->targetChange($employee, $slip->target);

        return $this->write($employee, $changes);
    }

    /**
     * The CRM answers yes or no; this field also carries how often.
     *
     * So a yes keeps whatever cadence somebody already chose, and only falls
     * back to monthly when there is nothing to keep. The CRM has no concept of
     * a run frequency, and overwriting a deliberate bi-weekly choice with
     * monthly on every page view would be inventing an answer it never gave.
     *
     * @return array<string, mixed>
     */
    protected function frequencyChange(Employee $employee, bool $eligible): array
    {
        $current = $employee->commission_frequency;

        $wanted = $eligible
            ? ($current !== 'none' ? $current : 'monthly')
            : 'none';

        return $current === $wanted ? [] : ['commission_frequency' => $wanted];
    }

    /** @return array<string, mixed> */
    protected function schemeChange(Employee $employee, ?string $scheme): array
    {
        if ($scheme === null || $employee->commission_scheme === $scheme) {
            return [];
        }

        $this->rememberScheme($scheme);

        return ['commission_scheme' => $scheme];
    }

    /** @return array<string, mixed> */
    protected function targetChange(Employee $employee, ?float $target): array
    {
        if ($target === null || (float) $employee->quota === $target) {
            return [];
        }

        return ['quota' => $target];
    }

    /**
     * Writes only when something differs.
     *
     * A refresh that changes nothing must not stamp updated_at on every agent
     * whose profile happens to get opened.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function write(Employee $employee, array $changes): bool
    {
        if ($changes === []) {
            return false;
        }

        $employee->update($changes);

        return true;
    }

    /**
     * Records a scheme the CRM named but PHREMS has not seen.
     *
     * Without this an agent would sit on a plan the employee form cannot offer,
     * and the next time somebody edited that employee the dropdown would
     * silently move them off it.
     */
    protected function rememberScheme(string $name): void
    {
        CommissionScheme::firstOrCreate(
            ['name' => $name],
            [
                'description' => 'Added automatically from the CRM.',
                'is_active' => true,
                'sort_order' => (int) CommissionScheme::max('sort_order') + 1,
            ],
        );
    }
}
