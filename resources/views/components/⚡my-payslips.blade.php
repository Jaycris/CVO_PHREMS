<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    #[Locked]
    public ?int $openId = null;

    public string $year = '';

    public function mount(?Payslip $payslip = null): void
    {
        if ($payslip?->exists) {
            // Guarded here rather than in the route: the check is "is this
            // yours", which only the payslip itself can answer.
            $this->authorizeOwn($payslip);
            $this->openId = $payslip->id;
        }
    }

    /**
     * A payslip belongs to its employee, and only once HR has released it.
     *
     * The gate is notified_at rather than the run's status: finalizing locks
     * the figures, but it is HR pressing Send that decides the employee may
     * see them. Those are separate moments on purpose — HR finalizes, checks,
     * and releases when ready.
     */
    protected function authorizeOwn(Payslip $payslip): void
    {
        $employee = Auth::user()?->employee;

        abort_unless($employee && $payslip->employee_id === $employee->id, 403, 'That payslip is not yours.');
        abort_unless($payslip->notified_at !== null, 403, 'That payslip has not been released yet.');
    }

    public function open(int $id): void
    {
        $this->authorizeOwn(Payslip::with('payrollRun')->findOrFail($id));
        $this->openId = $id;
    }

    public function closePayslip(): void
    {
        $this->openId = null;
    }


    public function with(): array
    {
        $employee = Auth::user()?->employee;

        /*
         * Ordering used to happen in PHP after loading every payslip, which
         * paging cannot do — page 2 would be sorted among itself. The pay date
         * lives on the run, so it is pulled in as a sub-select instead.
         */
        $released = fn () => Payslip::where('employee_id', $employee->id)
            // Nothing appears until HR releases it. A payslip seen before then
            // could still be a figure HR was about to correct.
            ->whereNotNull('notified_at');

        $payslips = $employee
            ? $released()->with('payrollRun')
                ->orderByDesc(
                    \App\Models\PayrollRun::select('pay_date')
                        ->whereColumn('payroll_runs.id', 'payslips.payroll_run_id')
                )
                ->paginate($this->perPage())
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->perPage());

        // Looked up on its own rather than from the page in view, so opening a
        // payslip and then paging away does not close it.
        $open = $employee && $this->openId
            ? $released()->with('payrollRun')->find($this->openId)
            : null;

        $lines = $open?->lines()->orderBy('sort_order')->get() ?? collect();
        $earnings = $lines
            ->where('section', 'earning')
            ->filter(fn ($line) => (float) $line->amount >= 0)
            ->values();
        $legacyDeductions = $lines
            ->where('section', 'earning')
            ->filter(fn ($line) => (float) $line->amount < 0)
            ->map(function ($line) {
                $line = clone $line;
                $line->amount = abs((float) $line->amount);

                return $line;
            });
        $deductions = $legacyDeductions
            ->concat($lines->where('section', 'deduction'))
            ->values();

        return [
            'payslips' => $payslips,
            'open' => $open,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'displayGrossPay' => round((float) $earnings->sum('amount'), 2),
            'displayTotalDeductions' => round((float) $deductions->sum('amount'), 2),
            'hasEmployee' => $employee !== null,
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Payslips</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Check each one and tell HR within three working days if something looks wrong.</p>
    </div>

    @unless ($hasEmployee)
        <x-card>
            <p class="text-sm font-medium text-[#778599]">Your account is not linked to an employee record yet. Ask HR to sort it out.</p>
        </x-card>
    @else
        <x-card
            :padding="false"
            class="directory-panel"
            x-data="{ selected: [] }"
        >
            <div
                wire:loading.flex
                wire:target="open"
                class="absolute inset-x-0 top-0 z-10 h-1 overflow-hidden bg-brand-50 dark:bg-brand-950/40"
            >
                <div class="h-full w-1/3 animate-[payslip-loader_0.9s_ease-in-out_infinite] rounded-r-full bg-brand-700 dark:bg-brand-300"></div>
            </div>

            <div class="directory-toolbar">
                <div>
                    <h2 class="directory-title">My Payslips Directory</h2>
                </div>

                <div class="directory-toolbar-actions">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            x-on:click="if (selected.length === 1) $wire.open(selected[0])"
                            x-bind:disabled="selected.length !== 1"
                            x-bind:title="selected.length === 1 ? 'View selected payslip' : 'Select one payslip to view'"
                            x-bind:class="selected.length === 1 ? 'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-white' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                        >
                            <x-icon name="eye" class="h-4 w-4" />
                        </button>
                        <a
                            x-bind:href="selected.length === 1 ? @js(url('/my-payslips')) + '/' + selected[0] + '/download' : '#'"
                            x-bind:title="selected.length === 1 ? 'Download selected payslip PDF' : 'Select one payslip to download'"
                            x-bind:class="selected.length === 1 ? 'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-white' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                        >
                            <x-icon name="download" class="h-4 w-4" />
                        </a>

                    </div>


                    <label class="directory-search">
                        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-400" />
                        <input
                            type="text"
                            placeholder="Search payslips..."
                            disabled
                            class="block h-10 w-full rounded-lg border border-ink-200 bg-white pl-9 pr-3.5 text-sm font-medium text-ink-700 shadow-sm placeholder:text-ink-400 disabled:opacity-100 dark:border-white/10 dark:bg-ink-900 dark:text-white"
                        >
                    </label>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="directory-table">
                    <thead class="directory-table-head">
                        <tr>
                            <th class="w-14 px-6 py-4 text-left">
                                <input
                                    type="checkbox"
                                    class="directory-checkbox"
                                    x-bind:checked="selected.length === {{ $payslips->count() }} && {{ $payslips->count() }} > 0"
                                    @click="selected = (selected.length === {{ $payslips->count() }}) ? [] : [{{ $payslips->getCollection()->pluck('id')->implode(',') }}].map(String)"
                                >
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Period</th>
                            <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Paid On</th>
                            <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Gross</th>
                            <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Deductions</th>
                            <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Net Pay</th>
                        </tr>
                    </thead>
                    <tbody class="directory-table-body">
                        @forelse ($payslips as $payslip)
                            <tr
                                wire:key="mine-{{ $payslip->id }}"
                                wire:click="open({{ $payslip->id }})"
                                wire:loading.class="opacity-70"
                                wire:target="open({{ $payslip->id }})"
                                class="directory-row cursor-pointer"
                                x-bind:class="selected.includes('{{ $payslip->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''"
                            >
                                <td class="px-6 py-4" onclick="event.stopPropagation()">
                                    <input
                                        type="checkbox"
                                        value="{{ $payslip->id }}"
                                        x-model="selected"
                                        class="directory-checkbox"
                                    >
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 font-bold text-ink-800 dark:text-white">{{ $payslip->payrollRun->periodLabel() }}</td>
                                <td class="whitespace-nowrap px-4 py-4 font-medium text-ink-600 dark:text-ink-300">{{ $payslip->payrollRun->pay_date->format('M j, Y') }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-ink-600 tabular-nums dark:text-ink-300">₱{{ number_format((float) $payslip->gross_pay, 2) }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-ink-600 tabular-nums dark:text-ink-300">₱{{ number_format((float) $payslip->total_deductions, 2) }}</td>
                                <td class="whitespace-nowrap px-4 py-4 text-right font-bold text-ink-950 tabular-nums dark:text-white">₱{{ number_format((float) $payslip->net_pay, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-16 text-center">
                                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                        <x-icon name="document" class="h-7 w-7" />
                                    </div>
                                    <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No payslips yet</p>
                                    <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Released payslips will appear here after HR sends them.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($payslips->hasPages())
                <div class="directory-pagination" @click="selected = []">
                    {{ $payslips->links('components.pagination', ['noun' => 'payslips']) }}
                </div>
            @endif
        </x-card>
    @endunless

    <x-modal :show="(bool) $open" onClose="closePayslip" maxWidth="2xl">
        @if ($open)
            <div
                x-data="{ ready: false }"
                x-init="requestAnimationFrame(() => ready = true)"
                x-show="ready"
                x-transition:enter="ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-3 scale-[0.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            >
                <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">{{ $open->payrollRun->periodLabel() }}</h2>
                        <p class="text-sm font-medium text-[#778599]">Paid {{ $open->payrollRun->pay_date->format('F j, Y') }}</p>
                    </div>
                    <x-badge :color="$open->payrollRun->status === 'paid' ? 'green' : 'brand'">
                        {{ $open->payrollRun->status === 'paid' ? 'Paid' : 'Ready' }}
                    </x-badge>
                </div>

                <div class="mb-5 grid grid-cols-2 gap-3 rounded-lg bg-[#f8fafc] p-3 text-sm sm:grid-cols-4 dark:bg-neutral-800/50">
                    @foreach ([
                        'Days worked' => $open->days_present . ' / ' . $open->days_expected,
                        'Absent' => $open->days_absent,
                        'Late' => $open->late_minutes . ' min',
                        'Overtime' => rtrim(rtrim(number_format((float) $open->overtime_hours, 2), '0'), '.') . ' h',
                    ] as $label => $value)
                        <div>
                            <p class="text-xs font-medium text-[#778599]">{{ $label }}</p>
                            <p class="mt-0.5 font-bold text-[#0f172a] dark:text-white tabular-nums">{{ $value }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Earnings</p>
                        <div class="mt-2 divide-y divide-neutral-100 dark:divide-neutral-800">
                            @foreach ($earnings as $line)
                                <div class="flex items-baseline justify-between gap-4 py-2">
                                    <div>
                                        <span class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</span>
                                        @if ($line->detail)<span class="ml-1 text-xs font-medium text-[#778599]">{{ $line->detail }}</span>@endif
                                    </div>
                                    <span class="text-sm font-medium text-[#0f172a] tabular-nums dark:text-white">
                                        ₱{{ number_format((float) $line->amount, 2) }}
                                    </span>
                                </div>
                            @endforeach
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <span class="text-sm font-bold text-[#0f172a] dark:text-white">Gross pay</span>
                                <span class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $displayGrossPay, 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Deductions</p>
                        <div class="mt-2 divide-y divide-neutral-100 dark:divide-neutral-800">
                            @forelse ($deductions as $line)
                                <div class="flex items-baseline justify-between gap-4 py-2">
                                    <div>
                                        <span class="text-sm font-medium text-[#65758c] dark:text-neutral-300">{{ $line->label }}</span>
                                        @if ($line->detail)<span class="ml-1 text-xs font-medium text-[#778599]">{{ $line->detail }}</span>@endif
                                    </div>
                                    <span class="text-sm font-medium text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $line->amount, 2) }}</span>
                                </div>
                            @empty
                                <p class="py-2 text-sm font-medium text-[#778599]">Nothing deducted.</p>
                            @endforelse
                            <div class="flex items-baseline justify-between gap-4 py-2">
                                <span class="text-sm font-bold text-[#0f172a] dark:text-white">Total deductions</span>
                                <span class="text-sm font-bold text-[#0f172a] dark:text-white tabular-nums">₱{{ number_format((float) $displayTotalDeductions, 2) }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 rounded-lg bg-brand-50 px-4 py-3 dark:bg-brand-900/20">
                        <span class="text-base font-bold text-[#0f172a] dark:text-white">Net Pay</span>
                        <span class="text-2xl font-bold text-brand-700 dark:text-brand-300 tabular-nums">₱{{ number_format((float) $open->net_pay, 2) }}</span>
                    </div>
                </div>

                <div class="mt-5 flex gap-2">
                    <x-button variant="secondary" x-on:click="$dispatch('close-modal-visual'); setTimeout(() => $wire.closePayslip(), 120)">Close</x-button>
                </div>
            </div>
        @endif
    </x-modal>
</div>
