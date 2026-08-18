<?php

use App\Models\Employee;
use App\Services\Crm\CommissionSlipService;
use App\Services\Crm\CrmUnavailable;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * HR's view of any agent's commission slip.
 *
 * Deliberately the same slip partial the agent sees, so a query raised over the
 * phone is answered from the identical screen.
 */
new #[Layout('layouts.app')] class extends Component
{
    public ?int $employeeId = null;
    public string $month = '';

    public function mount(): void
    {
        $this->month = now('Asia/Manila')->format('Y-m');
        $this->employeeId = Employee::orderBy('employee_id')->value('id');
    }

    public function refreshSlip(CommissionSlipService $service): void
    {
        if ($employee = $this->employee()) {
            $service->forget($employee, $this->month);
        }
    }

    protected function employee(): ?Employee
    {
        return $this->employeeId ? Employee::with(['department', 'position'])->find($this->employeeId) : null;
    }

    public function with(CommissionSlipService $service): array
    {
        $employee = $this->employee();
        $slip = null;
        $error = null;

        if (! $service->isConfigured()) {
            $error = 'The CRM connection has not been set up yet. Fill in CRM_API_BASE_URL and CRM_HRIS_API_TOKEN in the environment file.';
        } elseif (! $employee) {
            $error = 'Pick an agent to see their slip.';
        } else {
            try {
                $slip = $service->forEmployee($employee, $this->month);
            } catch (CrmUnavailable $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'slip' => $slip,
            'error' => $error,
            'employee' => $employee,
            'agentKey' => $employee ? $service->agentKey($employee) : null,
            'employees' => Employee::orderBy('employee_id')->get(['id', 'employee_id', 'first_name', 'last_name', 'crm_agent_id']),
            'monthOptions' => $employee ? $service->selectableMonths($employee, 24) : [],
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Commission Slips</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            Read live from the CRM. This app stores none of these figures and changes none of them.
        </p>
    </div>

    <x-card class="flex flex-wrap items-end gap-3">
        <div class="min-w-64">
            <x-label>Agent</x-label>
            <x-select wire:model.live="employeeId">
                @foreach ($employees as $option)
                    <option value="{{ $option->id }}">
                        {{ $option->employee_id }} — {{ trim($option->first_name . ' ' . $option->last_name) }}
                    </option>
                @endforeach
            </x-select>
        </div>

        <div class="min-w-48">
            <x-label>Month</x-label>
            <x-select wire:model.live="month">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
        </div>

        <x-button wire:click="refreshSlip" wire:loading.attr="disabled" wire:target="refreshSlip" variant="secondary">
            <span wire:loading.remove wire:target="refreshSlip">Refresh</span>
            <span wire:loading wire:target="refreshSlip">Fetching…</span>
        </x-button>

        @if ($slip && $employee)
            <x-button as="a" href="{{ route('my-commission.download', ['month' => $month, 'employee' => $employee->id]) }}">
                <x-icon name="document" class="h-4 w-4" /> Download PDF
            </x-button>
        @endif
    </x-card>

    @if ($agentKey)
        {{-- Shown because "the CRM has no record for this agent" is nearly
             always this key not matching what the CRM expects. --}}
        <p class="text-xs font-medium text-ink-500 dark:text-ink-400">
            Asking the CRM for agent <span class="font-mono font-bold text-ink-700 dark:text-ink-300">{{ $agentKey }}</span>.
            @if (! $employee?->crm_agent_id)
                That is their company email, because no CRM agent ID is set on their record.
            @endif
        </p>
    @endif

    @if ($error)
        <x-card>
            <div class="py-8 text-center">
                <p class="text-sm font-bold text-ink-900 dark:text-white">Nothing to show.</p>
                <p class="mx-auto mt-2 max-w-xl text-sm font-medium text-ink-500 dark:text-ink-400">{{ $error }}</p>
            </div>
        </x-card>
    @else
        <x-commission-slip :slip="$slip" />
    @endif
</div>
