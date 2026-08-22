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
        $this->resetValidation();
        $this->errorMessage = null;
        $this->showOpen = true;
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
            $run = $service->openRun($this->periodStart, $this->periodEnd, $this->runType, Auth::user());
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

    public function with(CommissionSlipService $crm): array
    {
        return [
            'runs' => CommissionRun::withCount(['slips', 'agents'])
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->paginate($this->perPage()),
            'monthOptions' => $this->monthOptions(),
            'crmReady' => $crm->isConfigured(),
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

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Period</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Status</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Agents</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">USD Total</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Net (PHP)</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Computed</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                            <td class="px-4 py-3 font-bold text-[#0f172a] dark:text-white">
                                {{ $run->periodLabel() }}
                                <span class="block text-xs font-medium text-[#778599]">{{ $run->typeLabel() }} · {{ $run->dayCount() }} day(s)</span>
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :color="$run->statusColor()">{{ $run->statusLabel() }}</x-badge>
                                @if ($run->failed_count > 0)
                                    <span class="ml-1 text-xs font-semibold text-amber-600 dark:text-amber-400">{{ $run->failed_count }} failed</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">{{ $run->slips_count ?: $run->agents_count }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-[#778599]">${{ number_format((float) $run->total_usd, 2) }}</td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums text-[#0f172a] dark:text-white">₱{{ number_format((float) $run->total_net, 2) }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599]">
                                {{ $run->computed_at?->format('M j, Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('commissions.run-show', $run) }}" wire:navigate class="font-medium text-brand-700 hover:text-brand-800 dark:text-brand-400">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center font-medium text-[#778599]">
                            No commission runs yet. Start one for the month you want to pay.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($runs->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
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

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="openRun" wire:loading.attr="disabled" wire:target="openRun">
                <span wire:loading.remove wire:target="openRun">Open Run</span>
                <span wire:loading wire:target="openRun">Opening…</span>
            </x-button>
            <x-button wire:click="$set('showOpen', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
