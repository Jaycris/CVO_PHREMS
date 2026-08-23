@props(['slip'])

{{--
    The whole slip, from the stored snapshot: every summary figure and every
    transaction row. Shared by the agent's own page, HR's view inside a run, and
    nothing else — so the two can never show different things.

    A field the CRM did not send prints as a dash. Zero would claim the CRM
    worked out that nothing was owed, and this is a document someone is paid
    against.
--}}

@php
    $money = fn ($value, string $prefix = '') => $value === null ? '—' : $prefix . number_format((float) $value, 2);
    $percent = fn ($value) => $value === null ? '—' : number_format((float) $value, 2) . '%';

    $identity = [
        'Agent' => $slip->employeeName(),
        'Employee ID' => $slip->employeeCode() ?: '—',
        'Team / Work Type' => $slip->teamLabel(),
        'Month' => $slip->monthLabel(),
    ];

    $identity['Commission Scheme'] = $slip->commission_scheme ?: '—';

    $performance = [
        'MTD' => $money($slip->mtd, '$'),
        'Agent Target' => $money($slip->target, '$'),
        'MTD %' => $percent($slip->mtd_percent),
    ];

    // The rate bands as the CRM defined them when this slip was computed, and
    // which one the agent had reached. Shown, never applied — the CRM has
    // already worked out what is owed, and a second copy of the maths here
    // would eventually disagree with it.
    $rules = collect($slip->scheme_rules ?? [])
        ->filter(fn ($rule) => isset($rule['commission_percent']))
        ->sortBy('minimum_mtd_percent')
        ->values();

    // The band reached is simply the last one whose threshold has been passed,
    // which is why the list is sorted first.
    $reachedIndex = null;

    if ($slip->mtd_percent !== null) {
        foreach ($rules as $i => $rule) {
            if ((float) $slip->mtd_percent >= (float) ($rule['minimum_mtd_percent'] ?? 0)) {
                $reachedIndex = $i;
            }
        }
    }

    $earned = [
        ['Service commission', $money($slip->service_commission, '$')],
        ['Markup commission', $money($slip->markup_commission, '$')],
        ['USD total', $money($slip->usd_total, '$')],
    ];

    // The conversion is shown as the sum it is, rather than three rows the
    // reader has to multiply together in their head. It is the step most likely
    // to be queried, so it should be the one that needs no working out.
    $canConvert = $slip->usd_total !== null && $slip->exchange_rate !== null;

    $held = [
        ['Card payment hold', $percent($slip->card_hold_percent)],
        ['Card payment hold amount', $money($slip->card_hold_amount, '₱')],
    ];
@endphp

<div class="space-y-5">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($identity as $label => $value)
            <div class="rounded-xl border border-ink-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-ink-900/70">
                <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</p>
                <p class="mt-1 text-sm font-semibold text-ink-900 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($performance as $label => $value)
            <div class="rounded-xl bg-[#f8fafc] px-4 py-3 dark:bg-neutral-800/50">
                <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</p>
                <p class="mt-1 text-lg font-bold tabular-nums text-ink-900 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    @if ($rules->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-ink-200 dark:border-white/10">
            <div class="flex flex-wrap items-baseline justify-between gap-2 bg-ink-50 px-5 py-2.5 dark:bg-white/5">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:text-ink-400">
                    {{ $slip->commission_scheme ?: 'Commission scheme' }} &mdash; rates
                </p>
                <p class="text-xs font-medium text-ink-500 dark:text-ink-400">
                    As set in the CRM when this slip was computed.
                </p>
            </div>

            <div class="divide-y divide-ink-100 dark:divide-white/10">
                @foreach ($rules as $i => $rule)
                    @php $reached = $reachedIndex === $i; @endphp
                    <div class="flex items-center justify-between gap-4 px-5 py-2.5 {{ $reached ? 'bg-brand-50 dark:bg-brand-500/10' : '' }}">
                        <span class="text-sm font-medium {{ $reached ? 'text-brand-800 dark:text-brand-200' : 'text-ink-700 dark:text-ink-300' }}">
                            @if ((float) ($rule['minimum_mtd_percent'] ?? 0) <= 0)
                                Up to {{ number_format((float) ($rules[$i + 1]['minimum_mtd_percent'] ?? 100), 0) }}% of target
                            @else
                                From {{ number_format((float) $rule['minimum_mtd_percent'], 0) }}% of target
                            @endif
                            @if ($reached)
                                <span class="ml-1 text-xs font-bold uppercase tracking-wide">&larr; reached</span>
                            @endif
                        </span>
                        <span class="text-sm font-bold tabular-nums {{ $reached ? 'text-brand-800 dark:text-brand-200' : 'text-ink-900 dark:text-white' }}">
                            {{ number_format((float) $rule['commission_percent'], 2) }}%
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid gap-0 overflow-hidden rounded-xl border border-ink-200 md:grid-cols-2 md:divide-x md:divide-ink-200 dark:border-white/10 dark:md:divide-white/10">
        <div class="divide-y divide-ink-100 dark:divide-white/10">
            <p class="bg-ink-50 px-5 py-2.5 text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:bg-white/5 dark:text-ink-400">Earned in USD</p>
            @foreach ($earned as [$label, $value])
                <div class="flex items-center justify-between gap-4 px-5 py-2.5">
                    <span class="text-sm font-medium text-ink-700 dark:text-ink-300">{{ $label }}</span>
                    <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $value }}</span>
                </div>
            @endforeach
        </div>

        <div class="divide-y divide-ink-100 dark:divide-white/10">
            <p class="bg-ink-50 px-5 py-2.5 text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:bg-white/5 dark:text-ink-400">Held back</p>
            @foreach ($held as [$label, $value])
                <div class="flex items-center justify-between gap-4 px-5 py-2.5">
                    <span class="text-sm font-medium text-ink-700 dark:text-ink-300">{{ $label }}</span>
                    <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $value }}</span>
                </div>
            @endforeach
            <p class="px-5 py-2.5 text-xs font-medium text-ink-500 dark:text-ink-400">
                A hold applies only to sales paid by card. The CRM decides which those are.
            </p>
        </div>
    </div>

    {{-- The conversion, spelled out. Everything above it is dollars, everything
         below it is pesos, and this is where it changes. --}}
    <div class="overflow-hidden rounded-xl border border-ink-200 dark:border-white/10">
        <p class="bg-ink-50 px-5 py-2.5 text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:bg-white/5 dark:text-ink-400">
            Currency conversion
        </p>

        @if ($canConvert)
            <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-5 py-4 text-center">
                <span class="text-lg font-bold tabular-nums text-ink-900 dark:text-white">{{ $money($slip->usd_total, '$') }}</span>
                <span class="text-sm font-medium text-ink-500">USD</span>
                <span class="text-lg font-medium text-ink-400">×</span>
                <span class="text-lg font-bold tabular-nums text-ink-900 dark:text-white">{{ number_format((float) $slip->exchange_rate, 4) }}</span>
                <span class="text-lg font-medium text-ink-400">=</span>
                <span class="text-lg font-bold tabular-nums text-ink-900 dark:text-white">{{ $money($slip->php_total, '₱') }}</span>
                <span class="text-sm font-medium text-ink-500">PHP</span>
            </div>
            <p class="border-t border-ink-100 px-5 py-2.5 text-xs font-medium text-ink-500 dark:border-white/10 dark:text-ink-400">
                Rate set by the CRM for {{ $slip->monthLabel() }}. This app does not convert anything — it prints the
                rate and the result the CRM sent.
            </p>
        @else
            <div class="space-y-1 px-5 py-4">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm font-medium text-ink-700 dark:text-ink-300">USD total</span>
                    <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $money($slip->usd_total, '$') }}</span>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm font-medium text-ink-700 dark:text-ink-300">Exchange rate</span>
                    <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">
                        {{ $slip->exchange_rate === null ? '—' : number_format((float) $slip->exchange_rate, 4) }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-sm font-medium text-ink-700 dark:text-ink-300">PHP total</span>
                    <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $money($slip->php_total, '₱') }}</span>
                </div>
                <p class="pt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
                    The CRM did not send enough to show the conversion as a sum.
                </p>
            </div>
        @endif
    </div>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-brand-50 px-5 py-4 dark:bg-brand-500/10">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700 dark:text-brand-300">Net Commission</p>
            <p class="mt-0.5 text-xs font-medium text-brand-800/80 dark:text-brand-200/80">Payable after the card hold.</p>
        </div>
        <p class="text-3xl font-bold tabular-nums text-brand-900 dark:text-white">{{ $money($slip->net_commission, '₱') }}</p>
    </div>

    <div>
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h3 class="text-sm font-bold uppercase tracking-[0.14em] text-ink-500 dark:text-ink-400">Transaction Statement</h3>
            <span class="text-xs font-medium text-ink-500">{{ $slip->transaction_count }} record(s)</span>
        </div>

        @unless ($slip->statement_supplied)
            <div class="rounded-xl border border-dashed border-ink-300 px-4 py-8 text-center dark:border-white/15">
                <p class="text-sm font-semibold text-ink-800 dark:text-white">The CRM did not send a statement for this month.</p>
                <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">The summary above is complete.</p>
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-ink-200 dark:border-white/10">
                <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                    <thead class="bg-ink-50 dark:bg-white/5">
                        <tr>
                            @foreach (['Sold', 'Brand', 'Author / Client', 'Book Title', 'Service', 'Payment'] as $heading)
                                <th class="whitespace-nowrap px-3 py-2.5 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">{{ $heading }}</th>
                            @endforeach
                            @foreach (['Sale', 'Service Amt', 'Markup Amt', 'Service Comm', 'Markup Comm', 'USD', 'PHP', 'Card Hold', 'Net'] as $heading)
                                <th class="whitespace-nowrap px-3 py-2.5 text-right text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                        @forelse ($slip->lines as $row)
                            <tr wire:key="line-{{ $row->id }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-3 py-2.5 font-medium text-[#526783] dark:text-white">{{ $row->sold_date ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 font-medium text-[#64748b] dark:text-ink-400">{{ $row->brand ?: '—' }}</td>
                                <td class="px-3 py-2.5 font-medium text-[#64748b] dark:text-ink-400">{{ $row->client ?: '—' }}</td>
                                <td class="px-3 py-2.5 font-medium text-[#64748b] dark:text-ink-400">{{ $row->book_title ?: '—' }}</td>
                                <td class="px-3 py-2.5 font-medium text-[#64748b] dark:text-ink-400">{{ $row->service ?: '—' }}</td>
                                <td class="whitespace-nowrap px-3 py-2.5 font-medium text-[#64748b] dark:text-ink-400">
                                    {{ $row->payment_method ?: '—' }}
                                    @if ($row->wasHeld())
                                        <x-badge color="amber" class="ml-1">held</x-badge>
                                    @endif
                                </td>
                                @foreach ([
                                    $money($row->sale_amount, '$'),
                                    $money($row->service_amount, '$'),
                                    $money($row->markup_amount, '$'),
                                    $money($row->service_commission, '$'),
                                    $money($row->markup_commission, '$'),
                                    $money($row->usd_total, '$'),
                                    $money($row->php_total, '₱'),
                                    $money($row->card_hold_amount, '₱'),
                                ] as $cell)
                                    <td class="whitespace-nowrap px-3 py-2.5 text-right font-medium tabular-nums text-[#64748b] dark:text-ink-400">{{ $cell }}</td>
                                @endforeach
                                <td class="whitespace-nowrap px-3 py-2.5 text-right font-bold tabular-nums text-ink-950 dark:text-white">{{ $money($row->net_commission, '₱') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="15" class="px-3 py-8 text-center font-medium text-ink-500">
                                    No commission records in {{ $slip->monthLabel() }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endunless
    </div>
</div>
