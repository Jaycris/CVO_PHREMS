<?php

use App\Livewire\Concerns\WithTablePagination;
use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The yearly holiday list payroll reads.
 *
 * Without a row here, a holiday is just a scheduled workday nobody clocked in
 * for — which payroll counts as an absence and deducts a day's pay for. Adding
 * the date is the whole fix.
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithTablePagination;

    public int $year;

    public bool $showForm = false;
    public ?int $editingId = null;
    public ?string $statusMessage = null;

    public string $whose = 'all';

    public string $date = '';
    public string $name = '';
    public string $type = Holiday::REGULAR;
    public string $observance = Holiday::PHILIPPINES;
    public string $note = '';

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function updatedYear(): void
    {
        $this->resetPage();
    }

    public function showOnly(string $whose): void
    {
        $this->whose = array_key_exists($whose, Holiday::OBSERVANCES) ? $whose : 'all';

        $this->resetPage();
    }

    /**
     * Only Philippine holidays carry Labor Code categories, so switching whose
     * holiday it is has to drop a type that no longer applies — otherwise the
     * Fourth of July gets saved as a Regular Holiday and somebody later expects
     * the 200% premium that goes with one.
     */
    public function updatedObservance(): void
    {
        $allowed = array_keys(Holiday::typesFor($this->observance));

        if (! in_array($this->type, $allowed, true)) {
            $this->type = $allowed[0];
        }
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'note']);
        $this->observance = $this->whose === 'all' ? Holiday::PHILIPPINES : $this->whose;
        $this->type = array_key_first(Holiday::typesFor($this->observance));

        // Starts on the year being viewed rather than today, so adding next
        // year's proclamation does not mean retyping the year sixteen times.
        $this->date = $this->year === (int) now()->year
            ? now()->toDateString()
            : $this->year . '-01-01';

        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $holiday = Holiday::findOrFail($id);

        $this->editingId = $holiday->id;
        $this->date = $holiday->date->toDateString();
        $this->name = $holiday->name;
        $this->type = $holiday->type;
        $this->observance = $holiday->observance;
        $this->note = (string) $holiday->note;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'date' => ['required', 'date'],
            'name' => ['required', 'string', 'max:120'],
            'observance' => ['required', 'in:' . implode(',', array_keys(Holiday::OBSERVANCES))],
            // Scoped to the observance, not the whole list — a US holiday must
            // not be storable as a Philippine Labor Code category.
            'type' => ['required', 'in:' . implode(',', array_keys(Holiday::typesFor($this->observance)))],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['date' => 'date', 'name' => 'holiday name', 'observance' => 'whose holiday']);

        $clash = Holiday::where('date', $data['date'])
            ->where('name', $data['name'])
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($clash) {
            $this->addError('name', 'That holiday is already on the list for this date.');

            return;
        }

        Holiday::updateOrCreate(['id' => $this->editingId], $data);

        // Jump the list to wherever it was just saved, otherwise adding next
        // year's Christmas looks like nothing happened.
        $this->year = (int) Carbon::parse($data['date'])->year;

        $this->showForm = false;
        $this->statusMessage = $this->editingId ? 'Holiday updated.' : 'Holiday added.';
        $this->reset(['editingId', 'name', 'note']);
    }

    public function delete(int $id): void
    {
        Holiday::findOrFail($id)->delete();

        $this->statusMessage = 'Holiday removed. Payroll runs already finalized keep the figures they were computed with.';
    }

    public function with(): array
    {
        $years = Holiday::years();

        // Always offer this year and next, so the list can be started from
        // empty and next year's proclamation has somewhere to go.
        $years = array_values(array_unique([
            ...$years,
            (int) now()->year,
            (int) now()->year + 1,
        ]));
        rsort($years);

        return [
            'years' => $years,
            'holidays' => Holiday::forYear($this->year)
                ->when($this->whose !== 'all', fn ($q) => $q->observance($this->whose))
                ->ordered()
                ->paginate($this->perPage()),
            // Counted per calendar rather than per type. "How many days off do
            // we give?" is the question people actually open this page with.
            'counts' => Holiday::forYear($this->year)
                ->selectRaw('observance, count(*) as total')
                ->groupBy('observance')
                ->pluck('total', 'observance'),
            'workingDays' => Holiday::forYear($this->year)
                ->where('type', Holiday::SPECIAL_WORKING)
                ->count(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Holidays</h1>
            <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">
                Philippine and US holidays the company observes. Payroll reads this list — a day that is not
                here is treated as an ordinary workday, and staying home on it is counted as an absence.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <x-select wire:model.live="year" class="w-32">
                @foreach ($years as $y)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endforeach
            </x-select>

            <x-button wire:click="create" @click="$wire.showForm = true" pill>
                <x-icon name="plus" class="h-4 w-4" /> Add Holiday
            </x-button>
        </div>
    </div>

    @if ($statusMessage)
        <div class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300">{{ $statusMessage }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach (\App\Models\Holiday::OBSERVANCES as $key => $label)
            <x-card>
                <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">{{ $label }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $counts[$key] ?? 0 }}</p>
                <p class="mt-1 text-xs font-medium text-[#778599]">
                    {{ match ($key) {
                        'philippines' => 'Set by proclamation each year.',
                        'united_states' => 'US days the company follows.',
                        default => 'Days the company gives on its own.',
                    } }}
                </p>
            </x-card>
        @endforeach

        <x-card>
            <p class="text-xs font-medium uppercase tracking-wide text-[#778599]">Working days</p>
            <p class="mt-1 text-2xl font-bold tabular-nums text-[#0f172a] dark:text-white">{{ $workingDays }}</p>
            <p class="mt-1 text-xs font-medium text-[#778599]">On the list, but everyone is still expected in.</p>
        </x-card>
    </div>

    <x-card :padding="false">
        <div class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800"
             x-data="{ pending: null }">
            @foreach (['all' => 'All calendars'] + \App\Models\Holiday::OBSERVANCES as $key => $label)
                <x-button wire:click="showOnly('{{ $key }}')"
                          wire:loading.attr="disabled"
                          wire:target="showOnly"
                          @click="pending = '{{ $key }}'"
                          x-bind:class="pending === '{{ $key }}' || (pending === null && {{ $whose === $key ? 'true' : 'false' }})
                              ? 'bg-brand-700 text-white shadow-sm shadow-brand-900/15 hover:bg-brand-800'
                              : ''"
                          variant="secondary"
                          class="h-9 px-3 text-xs">{{ $label }}</x-button>
            @endforeach
        </div>

        <div class="overflow-x-auto transition-opacity duration-150"
             wire:loading.class="opacity-40"
             wire:target="showOnly, year, gotoPage, nextPage, previousPage">
            <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-800">
                <thead class="bg-[#f8fafc] dark:bg-neutral-800/50">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Date</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Day</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Holiday</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Whose</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Type</th>
                        <th class="px-4 py-4 text-left text-xs font-medium uppercase tracking-wide text-[#778599]">Note</th>
                        <th class="px-4 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    @forelse ($holidays as $holiday)
                        <tr wire:key="hol-{{ $holiday->id }}">
                            <td class="px-4 py-3 font-bold tabular-nums text-[#0f172a] dark:text-white">
                                {{ $holiday->date->format('M j, Y') }}
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $holiday->date->format('l') }}</td>
                            <td class="px-4 py-3 font-medium text-[#0f172a] dark:text-white">{{ $holiday->name }}</td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $holiday->observanceLabel() }}</td>
                            <td class="px-4 py-3">
                                {{-- Green for anything that protects the day's
                                     pay, grey for the one that does not. --}}
                                <x-badge :color="match ($holiday->type) {
                                    'regular' => 'green',
                                    'special_non_working' => 'blue',
                                    'paid_day_off' => 'green',
                                    default => 'neutral',
                                }">{{ $holiday->typeLabel() }}</x-badge>
                            </td>
                            <td class="px-4 py-3 font-medium text-[#778599]">{{ $holiday->note ?: '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <x-button wire:click="edit({{ $holiday->id }})" @click="$wire.showForm = true"
                                              variant="secondary" class="h-9 px-3 text-xs">Edit</x-button>
                                    <x-button wire:click="delete({{ $holiday->id }})"
                                              wire:confirm="Remove {{ $holiday->name }} from the list? Payroll will treat that date as an ordinary workday."
                                              variant="secondary" class="h-9 px-3 text-xs">Delete</x-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center font-medium text-[#778599]">
                            No {{ $whose === 'all' ? '' : \App\Models\Holiday::OBSERVANCES[$whose] . ' ' }}holidays on file for {{ $year }} yet.
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($holidays->hasPages())
            <div class="border-t border-neutral-200 px-5 py-4 dark:border-neutral-800">
                {{ $holidays->links('components.pagination', ['noun' => 'holidays']) }}
            </div>
        @endif
    </x-card>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card>
            <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">US holidays are yours to choose</h2>
            <p class="mt-2 text-sm font-medium text-[#778599]">
                Nothing American is loaded for you, because no two companies follow the same set. Add the ones
                this company actually observes — Thanksgiving, the Fourth of July, whatever your clients close
                for — and leave the rest off. A day that is not on this list is an ordinary workday.
            </p>
            <p class="mt-2 text-sm font-medium text-[#778599]">
                When a US holiday falls on a weekend it is usually observed on the nearest weekday. Enter the
                date your staff actually take off, not the calendar date.
            </p>
        </x-card>

        <x-card>
            <h2 class="text-sm font-bold text-[#0f172a] dark:text-white">Two Philippine holidays move every year</h2>
            <p class="mt-2 text-sm font-medium text-[#778599]">
                <span class="font-bold text-[#0f172a] dark:text-white">Eid'l Fitr and Eid'l Adha</span> follow the lunar
                calendar and are announced only a few days ahead, and the
                <span class="font-bold text-[#0f172a] dark:text-white">extra special days</span> the President adds each
                year — usually 24 December and 2 November — change from year to year. Add them here when the
                proclamation comes out.
            </p>
        </x-card>
    </div>

    <x-modal wire="showForm" onClose="$set('showForm', false)" maxWidth="lg">
        <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">
            {{ $editingId ? 'Edit Holiday' : 'Add Holiday' }}
        </h2>

        <div class="mt-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-label>Date</x-label>
                    <x-input wire:model="date" type="date" />
                    @error('date') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-label>Whose holiday</x-label>
                    <x-select wire:model.live="observance">
                        @foreach (\App\Models\Holiday::OBSERVANCES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-select>
                    @error('observance') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <x-label>Holiday name</x-label>
                <x-input wire:model="name" type="text"
                         placeholder="{{ $observance === 'united_states' ? 'e.g. Thanksgiving Day' : ($observance === 'company' ? 'e.g. Company Anniversary' : 'e.g. Araw ng Kagitingan') }}" />
                @error('name') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Type</x-label>
                {{-- The options change with the calendar above. Only Philippine
                     holidays carry Labor Code categories. --}}
                <x-select wire:model="type">
                    @foreach (\App\Models\Holiday::typesFor($observance) as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-select>
                @error('type') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <x-label>Note <span class="font-medium text-[#778599]">(optional)</span></x-label>
                <x-input wire:model="note" type="text" placeholder="e.g. Proclamation No. 727" />
                <p class="mt-1 text-xs font-medium text-[#778599]">For your own reference. Payroll ignores it.</p>
                @error('note') <p class="mt-1 text-xs font-semibold text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="rounded-lg bg-[#f8fafc] p-3 text-xs font-medium text-[#778599] dark:bg-neutral-800/50">
                @if ($observance === 'philippines')
                    <span class="font-bold text-[#0f172a] dark:text-white">Regular</span> and
                    <span class="font-bold text-[#0f172a] dark:text-white">Special (Non-Working)</span> days keep an
                    employee's pay when they stay home.
                    <span class="font-bold text-[#0f172a] dark:text-white">Special (Working)</span> is the government
                    saying it is an ordinary day, so not turning up is still an absence.
                @else
                    <span class="font-bold text-[#0f172a] dark:text-white">Paid Day Off</span> keeps an employee's pay
                    when they stay home.
                    <span class="font-bold text-[#0f172a] dark:text-white">Special (Working)</span> puts the day on the
                    list for everyone to see while still expecting them in — useful for a client holiday your staff
                    work through.
                @endif
            </div>
        </div>

        <div class="mt-6 flex flex-wrap gap-2">
            <x-button wire:click="save">{{ $editingId ? 'Save Changes' : 'Add Holiday' }}</x-button>
            <x-button wire:click="$set('showForm', false)" @click="modalOpen = false" variant="secondary">Cancel</x-button>
        </div>
    </x-modal>
</div>
