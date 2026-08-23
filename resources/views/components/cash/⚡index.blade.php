<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\CashCategory;
use App\Models\CashEntry;
use App\Services\CashLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What came in and what went out.
 *
 * A record of movement, not a balance. There is no bank account on an entry
 * and no opening figure, because the question the company has is where the
 * money went last month — and answering "what is left" honestly would mean
 * reconciling every account, which is bookkeeping rather than a record.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    /** Which month is on screen, as YYYY-MM. */
    public string $month = '';

    public string $filter = 'all';

    public bool $showForm = false;

    /** Decides which entry gets overwritten on save, so the browser cannot move it. */
    #[Locked]
    public ?int $editingId = null;

    public ?string $statusMessage = null;

    public string $direction = CashEntry::OUT;
    public string $entryDate = '';
    public string $categoryId = '';
    public string $description = '';
    public string $amount = '';
    public string $reference = '';
    public string $note = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    /**
     * A named method rather than $set, so the buttons can point wire:loading at
     * it. Livewire cannot target a bare property assignment, which is why
     * clicking one used to sit there looking dead for the whole round trip.
     */
    public function showOnly(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'in', 'out'], true) ? $filter : 'all';

        $this->resetPage();
    }

    /** Categories flip with the side of the ledger, so a stale one is cleared. */
    public function updatedDirection(): void
    {
        $this->categoryId = '';
    }

    public function record(string $direction): void
    {
        $this->reset(['editingId', 'description', 'amount', 'reference', 'note', 'categoryId']);

        $this->direction = $direction === CashEntry::IN ? CashEntry::IN : CashEntry::OUT;

        // Defaults to today when today is in the month being viewed, otherwise
        // to the first of that month — so entering last month's receipts does
        // not mean correcting the date on every single one.
        $this->entryDate = $this->month === now()->format('Y-m')
            ? now()->toDateString()
            : $this->periodStart()->toDateString();

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $entry = CashEntry::findOrFail($id);

        $this->editingId = $entry->id;
        $this->direction = $entry->direction;
        $this->entryDate = $entry->entry_date->toDateString();
        $this->categoryId = (string) ($entry->cash_category_id ?? '');
        $this->description = $entry->description;
        $this->amount = (string) $entry->amount;
        $this->reference = (string) $entry->reference;
        $this->note = (string) $entry->note;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'entryDate' => ['required', 'date'],
            'direction' => ['required', 'in:in,out'],
            'categoryId' => ['nullable', 'exists:cash_categories,id'],
            'description' => ['required', 'string', 'max:180'],
            // Stored positive on both sides — the direction carries the sign.
            // Two ways of saying the same thing means both end up in the table.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999'],
            'reference' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], [
            'entryDate' => 'date',
            'categoryId' => 'category',
        ]);

        CashEntry::updateOrCreate(['id' => $this->editingId], [
            'entry_date' => $data['entryDate'],
            'direction' => $data['direction'],
            'cash_category_id' => $data['categoryId'] ?: null,
            'description' => $data['description'],
            'amount' => round((float) $data['amount'], 2),
            'reference' => $data['reference'] ?: null,
            'note' => $data['note'] ?: null,
            'recorded_by_user_id' => $this->editingId ? CashEntry::find($this->editingId)?->recorded_by_user_id : Auth::id(),
        ]);

        // Follow the entry to whichever month it landed in, otherwise dating
        // something to last month looks like nothing happened.
        $this->month = Carbon::parse($data['entryDate'])->format('Y-m');

        $this->showForm = false;
        $this->statusMessage = $this->editingId ? 'Entry updated.' : 'Entry recorded.';
        $this->reset(['editingId', 'description', 'amount', 'reference', 'note']);
    }

    public function delete(int $id): void
    {
        CashEntry::findOrFail($id)->delete();

        $this->statusMessage = 'Entry removed.';
    }

    protected function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
    }

    public function with(CashLedgerService $ledger): array
    {
        $start = $this->periodStart();
        $end = $start->copy()->endOfMonth();

        // Always offer this month, so an empty ledger still has somewhere to
        // put its first entry.
        $months = array_values(array_unique([...CashEntry::months(), now()->format('Y-m')]));
        rsort($months);

        $entries = CashEntry::with('category', 'recordedBy')
            ->between($start, $end)
            ->when($this->filter !== 'all', fn ($q) => $q->direction($this->filter))
            ->latestFirst()
            ->paginate($this->perPage());

        return [
            'monthLabel' => $start->format('F Y'),
            'months' => $months,
            'entries' => $entries,
            'totals' => $ledger->totals($start, $end),
            'toDate' => $ledger->totalsToDate(),
            'spending' => $ledger->byCategory($start, $end, CashEntry::OUT),
            'earning' => $ledger->byCategory($start, $end, CashEntry::IN),
            'categories' => CashCategory::active()->direction($this->direction)->ordered()->get(),
        ];
    }
};
?>

@php
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Money In &amp; Out</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                What the company received and what it paid out. A record of what moved, not a bank balance.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-select wire:model.live="month" wire:loading.attr="disabled" wire:target="month" class="w-40">
                @foreach ($months as $m)
                    <option value="{{ $m }}">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->format('F Y') }}</option>
                @endforeach
            </x-select>

            <x-button wire:click="record('in')" @click="$wire.showForm = true" pill>
                <x-icon name="plus" class="h-4 w-4" /> Money In
            </x-button>

            <x-button wire:click="record('out')" @click="$wire.showForm = true" variant="secondary" pill>
                <x-icon name="plus" class="h-4 w-4" /> Money Out
            </x-button>
        </div>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    {{-- The month at a glance. --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <x-card>
            <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">In &middot; {{ $monthLabel }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $peso($totals['in']) }}</p>
        </x-card>

        <x-card>
            <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">Out &middot; {{ $monthLabel }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-red-600 dark:text-red-400">{{ $peso($totals['out']) }}</p>
        </x-card>

        <x-card>
            <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">Net &middot; {{ $monthLabel }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums {{ $totals['net'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-[#0f172a] dark:text-white' }}">
                {{ $totals['net'] < 0 ? '−' . $peso(abs($totals['net'])) : $peso($totals['net']) }}
            </p>
            <p class="mt-1 text-xs font-medium text-[#778599]">
                {{ $totals['net'] < 0 ? 'Paid out more than came in' : 'Came in more than went out' }}
            </p>
        </x-card>
    </div>

    {{-- Where the money went. This is the "list of expenses" question. --}}
    @if ($spending->isNotEmpty() || $earning->isNotEmpty())
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ([['Where it went', $spending, 'red'], ['Where it came from', $earning, 'emerald']] as [$heading, $rows, $tone])
                @if ($rows->isNotEmpty())
                    <x-card>
                        <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">{{ $heading }}</h2>
                        <div class="mt-4 space-y-3">
                            @foreach ($rows as $row)
                                <div>
                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                        <span class="font-medium text-[#0f172a] dark:text-white">{{ $row->name }}</span>
                                        <span class="shrink-0 font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $peso($row->total) }}</span>
                                    </div>
                                    <div class="mt-1.5 flex items-center gap-2">
                                        <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                            <div class="h-full rounded-full bg-{{ $tone }}-500" style="width: {{ max($row->share, 1) }}%"></div>
                                        </div>
                                        <span class="w-11 shrink-0 text-right text-xs font-medium tabular-nums text-[#778599]">{{ $row->share }}%</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </x-card>
                @endif
            @endforeach
        </div>
    @endif

    <x-card :padding="false">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
            {{-- x-cloak on the group so the pressed state never flashes wrong
                 before Alpine takes over on first paint. --}}
            <div class="flex flex-wrap gap-2" x-data="{ pending: null }">
                @foreach (['all' => 'Everything', 'in' => 'Money in only', 'out' => 'Money out only'] as $key => $label)
                    {{-- Alpine paints the pressed state on mousedown, so the
                         button answers immediately instead of waiting out the
                         round trip. Livewire then confirms it on the way back. --}}
                    <x-button wire:click="showOnly('{{ $key }}')"
                              wire:loading.attr="disabled"
                              wire:target="showOnly"
                              @click="pending = '{{ $key }}'"
                              x-bind:class="pending === '{{ $key }}' || (pending === null && {{ $filter === $key ? 'true' : 'false' }})
                                  ? 'bg-brand-700 text-white shadow-sm shadow-brand-900/15 hover:bg-brand-800'
                                  : ''"
                              variant="secondary"
                              class="h-9 px-3 text-xs">{{ $label }}</x-button>
                @endforeach

                <span wire:loading wire:target="showOnly" class="flex items-center text-xs font-medium text-[#778599]">
                    <span class="mr-1.5 h-3 w-3 animate-spin rounded-full border-2 border-brand-200 border-t-brand-700"></span>
                    Filtering…
                </span>
            </div>

            @if ($totals['count'] > 0)
                <a href="{{ route('cash.export', ['month' => $month]) }}"
                   class="text-sm font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-400">
                    Download {{ $monthLabel }} as CSV
                </a>
            @endif
        </div>

        {{-- Fades while the new rows are on their way, so the table visibly
             belongs to the button that was just pressed rather than sitting
             there showing the previous answer. --}}
        <div class="overflow-x-auto transition-opacity duration-150"
             wire:loading.class="opacity-40"
             wire:target="showOnly, month, gotoPage, nextPage, previousPage">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Date</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Description</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Category</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">In</th>
                        <th class="px-4 py-4 text-right text-xs font-medium uppercase tracking-wide text-[#778599]">Out</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($entries as $entry)
                        <tr wire:key="cash-{{ $entry->id }}">
                            <td class="whitespace-nowrap px-4 py-3 font-medium tabular-nums text-[#0f172a] dark:text-white">
                                {{ $entry->entry_date->format('M j') }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-[#0f172a] dark:text-white">{{ $entry->description }}</p>
                                @if ($entry->reference || $entry->note)
                                    <p class="mt-0.5 text-xs font-medium text-[#778599]">
                                        {{ collect([$entry->reference, $entry->note])->filter()->join(' · ') }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($entry->category)
                                    <x-badge :color="$entry->isIn() ? 'green' : 'neutral'">{{ $entry->category->name }}</x-badge>
                                @else
                                    <span class="text-xs font-medium text-[#778599]">Not categorised</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                                {{ $entry->isIn() ? $peso($entry->amount) : '—' }}
                            </td>
                            <td class="px-4 py-3 text-right font-bold tabular-nums text-red-600 dark:text-red-400">
                                {{ $entry->isIn() ? '—' : $peso($entry->amount) }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-button wire:click="edit({{ $entry->id }})" @click="$wire.showForm = true"
                                              variant="secondary" class="h-9 px-3 text-xs">Edit</x-button>
                                    <x-button wire:click="delete({{ $entry->id }})"
                                              wire:confirm="Remove this entry? It comes straight out of the totals."
                                              variant="secondary" class="h-9 px-3 text-xs">Delete</x-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center">
                            <p class="font-medium text-[#778599]">Nothing recorded for {{ $monthLabel }}.</p>
                            <p class="mt-1 text-sm text-[#778599]">
                                Use <span class="font-semibold">Money In</span> or
                                <span class="font-semibold">Money Out</span> above to add the first entry.
                            </p>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $entries->links('components.pagination', ['noun' => 'entries']) }}
            </div>
        @endif
    </x-card>

    @if ($toDate['count'] > 0)
        <x-card>
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">Since you started recording</p>
                    <p class="mt-1 text-sm font-medium text-[#778599]">
                        {{ number_format($toDate['count']) }} entries &middot;
                        {{ $peso($toDate['in']) }} in &middot; {{ $peso($toDate['out']) }} out
                    </p>
                </div>
                <p class="text-2xl font-bold tabular-nums {{ $toDate['net'] < 0 ? 'text-red-600 dark:text-red-400' : 'text-[#0f172a] dark:text-white' }}">
                    {{ $toDate['net'] < 0 ? '−' . $peso(abs($toDate['net'])) : $peso($toDate['net']) }}
                </p>
            </div>
        </x-card>
    @endif

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
            {{ $editingId ? 'Edit Entry' : ($direction === 'in' ? 'Record Money In' : 'Record Money Out') }}
        </h2>

        <div class="mt-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Date</x-label>
                    <x-input wire:model="entryDate" type="date" />
                    @error('entryDate') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>In or out</x-label>
                    <x-select wire:model.live="direction">
                        <option value="in">Money in — received</option>
                        <option value="out">Money out — paid</option>
                    </x-select>
                </div>
            </div>

            <div>
                <x-label>What was it for</x-label>
                <x-input wire:model="description" type="text"
                         placeholder="{{ $direction === 'in' ? 'e.g. August billing — Ink House' : 'e.g. Office rent for August' }}" />
                @error('description') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Amount</x-label>
                    <x-input wire:model="amount" type="number" step="0.01" min="0.01" placeholder="0.00" />
                    <p class="mt-1 text-xs font-medium text-[#778599]">Always a positive figure. The side above decides the sign.</p>
                    @error('amount') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Category</x-label>
                    <x-select wire:model="categoryId">
                        <option value="">Not categorised</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-select>
                    @error('categoryId') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Reference <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="reference" type="text" placeholder="OR or invoice number" />
                    @error('reference') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                    <x-input wire:model="note" type="text" placeholder="Anything worth remembering" />
                    @error('note') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="save">{{ $editingId ? 'Save Changes' : 'Record Entry' }}</x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
