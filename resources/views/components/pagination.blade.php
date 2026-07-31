@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm font-medium text-ink-500 dark:text-ink-400">
            Showing
            <span class="font-bold text-ink-800 dark:text-white">{{ $paginator->firstItem() }}</span>
            to
            <span class="font-bold text-ink-800 dark:text-white">{{ $paginator->lastItem() }}</span>
            of
            <span class="font-bold text-ink-800 dark:text-white">{{ $paginator->total() }}</span>
            departments
        </p>

        <div class="flex flex-wrap items-center gap-2">
            @if ($paginator->onFirstPage())
                <span class="rounded-lg border border-ink-200 bg-ink-50 px-3 py-2 text-sm font-semibold text-ink-400 dark:border-white/10 dark:bg-white/5 dark:text-ink-500">Previous</span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10">
                    Previous
                </button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-2 text-sm font-semibold text-ink-400">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="rounded-lg bg-brand-700 px-3 py-2 text-sm font-bold text-white shadow-sm">{{ $page }}</span>
                        @else
                            <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10">
                                {{ $page }}
                            </button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="rounded-lg border border-ink-200 bg-white px-3 py-2 text-sm font-semibold text-ink-600 shadow-sm transition hover:bg-ink-50 dark:border-white/10 dark:bg-ink-900 dark:text-ink-300 dark:hover:bg-white/10">
                    Next
                </button>
            @else
                <span class="rounded-lg border border-ink-200 bg-ink-50 px-3 py-2 text-sm font-semibold text-ink-400 dark:border-white/10 dark:bg-white/5 dark:text-ink-500">Next</span>
            @endif
        </div>
    </nav>
@endif
