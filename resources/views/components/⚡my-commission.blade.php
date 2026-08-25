<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CommissionSlip;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * An agent's own commission slips.
 *
 * Reads the slips this app wrote down when the run was computed — not the CRM.
 * That is the point of the run: the figure an agent is shown is the figure that
 * was locked and sent, and it does not move afterwards because someone edited a
 * sale in the CRM.
 *
 * Nothing appears until HR presses Send, exactly as with a payslip.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    /**
     * Which slip is open. Locked, because it decides which figures are shown
     * and the browser must not be able to name a different one.
     */
    #[Locked]
    public ?int $openId = null;

    /**
     * Whether the panel is showing. Deliberately a separate, unlocked flag:
     * the click opens the panel straight away in the browser, and only the
     * server decides which slip goes in it.
     */
    public bool $showSlip = false;

    public function mount(?CommissionSlip $slip = null): void
    {
        abort_unless(Auth::user()->employee, 403, 'No employee profile is linked to your account.');

        if ($slip?->exists) {
            $this->authorizeOwn($slip);
            $this->openId = $slip->id;
            $this->showSlip = true;
        }
    }

    /**
     * A slip belongs to its agent, and only once it has been sent.
     *
     * The gate is notified_at rather than the run's status: finalizing locks
     * the figures, but it is HR pressing Send that decides the agent may see
     * them.
     */
    protected function authorizeOwn(CommissionSlip $slip): void
    {
        $employee = Auth::user()?->employee;

        abort_unless($employee && $slip->employee_id === $employee->id, 403, 'That commission slip is not yours.');
        abort_unless($slip->notified_at !== null, 403, 'That commission slip has not been sent yet.');
    }

    public function open(int $id): void
    {
        $this->authorizeOwn(CommissionSlip::findOrFail($id));
        $this->openId = $id;
        $this->showSlip = true;
    }

    public function closeSlip(): void
    {
        $this->openId = null;
        $this->showSlip = false;
    }

    public function with(): array
    {
        $employee = Auth::user()->employee;

        $mine = fn () => CommissionSlip::where('employee_id', $employee->id)->released();

        return [
            'slips' => $mine()->with('commissionRun')
                ->join('commission_runs', 'commission_runs.id', '=', 'commission_slips.commission_run_id')
                ->orderByDesc('commission_runs.period_start')
                ->orderByDesc('commission_runs.id')
                ->select('commission_slips.*')
                ->paginate($this->perPage()),
            // Looked up on its own rather than from the page in view, so paging
            // away does not close it.
            'open' => $this->openId
                ? $mine()->with(['lines', 'commissionRun', 'employee'])->find($this->openId)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">My Commission</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
            Worked out in the CRM. Check each slip and tell your team lead within three working days if something looks wrong.
        </p>
    </div>

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
                <h2 class="directory-title">My Commission Directory</h2>
            </div>

            <div class="directory-toolbar-actions">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="if (selected.length === 1) { $dispatch('open-phrems-modal', 'showSlip'); $wire.open(selected[0]); }"
                        x-bind:disabled="selected.length !== 1"
                        x-bind:title="selected.length === 1 ? 'View selected commission slip' : 'Select one commission slip to view'"
                        x-bind:class="selected.length === 1 ? 'text-ink-500 hover:bg-ink-100 hover:text-ink-900 dark:text-ink-400 dark:hover:bg-white/10 dark:hover:text-white' : 'pointer-events-none text-ink-400 opacity-40 dark:text-ink-500'"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-ink-200 bg-white shadow-sm transition dark:border-white/10 dark:bg-ink-900"
                    >
                        <x-icon name="eye" class="h-4 w-4" />
                    </button>
                    <a
                        x-bind:href="selected.length === 1 ? @js(url('/my-commission')) + '/' + selected[0] + '/download' : '#'"
                        x-bind:title="selected.length === 1 ? 'Download selected commission slip PDF' : 'Select one commission slip to download'"
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
                        placeholder="Search commission slips..."
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
                                x-bind:checked="selected.length === {{ $slips->count() }} && {{ $slips->count() }} > 0"
                                @click="selected = (selected.length === {{ $slips->count() }}) ? [] : [{{ $slips->getCollection()->pluck('id')->implode(',') }}].map(String)"
                            >
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Month</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">MTD</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">USD Total</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">PHP Total</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Card Hold</th>
                        <th class="px-4 py-4 text-right text-xs font-bold uppercase tracking-wide text-ink-600 dark:text-ink-300">Net Commission</th>
                    </tr>
                </thead>
                <tbody class="directory-table-body">
                    @php($money = fn ($v, $p = '') => $v === null ? '—' : $p . number_format((float) $v, 2))
                    @forelse ($slips as $slip)
                        <tr
                            wire:key="mine-{{ $slip->id }}"
                            wire:click="open({{ $slip->id }})"
                            @click="$dispatch('open-phrems-modal', 'showSlip')"
                            wire:loading.class="opacity-70"
                            wire:target="open({{ $slip->id }})"
                            class="directory-row cursor-pointer"
                            x-bind:class="selected.includes('{{ $slip->id }}') ? 'bg-brand-50/40 dark:bg-brand-900/10' : ''"
                        >
                            <td class="px-6 py-4" onclick="event.stopPropagation()">
                                <input
                                    type="checkbox"
                                    value="{{ $slip->id }}"
                                    x-model="selected"
                                    class="directory-checkbox"
                                >
                            </td>
                            <td class="whitespace-nowrap px-6 py-4 font-bold text-ink-800 dark:text-white">{{ $slip->monthLabel() }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-ink-600 tabular-nums dark:text-ink-300">{{ $money($slip->mtd, '$') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-ink-600 tabular-nums dark:text-ink-300">{{ $money($slip->usd_total, '$') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-ink-600 tabular-nums dark:text-ink-300">{{ $money($slip->php_total, '₱') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-medium text-ink-600 tabular-nums dark:text-ink-300">{{ $money($slip->card_hold_amount, '₱') }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-right font-bold text-ink-950 tabular-nums dark:text-white">{{ $money($slip->net_commission, '₱') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-icon name="chart" class="h-7 w-7" />
                                </div>
                                <p class="mt-4 text-base font-bold text-ink-950 dark:text-white">No commission slips yet</p>
                                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">Released commission slips will appear here after HR sends them.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($slips->hasPages())
            <div class="directory-pagination" @click="selected = []">
                {{ $slips->links('components.pagination', ['noun' => 'slips']) }}
            </div>
        @endif
    </x-card>

    <x-modal wire="showSlip" onClose="closeSlip" maxWidth="4xl">
        {{-- The panel is on screen before the slip arrives, so it says so
             rather than showing an empty box for a moment. --}}
        @unless ($open)
            <p class="py-8 text-center text-sm font-medium text-[#778599]">Loading…</p>
        @else
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">Commission Slip</p>
                    <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">{{ $open->monthLabel() }}</h2>
                    <p class="text-sm font-medium text-[#778599]">Sent {{ $open->notified_at?->format('F j, Y') }}</p>
                </div>
                <x-button as="a" href="{{ route('my-commission.download', ['slip' => $open->id]) }}" variant="secondary" class="h-9 px-3 text-xs">
                    <x-icon name="document" class="h-4 w-4" /> Download PDF
                </x-button>
            </div>

            <x-commission-slip-detail :slip="$open" />
        @endunless
    </x-modal>
</div>
