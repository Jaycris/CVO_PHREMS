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

    $performance = [
        'MTD' => $money($slip->mtd, '$'),
        'Target' => $money($slip->target, '$'),
        'MTD %' => $percent($slip->mtd_percent),
    ];

    $earned = [
        ['Service commission', $money($slip->service_commission, '$')],
        ['Markup commission', $money($slip->markup_commission, '$')],
        ['USD total', $money($slip->usd_total, '$')],
        ['Exchange rate', $slip->exchange_rate === null ? '—' : number_format((float) $slip->exchange_rate, 4)],
        ['PHP total', $money($slip->php_total, '₱')],
    ];

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

    <div class="grid gap-0 overflow-hidden rounded-xl border border-ink-200 md:grid-cols-2 md:divide-x md:divide-ink-200 dark:border-white/10 dark:md:divide-white/10">
        <div class="divide-y divide-ink-100 dark:divide-white/10">
            <p class="bg-ink-50 px-5 py-2.5 text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:bg-white/5 dark:text-ink-400">Earned</p>
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
