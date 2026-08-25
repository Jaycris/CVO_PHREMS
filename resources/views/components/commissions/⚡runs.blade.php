<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CommissionRun;
use App\Services\Commission\CommissionRunService;
use App\Services\Crm\CommissionSlipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One run per month, listed newest first.
 *
 * Deliberately the same shape as the payroll run list: open a month, compute
 * it, check it, finalize it, send it. Anyone who has run a payroll already
 * knows how this screen works.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public bool $showOpen = false;
    public ?string $statusMessage = null;
    public ?string $errorMessage = null;

    public string $runType = 'monthly';
    public string $month = '';
    public string $periodStart = '';
    public string $periodEnd = '';

    /** 'all' | 'specific' — who the run covers. */
    public string $agentMode = 'all';
    /** @var list<int> */
    public array $selectedAgents = [];
    public string $agentSearch = '';
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function mount(): void
    {
        $this->month = now('Asia/Manila')->format('Y-m');
        $this->applyPreset();
    }

    public function openForm(): void
    {
        $this->runType = 'monthly';
        $this->month = now('Asia/Manila')->format('Y-m');
        $this->applyPreset();
        $this->agentMode = 'all';
        $this->selectedAgents = [];
        $this->agentSearch = '';
        $this->resetValidation();
        $this->errorMessage = null;
        $this->showOpen = true;
    }

    /** Ticks everyone by default, so "specific" starts from all and narrows. */
    public function updatedAgentMode(CommissionRunService $service): void
    {
        if ($this->agentMode === 'specific' && $this->selectedAgents === []) {
            $this->selectedAgents = $service->defaultAgentsFor($this->runType)->pluck('id')->all();
        }
    }

    public function selectAllAgents(CommissionRunService $service): void
    {
        $this->selectedAgents = $service->selectableAgents()->pluck('id')->all();
    }

    public function selectMatchingAgents(CommissionRunService $service): void
    {
        $this->selectedAgents = $service->suggestedAgentsFor($this->runType)->pluck('id')->all();
    }

    public function selectNoAgents(): void
    {
        $this->selectedAgents = [];
    }

    public function updatedRunType(): void
    {
        $this->applyPreset();
    }

    public function updatedMonth(): void
    {
        $this->applyPreset();
    }

    /**
     * Fills the dates from the type, as a starting point only.
     *
     * Bi-weekly guesses the first half of the month because that is the common
     * case, not because it is a rule — where the split falls varies by agent,
     * so both dates stay editable and Custom leaves them alone entirely.
     */
    protected function applyPreset(): void
    {
        try {
            $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        } catch (\Throwable) {
            return;
        }

        if ($this->runType === 'monthly') {
            $this->periodStart = $start->toDateString();
            $this->periodEnd = $start->copy()->endOfMonth()->toDateString();
        } elseif ($this->runType === 'biweekly') {
            $this->periodStart = $start->toDateString();
            $this->periodEnd = $start->copy()->day(15)->toDateString();
        }
    }

    /** Named openRun, not open: see the note in components/modal.blade.php. */
    public function openRun(CommissionRunService $service): void
    {
        $this->errorMessage = null;

        $this->validate([
            'runType' => ['required', 'in:monthly,biweekly,custom'],
            'periodStart' => ['required', 'date'],
            'periodEnd' => ['required', 'date', 'after_or_equal:periodStart'],
        ], [
            'periodEnd.after_or_equal' => 'The end date is before the start date.',
        ]);

        try {
            $run = $service->openRun(
                $this->periodStart,
                $this->periodEnd,
                $this->runType,
                Auth::user(),
                agentIds: $this->agentMode === 'specific' ? $this->selectedAgents : null,
            );
        } catch (\Throwable $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showOpen = false;

        $this->redirect(route('commissions.run-show', $run), navigate: true);
    }

    /** @return array<string, string> */
    public function monthOptions(): array
    {
        $cursor = Carbon::now('Asia/Manila')->startOfMonth();
        $months = [];

        for ($i = 0; $i < 18; $i++) {
            $months[$cursor->format('Y-m')] = $cursor->format('F Y');
            $cursor = $cursor->copy()->subMonthNoOverflow();
        }

        return $months;
    }

    public function with(CommissionSlipService $crm, CommissionRunService $runs): array
    {
        return [
            'runs' => CommissionRun::withCount(['slips', 'agents'])
                ->when($this->search !== '', function ($query): void {
                    $term = '%' . trim($this->search) . '%';

                    $query->where(function ($query) use ($term): void {
                        $query->where('run_type', 'like', $term)
                            ->orWhere('status', 'like', $term)
                            ->orWhere('period_start', 'like', $term)
                            ->orWhere('period_end', 'like', $term);
                    });
                })
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
            'monthOptions' => $this->monthOptions(),
            'crmReady' => $crm->isConfigured(),
            'defaultAgentCount' => $runs->defaultAgentsFor($this->runType)->count(),
            'pickableAgents' => $this->showOpen && $this->agentMode === 'specific'
                ? $runs->selectableAgents()->filter(fn ($e) => $this->agentSearch === ''
                    || str_contains(
                        mb_strtolower($e->fullName() . ' ' . $e->employee_id . ' ' . $e->company_email),
                        mb_strtolower($this->agentSearch)
                    ))
                : collect(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Commission Runs</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Monthly or bi-weekly, over whatever period the commission covers. Nothing reaches an agent until someone sends it.
            </p>
        </div>

        <x-button wire:click="openForm" @click="$wire.showOpen = true" pill>
            <x-icon name="plus" class="h-4 w-4" /> Start a Commission Run
        </x-button>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    @unless ($crmReady)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/10">
            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">The CRM connection is not set up.</p>
            <p class="mt-1 text-sm font-medium text-amber-800 dark:text-amber-200/90">
                Computing a run reads every figure from the CRM, so it cannot run until
                <code class="font-mono text-xs">CRM_API_BASE_URL</code> and <code class="font-mono text-xs">CRM_HRIS_API_TOKEN</code>
                are filled in. Check it with <code class="font-mono text-xs">php artisan crm:check</code>.
            </p>
        </div>
    @endunless

    <x-card :padding="false" class="directory-panel"
        x-data="{ selected: [], runUrls: @js($runs->getCollection()->mapWithKeys(fn ($run) => [(string) $run->id => route('commissions.run-show', $run)])) }">
        <div class="directory-toolbar">
            <div>
                <h2 class="directory-title">Commission Run Directory</h2>
                <p x-cloak x-show="selected.length > 0"
                    class="mt-1 text-xs font-semibold text-amber-600 dark:text-amber-400"
                    x-text="selected.length + ' selected'"></p>
            </div>

            <div class="directory-toolbar-actions">
                <button type="button"
                    x-on:click="if (selected.length === 1) Livewire.navigate(runUrls[selected[0]])"
                    x-bind:disabled="selected.length !== 1"
                    x-bind:title="selected.length === 1 ? 'View selected commission run' : 'Select one commission run to view'"
                    x-bind:class="selected.length === 1 ? 'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-white' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900">
                    <x-icon name="eye" class="h-4 w-4" />
                </button>

                <label class="directory-search">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                    <input type="text" wire:model.live.debounce.300ms="search" @input="selected = []"
                        placeholder="Search commission runs..."
                        class="block h-10 w-full rounded-lg border border-ink-200 bg-white pl-9 pr-3.5 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-400 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                </label>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="directory-table">
                <thead class="directory-table-head">
                    <tr>
                        <th class="w-14 px-6 py-4 text-left">
                            <input type="checkbox" class="directory-checkbox"
                                x-bind:checked="selected.length === {{ $runs->count() }} && {{ $runs->count() }} > 0"
                                @click="selected = selected.length === {{ $runs->count() }} ? [] : @js($runs->getCollection()->pluck('id')->map(fn ($id) => (string) $id)->values())">
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Period</th>
                        <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Status</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Agents</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">USD Total</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Net (PHP)</th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Computed</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}"
                            @click="Livewire.navigate(runUrls['{{ $run->id }}'])"
                            class="directory-row cursor-pointer"
                            x-bind:class="selected.includes('{{ $run->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''">
                            <td class="px-6 py-4" @click.stop>
                                <input type="checkbox" value="{{ $run->id }}" x-model="selected" class="directory-checkbox">
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 font-bold text-ink-800 dark:text-white">
                                {{ $run->periodLabel() }}
                                <span class="mt-0.5 block text-xs font-medium text-ink-500 dark:text-ink-400">{{ $run->typeLabel() }} · {{ $run->dayCount() }} day(s)</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-4">
                                <x-badge :color="$run->statusColor()">{{ $run->statusLabel() }}</x-badge>
                                @if ($run->failed_count > 0)
                                    <span class="ml-1 text-xs font-semibold text-amber-600 dark:text-amber-400">{{ $run->failed_count }} failed</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium tabular-nums text-ink-600 dark:text-ink-300">{{ $run->slips_count ?: $run->agents_count }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium tabular-nums text-ink-600 dark:text-ink-300">&#36;{{ number_format((float) $run->total_usd, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-bold tabular-nums text-ink-950 dark:text-white">₱{{ number_format((float) $run->total_net, 2) }}</td>
                            <td class="whitespace-nowrap px-6 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $run->computed_at?->format('M j, Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="document" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No commission runs yet</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Start a run for the commission period you want to process.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($runs->hasPages())
            <div class="directory-pagination" @click="selected = []">
                {{ $runs->links('components.pagination', ['noun' => 'runs']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showOpen" onClose="$set('showOpen', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">Start a Commission Run</h2>
        <p class="mt-1 text-sm font-medium text-[#778599]">
            Opening a run does not read the CRM yet. You pick the agents and compute it on the next screen.
        </p>

        @if ($errorMessage)
            <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $errorMessage }}</div>
        @endif

        <div class="mt-5">
            <x-label>How often</x-label>
            <x-select wire:model.live="runType">
                <option value="monthly">Monthly — the whole calendar month</option>
                <option value="biweekly">Bi-weekly — twice a month</option>
                <option value="custom">Custom — any dates</option>
            </x-select>
            <p class="mt-1 text-xs font-medium text-[#778599]">
                Agents whose Commission Frequency matches are pre-selected. You can change who is included next.
            </p>
        </div>

        <div class="mt-4 {{ $runType === 'custom' ? 'hidden' : '' }}">
            <x-label>Month</x-label>
            <x-select wire:model.live="month">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
        </div>

        {{-- Always editable, whatever the type. The preset is a starting point;
             where a fortnight actually falls varies by agent. --}}
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <x-label>Period covers — from</x-label>
                <x-input wire:model="periodStart" type="date" />
                @error('periodStart') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <x-label>To</x-label>
                <x-input wire:model="periodEnd" type="date" />
                @error('periodEnd') <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>

        <p class="mt-2 text-xs font-medium text-[#778599]">
            These dates are what the CRM is asked for, so they should match the period the commission covers.
        </p>

        {{-- Who the run covers. All by default; narrowing to one agent is the
             common case when a single slip needs reissuing. --}}
        <div class="mt-4">
            <x-label>Agents</x-label>
            <x-select wire:model.live="agentMode">
                <option value="all">All agents ({{ $defaultAgentCount }})</option>
                <option value="specific">Choose specific agents</option>
            </x-select>
        </div>

        @if ($agentMode === 'specific')
            <div class="mt-3 rounded-xl border border-ink-200 p-3 dark:border-white/10">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative min-w-48 flex-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-ink-500">
                            <x-icon name="search" class="h-4 w-4" />
                        </span>
                        <input type="text" wire:model.live.debounce.250ms="agentSearch" placeholder="Search agents"
                               class="h-9 w-full rounded-lg border border-ink-200 bg-white py-2 pl-9 pr-3 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-500 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-900 dark:text-white">
                    </div>
                    <x-button wire:click="selectAllAgents" type="button" variant="secondary" class="h-9 px-2.5 text-xs">All</x-button>
                    <x-button wire:click="selectMatchingAgents" type="button" variant="secondary" class="h-9 px-2.5 text-xs">
                        {{ $runType === 'biweekly' ? 'Bi-weekly' : 'Monthly' }} only
                    </x-button>
                    <x-button wire:click="selectNoAgents" type="button" variant="secondary" class="h-9 px-2.5 text-xs">None</x-button>
                </div>

                <p class="mt-2 text-xs font-medium text-[#778599]">{{ count($selectedAgents) }} selected.</p>

                <div class="mt-2 max-h-56 divide-y divide-ink-100 overflow-y-auto rounded-lg border border-ink-200 dark:divide-white/10 dark:border-white/10">
                    @forelse ($pickableAgents as $agent)
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 transition hover:bg-ink-50 dark:hover:bg-white/5"
                               wire:key="pick-{{ $agent->id }}">
                            <input type="checkbox" wire:model.live="selectedAgents" value="{{ $agent->id }}"
                                   class="h-4 w-4 rounded border-ink-300 text-brand-600 focus:ring-brand-500 dark:border-white/20 dark:bg-ink-800">
                            <span class="flex-1">
                                <span class="block text-sm font-semibold text-ink-900 dark:text-white">
                                    {{ $agent->fullName() ?: $agent->employee_id }}
                                </span>
                                <span class="block text-xs font-medium text-[#778599]">{{ $agent->employee_id }}</span>
                            </span>
                            <x-badge :color="match ($agent->commission_frequency) {
                                'monthly' => 'blue',
                                'biweekly' => 'brand',
                                default => 'neutral',
                            }">
                                {{ match ($agent->commission_frequency) {
                                    'monthly' => 'Monthly',
                                    'biweekly' => 'Bi-weekly',
                                    default => 'None',
                                } }}
                            </x-badge>
                        </label>
                    @empty
                        <p class="px-3 py-6 text-center text-sm font-medium text-[#778599]">
                            {{ $agentSearch !== '' ? 'Nobody matches that.' : 'No employees on file.' }}
                        </p>
                    @endforelse
                </div>
            </div>
        @endif

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="openRun" wire:loading.attr="disabled" wire:target="openRun">
                <span wire:loading.remove wire:target="openRun">Open Run</span>
                <span wire:loading wire:target="openRun">Opening…</span>
            </x-button>
            <x-button wire:click="$set('showOpen', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
