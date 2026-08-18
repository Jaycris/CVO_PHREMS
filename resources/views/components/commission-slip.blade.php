@props(['slip'])

{{--
    The slip itself, shared by the agent's own page and HR's view of it, so the
    two can never drift apart.

    Every figure here came from the CRM. Nothing on this page is worked out
    locally — see App\Services\Crm\CommissionSlip. A field the CRM did not send
    shows a dash, never a zero.
--}}

@php
    $money = fn (?float $value, string $prefix = '') => $value === null
        ? '—'
        : $prefix . number_format($value, 2);

    $percent = fn (?float $value) => $value === null ? '—' : number_format($value, 2) . '%';

    $summary = [
        'Agent' => $slip->agentName ?: '—',
        'Team / Work Type' => trim(implode(' · ', array_filter([$slip->team, $slip->workType]))) ?: '—',
        'Month' => $slip->monthLabel(),
        'MTD' => $money($slip->mtd, '$'),
        'Target' => $money($slip->target, '$'),
        'MTD %' => $percent($slip->mtdPercent),
    ];

    $earnings = [
        ['Service commission', $money($slip->serviceCommission, '$')],
        ['Markup commission', $money($slip->markupCommission, '$')],
        ['USD total', $money($slip->usdTotal, '$')],
        ['Exchange rate', $slip->exchangeRate === null ? '—' : number_format($slip->exchangeRate, 4)],
        ['PHP total', $money($slip->phpTotal, '₱')],
    ];

    $holds = [
        ['Card payment hold', $percent($slip->cardHoldPercent)],
        ['Card payment hold amount', $money($slip->cardHoldAmount, '₱')],
    ];
@endphp

<div class="space-y-6">
    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">From the CRM</p>
            <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Commission Summary</h2>
        </div>

        <div class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($summary as $label => $value)
                <div class="rounded-xl border border-ink-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-ink-900/70">
                    <p class="text-xs font-bold uppercase tracking-wide text-ink-500 dark:text-ink-400">{{ $label }}</p>
                    <p class="mt-1 text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $value }}</p>
                </div>
            @endforeach
        </div>

        <div class="grid gap-0 border-t border-ink-200 md:grid-cols-2 md:divide-x md:divide-ink-200 dark:border-white/10 dark:md:divide-white/10">
            <div class="divide-y divide-ink-100 dark:divide-white/10">
                <p class="px-6 py-3 text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:text-ink-400">Earned</p>
                @foreach ($earnings as [$label, $value])
                    <div class="flex items-center justify-between gap-4 px-6 py-3">
                        <span class="text-sm font-medium text-ink-700 dark:text-ink-300">{{ $label }}</span>
                        <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $value }}</span>
                    </div>
                @endforeach
            </div>

            <div class="divide-y divide-ink-100 dark:divide-white/10">
                <p class="px-6 py-3 text-xs font-bold uppercase tracking-[0.14em] text-ink-500 dark:text-ink-400">Held back</p>
                @foreach ($holds as [$label, $value])
                    <div class="flex items-center justify-between gap-4 px-6 py-3">
                        <span class="text-sm font-medium text-ink-700 dark:text-ink-300">{{ $label }}</span>
                        <span class="text-sm font-semibold tabular-nums text-ink-900 dark:text-white">{{ $value }}</span>
                    </div>
                @endforeach
                <p class="px-6 py-3 text-xs font-medium text-ink-500 dark:text-ink-400">
                    A hold applies only to sales paid by card. The CRM decides which those are.
                </p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-ink-200 bg-brand-50 px-6 py-5 dark:border-white/10 dark:bg-brand-500/10">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700 dark:text-brand-300">Net Commission</p>
                <p class="mt-0.5 text-xs font-medium text-brand-800/80 dark:text-brand-200/80">What is payable after the card hold.</p>
            </div>
            <p class="text-3xl font-bold tabular-nums text-brand-900 dark:text-white">{{ $money($slip->netCommission, '₱') }}</p>
        </div>
    </x-card>

    <x-card :padding="false" class="overflow-hidden rounded-2xl">
        <div class="border-b border-ink-200 bg-ink-50 px-6 py-5 dark:border-white/10 dark:bg-white/5">
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-brand-700 dark:text-brand-300">From the CRM</p>
            <h2 class="mt-1 text-xl font-bold text-ink-950 dark:text-white">Transaction Statement</h2>
            <p class="mt-1 text-sm font-medium text-ink-500 dark:text-ink-400">
                Every commission record behind the figures above, for {{ $slip->monthLabel() }}.
            </p>
        </div>

        @unless ($slip->statementSupplied)
            {{-- Told apart from "no sales" deliberately: this one is a CRM that
                 does not send the rows yet, and saying "no transactions" here
                 would read as the agent having sold nothing. --}}
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-semibold text-ink-800 dark:text-white">The CRM did not send a statement for this month.</p>
                <p class="mx-auto mt-1 max-w-lg text-sm font-medium text-ink-500 dark:text-ink-400">
                    The summary above is complete. The per-sale rows need a <code class="font-mono text-xs">transactions</code>
                    array on the CRM's commission-slip response — see docs/crm-commission-api.md.
                </p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-ink-200 text-sm dark:divide-white/10">
                    <thead class="bg-ink-50 dark:bg-white/5">
                        <tr>
                            @foreach ([
                                'Sold', 'Brand', 'Author / Client', 'Book Title', 'Service', 'Payment',
                            ] as $heading)
                                <th class="whitespace-nowrap px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">{{ $heading }}</th>
                            @endforeach
                            @foreach ([
                                'Sale', 'Service Amt', 'Markup Amt', 'Service Comm', 'Markup Comm', 'USD Total', 'PHP Total', 'Card Hold', 'Net',
                            ] as $heading)
                                <th class="whitespace-nowrap px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-[#526783] dark:text-ink-300">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ink-100 dark:divide-white/10">
                        @forelse ($slip->transactions as $index => $row)
                            <tr wire:key="txn-{{ $index }}" class="transition hover:bg-ink-50 dark:hover:bg-white/5">
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-[#526783] dark:text-white">{{ $row->soldDate ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-[#64748b] dark:text-ink-400">{{ $row->brand ?: '—' }}</td>
                                <td class="px-4 py-3 font-medium text-[#64748b] dark:text-ink-400">{{ $row->client ?: '—' }}</td>
                                <td class="px-4 py-3 font-medium text-[#64748b] dark:text-ink-400">{{ $row->bookTitle ?: '—' }}</td>
                                <td class="px-4 py-3 font-medium text-[#64748b] dark:text-ink-400">{{ $row->service ?: '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-medium text-[#64748b] dark:text-ink-400">
                                    {{ $row->paymentMethod ?: '—' }}
                                    @if ($row->wasHeld())
                                        <x-badge color="amber" class="ml-1">held</x-badge>
                                    @endif
                                </td>
                                @foreach ([
                                    $money($row->saleAmount, '$'),
                                    $money($row->serviceAmount, '$'),
                                    $money($row->markupAmount, '$'),
                                    $money($row->serviceCommission, '$'),
                                    $money($row->markupCommission, '$'),
                                    $money($row->usdTotal, '$'),
                                    $money($row->phpTotal, '₱'),
                                    $money($row->cardHoldAmount, '₱'),
                                ] as $cell)
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-[#64748b] dark:text-ink-400">{{ $cell }}</td>
                                @endforeach
                                <td class="whitespace-nowrap px-4 py-3 text-right font-bold tabular-nums text-ink-950 dark:text-white">{{ $money($row->netCommission, '₱') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="15" class="px-4 py-10 text-center font-medium text-ink-500">
                                    No commission records in {{ $slip->monthLabel() }}.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endunless
    </x-card>
</div>
