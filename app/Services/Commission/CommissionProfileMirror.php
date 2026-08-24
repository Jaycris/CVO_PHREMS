<?php

namespace App\Services\Commission;

use App\Models\CommissionScheme;
use App\Models\Employee;
use App\Services\Crm\CommissionSlip as CrmCommissionSlip;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Support\Carbon;

/**
 * Copies an agent's scheme and target down from the CRM.
 *
 * The CRM owns both: it decides which plan somebody is on and what they are
 * measured against, and every commission figure is worked out from those. What
 * PHREMS keeps is a copy for HR to read on the employee's profile — and a copy
 * nobody refreshes is just a number that used to be true.
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
     * Whether this employee is one worth asking the CRM about.
     *
     * Most of the company is not on commission, and asking about them would be
     * an HTTP call per profile view to learn nothing.
     */
    public function tracks(Employee $employee): bool
    {
        return filled($employee->commission_scheme)
            || ($employee->commission_frequency && $employee->commission_frequency !== 'none');
    }

    /**
     * Asks the CRM and applies whatever comes back.
     *
     * Silent when the CRM cannot answer. This runs while somebody is looking at
     * a profile page, and a CRM that is down or has no user linked to this
     * employee must not turn that page into an error — the stored figures are
     * still the best answer available.
     */
    public function refresh(Employee $employee, ?Carbon $month = null): bool
    {
        if (! $this->tracks($employee) || ! $this->crm->isConfigured()) {
            return false;
        }

        $month ??= Carbon::now('Asia/Manila')->startOfMonth();

        try {
            $slip = $this->crm->forPeriod(
                $employee,
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            );
        } catch (CrmUnavailable) {
            return false;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return $this->apply($employee, $slip);
    }

    /**
     * Writes what the CRM said onto the employee record.
     *
     * Only when something actually differs, so a refresh that changes nothing
     * does not stamp updated_at on every agent whose profile gets opened.
     */
    public function apply(Employee $employee, CrmCommissionSlip $slip): bool
    {
        $changes = [];

        if ($slip->scheme !== null && $employee->commission_scheme !== $slip->scheme) {
            $this->rememberScheme($slip->scheme);
            $changes['commission_scheme'] = $slip->scheme;
        }

        if ($slip->target !== null && (float) $employee->quota !== $slip->target) {
            $changes['quota'] = $slip->target;
        }

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
