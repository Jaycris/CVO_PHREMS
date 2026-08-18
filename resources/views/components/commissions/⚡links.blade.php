<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Employee;
use App\Services\Crm\AgentLinkService;
use App\Services\Crm\CrmAgent;
use App\Services\Crm\CrmUnavailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ties each HRIS employee to their CRM account, once.
 *
 * The CRM cannot hold our employee id and its records carry company names,
 * aliases and phone names of their own, so nothing here links anything by
 * itself. The screen suggests, a person confirms, and the CRM's id is written
 * down with their name against it.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public string $filter = 'unlinked';
    public string $search = '';
    public ?string $statusMessage = null;

    /** employee id => the CRM id chosen in the dropdown for that row. */
    public array $choice = [];

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function refreshAgents(AgentLinkService $links): void
    {
        try {
            $links->agents(fresh: true);
            $this->statusMessage = 'Agent list re-read from the CRM.';
        } catch (CrmUnavailable $e) {
            $this->statusMessage = $e->getMessage();
        }
    }

    public function link(int $employeeId, AgentLinkService $links): void
    {
        $agentId = $this->choice[$employeeId] ?? null;

        if (! $agentId) {
            $this->statusMessage = 'Pick a CRM account first.';

            return;
        }

        try {
            $agent = $links->agents()->firstWhere('id', $agentId);
        } catch (CrmUnavailable $e) {
            $this->statusMessage = $e->getMessage();

            return;
        }

        if (! $agent) {
            $this->statusMessage = 'That CRM account is no longer in the list. Press Refresh and try again.';

            return;
        }

        $employee = Employee::findOrFail($employeeId);
        $links->link($employee, $agent, Auth::user());

        $this->statusMessage = ($employee->fullName() ?: $employee->employee_id)
            . ' is now linked to ' . $agent->fullName() . ' (' . ($agent->email ?: $agent->id) . ').';
    }

    public function unlink(int $employeeId, AgentLinkService $links): void
    {
        $employee = Employee::findOrFail($employeeId);
        $links->unlink($employee);

        $this->statusMessage = ($employee->fullName() ?: $employee->employee_id)
            . ' is no longer linked. Their commission page will say so until it is linked again.';
    }

    public function with(AgentLinkService $links): array
    {
        $agents = new Collection;
        $error = null;

        if (! $links->isConfigured()) {
            $error = 'The CRM connection has not been set up yet. Fill in CRM_API_BASE_URL and CRM_HRIS_API_TOKEN.';
        } else {
            try {
                $agents = $links->agents();
            } catch (CrmUnavailable $e) {
                $error = $e->getMessage();
            }
        }

        $taken = Employee::whereNotNull('crm_agent_id')->pluck('crm_agent_id', 'id');

        $employees = Employee::query()
            ->when($this->filter === 'unlinked', fn ($q) => $q->whereNull('crm_agent_id'))
            ->when($this->filter === 'linked', fn ($q) => $q->whereNotNull('crm_agent_id'))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('first_name', 'like', "%{$this->search}%")
                ->orWhere('last_name', 'like', "%{$this->search}%")
                ->orWhere('employee_id', 'like', "%{$this->search}%")
                ->orWhere('company_email', 'like', "%{$this->search}%")))
            ->orderBy('employee_id')
            ->paginate($this->perPage());

        $rows = $employees->getCollection()->map(function (Employee $employee) use ($links, $agents, $taken) {
            $suggestion = $employee->crm_agent_id ? ['agent' => null, 'basis' => null] : $links->suggestFor($employee, $agents);

            return (object) [
                'employee' => $employee,
                'linkedAgent' => $employee->crm_agent_id ? $agents->firstWhere('id', $employee->crm_agent_id) : null,
                'suggestion' => $suggestion['agent'],
                'basis' => $suggestion['basis'],
                'drift' => $links->driftFor($employee, $agents),
            ];
        });

        // A CRM account already spoken for is not offered again, so two
        // employees cannot be pointed at the same earnings by accident.
        $claimed = $taken->values()->all();

        return [
            'rows' => $rows,
            'employees' => $employees,
            'agents' => $agents,
            'claimedAgentIds' => $claimed,
            'error' => $error,
            'unlinkedCount' => Employee::whereNull('crm_agent_id')->count(),
            'linkedCount' => Employee::whereNotNull('crm_agent_id')->count(),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Link CRM Accounts</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            Confirm which CRM account belongs to each employee. Done once per person; every commission lookup afterwards uses it.
        </p>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="rounded-xl border border-brand-200 bg-brand-50 p-4 text-sm font-medium leading-6 text-brand-900 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-100">
        Nothing is matched automatically. A suggestion marked <span class="font-bold">by email</span> is as close to certain as
        this gets, since the CRM's Email Address is the company email. Anything weaker is a hint — check the name, phone
        and department before confirming, because the cost of getting it wrong is one agent seeing another's earnings.
    </div>

    <x-card class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-48">
                <x-label>Show</x-label>
                <x-select wire:model.live="filter">
                    <option value="unlinked">Not linked yet ({{ $unlinkedCount }})</option>
                    <option value="linked">Linked ({{ $linkedCount }})</option>
                    <option value="all">Everyone</option>
                </x-select>
            </div>

            <div class="min-w-64">
                <x-label>Search</x-label>
                <x-input wire:model.live.debounce.300ms="search" type="text" placeholder="Name, employee ID or email" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-button wire:click="refreshAgents" wire:loading.attr="disabled" wire:target="refreshAgents" variant="secondary">
                <span wire:loading.remove wire:target="refreshAgents">Refresh CRM list</span>
                <span wire:loading wire:target="refreshAgents">Reading…</span>
            </x-button>

            {{-- For the day the CRM can hold an HRIS Employee ID: the pairings
                 are already decided, so nobody has to decide them twice. --}}
            <x-button as="a" href="{{ route('commissions.links.export') }}" variant="secondary">
                <x-icon name="document" class="h-4 w-4" /> Export links (CSV)
            </x-button>
        </div>
    </x-card>

    @if ($error)
        <x-card>
            <div class="py-8 text-center">
                <p class="text-sm font-bold text-ink-900 dark:text-white">The CRM agent list could not be read.</p>
                <p class="mx-auto mt-2 max-w-xl text-sm font-medium text-ink-500 dark:text-ink-400">{{ $error }}</p>
                <p class="mx-auto mt-3 max-w-xl text-xs font-medium text-ink-500 dark:text-ink-400">
                    This screen needs <code class="font-mono">GET /api/hris/agents</code> on the CRM — see docs/crm-commission-api.md.
                </p>
            </div>
        </x-card>
    @else
        <x-card :padding="false" class="overflow-hidden rounded-2xl">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                    <thead class="bg-ink-50 dark:bg-white/5">
                        <tr>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Employee (HRIS)</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">CRM Account</th>
                            <th class="px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">Status</th>
                            <th class="px-5 py-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                        @forelse ($rows as $row)
                            @php($employee = $row->employee)
                            <tr wire:key="link-{{ $employee->id }}" class="align-top transition hover:bg-ink-50 dark:hover:bg-white/5">
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-ink-950 dark:text-white">{{ $employee->fullName() ?: $employee->employee_id }}</p>
                                    <p class="text-xs font-medium text-[#778599]">{{ $employee->employee_id }} · {{ $employee->company_email }}</p>
                                    <p class="text-xs font-medium text-[#778599]">{{ $employee->department?->name ?? '—' }}</p>
                                </td>

                                <td class="px-5 py-4">
                                    @if ($employee->crm_agent_id)
                                        <p class="font-semibold text-ink-950 dark:text-white">
                                            {{ $row->linkedAgent?->fullName() ?? ($employee->crm_agent_snapshot['name'] ?? $employee->crm_agent_id) }}
                                        </p>
                                        <p class="text-xs font-medium text-[#778599]">
                                            {{ $row->linkedAgent?->email ?? ($employee->crm_agent_snapshot['email'] ?? '—') }}
                                        </p>
                                        <p class="text-xs font-medium text-[#778599]">
                                            CRM ID <span class="font-mono">{{ $employee->crm_agent_id }}</span>
                                        </p>
                                    @else
                                        <select wire:model="choice.{{ $employee->id }}"
                                                class="w-full max-w-sm rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-medium text-ink-700 shadow-sm focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                                            <option value="">— pick the CRM account —</option>
                                            @foreach ($agents as $agent)
                                                @continue(in_array($agent->id, $claimedAgentIds, true))
                                                <option value="{{ $agent->id }}" @selected($row->suggestion?->id === $agent->id)>
                                                    {{ $agent->fullName() }}{{ $agent->email ? ' · ' . $agent->email : '' }}{{ $agent->department ? ' · ' . $agent->department : '' }}{{ $agent->brand ? ' · ' . $agent->brand : '' }}
                                                </option>
                                            @endforeach
                                        </select>

                                        @if ($row->suggestion)
                                            <p class="mt-1.5 text-xs font-medium text-[#778599]">
                                                Suggested by <span class="font-bold">{{ $row->basis }}</span>:
                                                {{ $row->suggestion->fullName() }}
                                                @if ($row->suggestion->phone) · {{ $row->suggestion->phone }} @endif
                                                @if ($row->suggestion->workType) · {{ $row->suggestion->workType }} @endif
                                            </p>
                                        @else
                                            <p class="mt-1.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                                                No CRM account matched their email, phone or name. Pick one by hand, or create them in the CRM first.
                                            </p>
                                        @endif
                                    @endif
                                </td>

                                <td class="px-5 py-4">
                                    @if ($employee->crm_agent_id)
                                        <x-badge color="green">Linked</x-badge>
                                        <p class="mt-1.5 text-xs font-medium text-[#778599]">
                                            {{ $employee->crm_linked_at?->format('M j, Y') }}
                                            @if ($employee->crmLinkedBy) by {{ $employee->crmLinkedBy->name }} @endif
                                        </p>
                                        @foreach ($row->drift as $warning)
                                            <p class="mt-1.5 text-xs font-semibold text-amber-600 dark:text-amber-400">{{ $warning }}</p>
                                        @endforeach
                                    @elseif ($row->basis === 'email')
                                        <x-badge color="brand">Match found</x-badge>
                                    @elseif ($row->basis)
                                        <x-badge color="amber">Check this one</x-badge>
                                    @else
                                        <x-badge color="neutral">No match</x-badge>
                                    @endif
                                </td>

                                <td class="px-5 py-4 text-right">
                                    @if ($employee->crm_agent_id)
                                        <x-button wire:click="unlink({{ $employee->id }})"
                                                  wire:confirm="Unlink this employee? Their commission page will stop showing figures until they are linked again."
                                                  variant="secondary" class="h-9 px-3 text-xs">Unlink</x-button>
                                    @else
                                        <x-button wire:click="link({{ $employee->id }})"
                                                  wire:loading.attr="disabled" wire:target="link({{ $employee->id }})"
                                                  class="h-9 px-3 text-xs">Confirm link</x-button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-10 text-center font-medium text-ink-500">
                                @if ($filter === 'unlinked')
                                    Everyone is linked.
                                @else
                                    Nobody matches that.
                                @endif
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($employees->hasPages())
                <div class="border-t border-ink-200 px-5 py-4 dark:border-white/10">
                    {{ $employees->links('components.pagination', ['noun' => 'employees']) }}
                </div>
            @endif
        </x-card>
    @endif
</div>
