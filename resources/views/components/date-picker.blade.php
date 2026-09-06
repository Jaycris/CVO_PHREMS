@props([
    'model',
    'align' => 'left',
])

@php
    $alignment = $align === 'right' ? 'right-0' : 'left-0';
    $months = [
        'January',
        'February',
        'March',
        'April',
        'May',
        'June',
        'July',
        'August',
        'September',
        'October',
        'November',
        'December',
    ];
    $currentYear = (int) date('Y');
@endphp

<div class="relative" x-data="datePicker($wire.entangle(@js($model)).live)" x-on:click.outside="open = false">
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex h-11 w-full items-center justify-between rounded-lg border border-ink-200 bg-white px-3.5 text-left text-sm font-semibold text-ink-700 shadow-sm transition hover:border-ink-300 hover:bg-ink-50 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-600/20 dark:border-white/10 dark:bg-ink-900 dark:text-white dark:hover:bg-white/5">
        <span x-text="display()"></span>
        <x-icon name="calendar" class="h-4 w-4 text-ink-400" />
    </button>

    <div x-cloak x-show="open" x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1 scale-[0.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100" x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0 scale-100" x-transition:leave-end="opacity-0 translate-y-1 scale-[0.98]" class="absolute {{ $alignment }} z-50 mt-2 w-[min(22rem,calc(100vw-3rem))] rounded-xl border border-ink-200 bg-white p-4 shadow-xl shadow-ink-950/10 dark:border-white/10 dark:bg-ink-900 dark:shadow-black/30">
        <div class="mb-4 flex items-center justify-between gap-2">
            <button type="button" x-on:click="previousMonth()" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-white/10" title="Previous month"><x-icon name="chevron-down" class="h-4 w-4 rotate-90" /></button>
            <div class="flex min-w-0 items-center justify-center gap-2">
                <label class="sr-only">Calendar month</label>
                <select x-model.number="month" aria-label="Calendar month" class="h-9 min-w-0 flex-1 rounded-lg border border-ink-200 bg-white px-2 text-sm font-bold text-ink-950 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-950 dark:text-white">
                    @foreach ($months as $monthIndex => $monthName)
                        <option value="{{ $monthIndex }}">{{ $monthName }}</option>
                    @endforeach
                </select>
                <label class="sr-only">Calendar year</label>
                <select x-model.number="year" aria-label="Calendar year" class="h-9 w-24 shrink-0 rounded-lg border border-ink-200 bg-white px-2 text-sm font-bold text-ink-950 focus:border-brand-500 focus:ring-brand-500 dark:border-white/10 dark:bg-ink-950 dark:text-white">
                    @foreach (range($currentYear + 20, $currentYear - 100) as $yearOption)
                        <option value="{{ $yearOption }}">{{ $yearOption }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" x-on:click="nextMonth()" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-500 transition hover:bg-ink-100 dark:text-ink-300 dark:hover:bg-white/10" title="Next month"><x-icon name="chevron-down" class="h-4 w-4 -rotate-90" /></button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-xs font-bold uppercase tracking-wide text-ink-400">
            <template x-for="dayName in dayNames" :key="dayName"><div class="py-1" x-text="dayName"></div></template>
        </div>
        <div class="mt-1 grid grid-cols-7 gap-1 text-center text-sm">
            <template x-for="blank in firstDay()" :key="'blank-' + blank"><div class="h-9"></div></template>
            <template x-for="day in daysInMonth()" :key="day">
                <button type="button" x-on:click="select(day)" x-text="day" x-bind:class="isSelected(day) ? 'bg-brand-700 text-white shadow-sm' : 'text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-white/10'" class="h-9 rounded-lg font-semibold transition"></button>
            </template>
        </div>
    </div>
</div>
