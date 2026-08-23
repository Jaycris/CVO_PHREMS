<?php

namespace App\Services\Commission;

use App\Models\CommissionScheme;
use App\Models\Employee;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Copies an agent's scheme and target down from the CRM.
 *
 * The CRM owns both. It decides which plan someone is on and what they are
 * measured against, and it recalculates every figure from those. What PHREMS
 * keeps is a copy for HR to look at on the employee's profile — and a copy
 * that nobody refreshes is just a number that used to be true.
 *
 * Deliberately one-way. Nothing here writes back to the CRM, so a mistake in
 * PHREMS cannot change what an agent is actually paid.
 */
class CommissionProfileSync
{
    public function __construct(
        protected CommissionSlipService $slips,
    ) {}

    /**
     * Brings one employee's record in line with the CRM.
     *
     * @return array{changed: bool, scheme: ?string, target: ?float, was: array<string, mixed>, error: ?string}
     */
    public function sync(Employee $employee, ?Carbon $month = null): array
    {
        $month ??= Carbon::now()->startOfMonth();

        $was = [
            'scheme' => $employee->commission_scheme,
            'target' => $employee->quota === null ? null : (float) $employee->quota,
        ];

        try {
            $slip = $this->slips->forPeriod(
                $employee,
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            );
        } catch (CrmUnavailable $e) {
            return [...compact('was'), 'changed' => false, 'scheme' => null, 'target' => null, 'error' => $e->getMessage()];
        }

        $changes = [];

        if ($slip->scheme !== null) {
            // An agent must never end up on a plan the dropdown does not
            // offer, or their next edit would silently move them off it.
            $this->rememberScheme($slip->scheme);
            $changes['commission_scheme'] = $slip->scheme;
        }

        if ($slip->target !== null) {
            $changes['quota'] = $slip->target;
        }

        // Only writes when something actually differs, so a sync that changes
        // nothing does not stamp updated_at on every employee in the company.
        $changes = array_filter(
            $changes,
            fn ($value, $key) => (string) $employee->{$key} !== (string) $value,
            ARRAY_FILTER_USE_BOTH
        );

        if ($changes !== []) {
            $employee->update($changes);
        }

        return [
            'changed' => $changes !== [],
            'scheme' => $slip->scheme,
            'target' => $slip->target,
            'was' => $was,
            'error' => null,
        ];
    }

    /**
     * Syncs everyone who is on commission.
     *
     * One agent's CRM record being missing must not stop the rest, so each is
     * caught on its own and reported rather than thrown.
     *
     * @param  Collection<int, Employee>|null  $employees
     * @return array{synced: int, changed: int, failed: array<string, string>}
     */
    public function syncAll(?Collection $employees = null, ?Carbon $month = null): array
    {
        $employees ??= Employee::query()
            ->where('commission_frequency', '!=', 'none')
            ->orWhereNotNull('commission_scheme')
            ->get();

        $changed = 0;
        $failed = [];

        foreach ($employees as $employee) {
            try {
                $result = $this->sync($employee, $month);

                if ($result['error'] !== null) {
                    $failed[$employee->employee_id] = $result['error'];

                    continue;
                }

                $changed += $result['changed'] ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
                $failed[$employee->employee_id] = $e->getMessage();
            }
        }

        return [
            'synced' => $employees->count(),
            'changed' => $changed,
            'failed' => $failed,
        ];
    }

    /**
     * Makes sure a scheme the CRM named exists on the PHREMS list.
     *
     * Created inactive-safe: it is added as usable, because the CRM is already
     * paying somebody under it. Refusing to record it would leave the employee
     * form unable to show the truth.
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
