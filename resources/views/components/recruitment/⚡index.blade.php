<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Placeholder, deliberately routed and permissioned already.
 *
 * Standing it up now means the menu entry, the permission and the URL are
 * settled before anything is built on them — so when recruitment arrives it is
 * a page swap rather than a round of wiring, and anyone given the permission
 * today keeps it.
 */
new #[Layout('layouts.app')] class extends Component
{
    //
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-[#0f172a] dark:text-white">Recruitment</h1>
        <p class="text-sm font-medium text-[#778599] dark:text-neutral-400">Hiring, from an open role through to someone's first day.</p>
    </div>

    <x-card>
        <div class="py-12 text-center">
            <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
                <x-icon name="people-group" class="h-7 w-7" />
            </div>

            <h2 class="text-lg font-bold text-[#0f172a] dark:text-white">Coming soon</h2>
            <p class="mx-auto mt-2 max-w-md text-sm font-medium text-[#778599] dark:text-neutral-400">
                This is where hiring will live. Nothing here yet.
            </p>

            <div class="mx-auto mt-8 max-w-lg rounded-xl bg-[#f8fafc] p-5 text-left dark:bg-neutral-800/50">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-[#526783] dark:text-neutral-300">Planned</p>
                <ul class="mt-3 space-y-2">
                    @foreach ([
                        'Open positions, with the headcount each one is for',
                        'Applicants and where each one has got to',
                        'Interview scheduling and notes from the panel',
                        'Turning a hire into an employee record, without retyping anything',
                    ] as $item)
                        <li class="flex items-start gap-2.5 text-sm font-medium text-[#65758c] dark:text-neutral-300">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-[#778599]"></span>
                            {{ $item }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-xs font-medium text-[#778599]">
                    The last one is the point of building it here rather than in a spreadsheet — an accepted offer
                    should become an employee record and an onboarding invitation on its own.
                </p>
            </div>
        </div>
    </x-card>
</div>
